<?php
/**
 * CareerAct — AI Career Roadmap Service (Module 10 / Feature 6)
 * 
 * Generates and manages personalized, actionable career development roadmaps
 * connecting student career goals, skill-gap assessments, and verified extracurricular activities.
 * 
 * Database: carrearact2
 */

declare(strict_types=1);

require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/event_discovery_service.php';

class RoadmapService
{
    private PDO $db;
    private GeminiService $gemini;

    public function __construct(PDO $db, ?GeminiService $gemini = null)
    {
        $this->db = $db;
        $this->gemini = $gemini ?? new GeminiService();
    }

    /**
     * Retrieves the student's active primary career goal
     */
    public function getStudentCareerGoal(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                scg.career_id,
                scg.is_primary,
                c.title AS career_title,
                c.description AS career_desc,
                c.typical_level,
                cs.name AS subfield_name,
                cf.name AS field_name
            FROM student_career_goals scg
            JOIN careers c ON scg.career_id = c.id
            LEFT JOIN career_subfields cs ON c.subfield_id = cs.id
            LEFT JOIN career_fields cf ON cs.field_id = cf.id
            WHERE scg.user_id = :user_id
            ORDER BY scg.is_primary DESC, scg.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retrieves student roadmap milestones grouped by phase
     */
    public function getRoadmap(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                srm.*,
                a.title AS activity_title,
                a.delivery_mode,
                ac.name AS category_name,
                ac.code AS category_code
            FROM student_roadmap_milestones srm
            LEFT JOIN activities a ON srm.recommended_activity_id = a.id
            LEFT JOIN activity_categories ac ON a.category_id = ac.id
            WHERE srm.user_id = :user_id
            ORDER BY srm.phase_number ASC, 
                     FIELD(srm.priority_level, 'critical', 'high', 'medium', 'advanced'), 
                     srm.milestone_id ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $phases = [];
        $completedCount = 0;
        $totalCount = count($milestones);

        foreach ($milestones as $m) {
            $pNum = (int)$m['phase_number'];
            if (!isset($phases[$pNum])) {
                $phases[$pNum] = [
                    'phase_number' => $pNum,
                    'phase_title'  => $m['phase_title'],
                    'items'        => []
                ];
            }
            $phases[$pNum]['items'][] = $m;
            if ((int)$m['is_completed'] === 1) {
                $completedCount++;
            }
        }

        $progressPct = $totalCount > 0 ? (int)round(($completedCount / $totalCount) * 100) : 0;

        return [
            'total_milestones'     => $totalCount,
            'completed_milestones' => $completedCount,
            'progress_pct'         => $progressPct,
            'phases'               => array_values($phases),
            'milestones'           => $milestones
        ];
    }

    /**
     * Generates or regenerates a personalized career roadmap for the student
     * Preserves already completed milestones across regenerations.
     */
    public function generateRoadmap(int $userId, bool $regenerate = false): array
    {
        $goal = $this->getStudentCareerGoal($userId);
        if (!$goal) {
            return ['success' => false, 'error' => 'No active career goal selected. Please set your career goal first.'];
        }

        $careerId = (int)$goal['career_id'];
        $careerTitle = (string)$goal['career_title'];

        // Check if existing milestones exist
        $existing = $this->getRoadmap($userId);
        if (!empty($existing['milestones']) && !$regenerate) {
            return ['success' => true, 'roadmap' => $existing, 'from_cache' => true];
        }

        // Cache completed milestones to preserve student progress
        $completedCompetencies = [];
        if (!empty($existing['milestones'])) {
            foreach ($existing['milestones'] as $m) {
                if ((int)$m['is_completed'] === 1) {
                    $completedCompetencies[strtolower(trim($m['competency_title']))] = $m['completed_at'] ?? date('Y-m-d H:i:s');
                }
            }
        }

        // 1. Fetch student's current claimed skills
        $stmtSkills = $this->db->prepare("
            SELECT s.id, s.name 
            FROM student_skills ss
            JOIN skills s ON ss.skill_id = s.id
            WHERE ss.user_id = :user_id
        ");
        $stmtSkills->execute([':user_id' => $userId]);
        $currentSkills = $stmtSkills->fetchAll(PDO::FETCH_ASSOC);
        $skillNames = array_column($currentSkills, 'name');

        // 2. Fetch assessed skill gaps
        $stmtGaps = $this->db->prepare("
            SELECT s.id, s.name, ga.overall_score, ga.gap_status
            FROM ai_skill_gap_analyses ga
            JOIN skills s ON ga.skill_id = s.id
            WHERE ga.user_id = :user_id
            ORDER BY ga.id DESC
        ");
        $stmtGaps->execute([':user_id' => $userId]);
        $skillGaps = $stmtGaps->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch completed student achievements
        $stmtAch = $this->db->prepare("
            SELECT a.title, e.activity_id, a.result, a.completed_on
            FROM achievements a
            LEFT JOIN enrollments e ON a.enrollment_id = e.id
            WHERE a.user_id = :user_id
        ");
        $stmtAch->execute([':user_id' => $userId]);
        $completedActivities = $stmtAch->fetchAll(PDO::FETCH_ASSOC);

        // 4. Fetch available verified extracurricular activities
        $stmtActs = $this->db->prepare("
            SELECT 
                a.id, a.title, a.description, a.delivery_mode, 
                ac.name AS category_name, ac.code AS category_code
            FROM activities a
            JOIN activity_categories ac ON a.category_id = ac.id
            WHERE a.is_active = 1
            ORDER BY a.id ASC
            LIMIT 25
        ");
        $stmtActs->execute();
        $availableActivities = $stmtActs->fetchAll(PDO::FETCH_ASSOC);

        // 5. Generate roadmap phases via Gemini or Domain Heuristic
        $phasesData = $this->synthesizeRoadmapMilestones(
            $careerTitle,
            $skillNames,
            $skillGaps,
            $completedActivities,
            $availableActivities
        );

        // 6. Persist to student_roadmap_milestones
        $this->db->beginTransaction();
        try {
            // Delete old non-completed or all old milestones for this career
            $stmtDel = $this->db->prepare("DELETE FROM student_roadmap_milestones WHERE user_id = :user_id");
            $stmtDel->execute([':user_id' => $userId]);

            $stmtIns = $this->db->prepare("
                INSERT INTO student_roadmap_milestones (
                    user_id, career_id, phase_number, phase_title, competency_title,
                    reason_text, suggested_action, recommended_activity_id,
                    priority_level, target_period, expected_outcome, is_completed, completed_at
                ) VALUES (
                    :user_id, :career_id, :phase_num, :phase_title, :competency,
                    :reason, :action, :act_id,
                    :priority, :period, :outcome, :is_done, :done_at
                )
            ");

            foreach ($phasesData as $phase) {
                $pNum = (int)$phase['phase_number'];
                $pTitle = (string)$phase['phase_title'];

                foreach ($phase['items'] as $item) {
                    $compKey = strtolower(trim($item['competency_title']));
                    $wasDone = isset($completedCompetencies[$compKey]);
                    $doneAt = $wasDone ? $completedCompetencies[$compKey] : null;

                    // Match activity ID if specified or by category
                    $matchedActId = !empty($item['activity_id']) ? (int)$item['activity_id'] : null;
                    if (!$matchedActId && !empty($availableActivities)) {
                        foreach ($availableActivities as $act) {
                            if (!empty($item['category_hint']) && stripos($act['category_code'], $item['category_hint']) !== false) {
                                $matchedActId = (int)$act['id'];
                                break;
                            }
                        }
                    }

                    $stmtIns->execute([
                        ':user_id'     => $userId,
                        ':career_id'   => $careerId,
                        ':phase_num'   => $pNum,
                        ':phase_title' => $pTitle,
                        ':competency'  => $item['competency_title'],
                        ':reason'      => $item['reason_text'],
                        ':action'      => $item['suggested_action'],
                        ':act_id'      => $matchedActId,
                        ':priority'    => $item['priority_level'] ?? 'high',
                        ':period'      => $item['target_period'] ?? 'Month 1-2',
                        ':outcome'     => $item['expected_outcome'] ?? 'Demonstrable competency verification',
                        ':is_done'     => $wasDone ? 1 : 0,
                        ':done_at'     => $doneAt
                    ]);
                }
            }

            $this->db->commit();
            return ['success' => true, 'roadmap' => $this->getRoadmap($userId), 'regenerated' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Database error generating roadmap: ' . $e->getMessage()];
        }
    }

    /**
     * Toggles a roadmap milestone's completion status
     */
    public function toggleMilestone(int $userId, int $milestoneId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE student_roadmap_milestones 
            SET is_completed = IF(is_completed = 1, 0, 1),
                completed_at = IF(is_completed = 1, NULL, NOW())
            WHERE milestone_id = :mid AND user_id = :uid
        ");
        return $stmt->execute([':mid' => $milestoneId, ':uid' => $userId]);
    }

    /**
     * Auto-completes milestone related to an activity when the student completes it
     */
    public function autoUpdateRoadmapOnActivityCompletion(int $userId, int $activityId): int
    {
        $stmt = $this->db->prepare("
            UPDATE student_roadmap_milestones
            SET is_completed = 1, completed_at = NOW()
            WHERE user_id = :uid 
              AND recommended_activity_id = :aid 
              AND is_completed = 0
        ");
        $stmt->execute([':uid' => $userId, ':aid' => $activityId]);
        return $stmt->rowCount();
    }

    /**
     * Synthesizes tailored milestones using Gemini AI or domain heuristics
     */
    private function synthesizeRoadmapMilestones(
        string $careerTitle,
        array $currentSkills,
        array $skillGaps,
        array $completedActivities,
        array $availableActivities
    ): array {
        if ($this->gemini->isConfigured()) {
            try {
                $sys = "You are a Chief Career Development Architect for CareerAct.
Generate a structured, 4-stage personalized career roadmap connecting extracurricular activities and real-world milestones for a student targeting: '{$careerTitle}'.
Respond strictly with valid JSON. No markdown fences outside the JSON.";

                $prompt = json_encode([
                    'target_career'       => $careerTitle,
                    'current_skills'      => array_slice($currentSkills, 0, 8),
                    'skill_gaps'          => array_slice($skillGaps, 0, 4),
                    'completed_activity_count' => count($completedActivities),
                    'instructions'        => "Generate 4 developmental stages:
1. Stage 1: Strengthen Core Fundamentals & Technical Foundations
2. Stage 2: Develop Teamwork & Practical Competitive Skills (Hackathons, Coding Sprints)
3. Stage 3: Build a Professional Portfolio & Applied Projects
4. Stage 4: Improve Career Readiness, Leadership & Industry Mastery

Return format:
{
  \"phases\": [
    {
      \"phase_number\": 1,
      \"phase_title\": \"Stage 1: Strengthen Programming & Core Fundamentals\",
      \"items\": [
        {
          \"competency_title\": \"Algorithmic Problem Solving & Data Structures\",
          \"suggested_action\": \"Participate in competitive programming challenges or algorithm workshops\",
          \"reason_text\": \"Core foundation for technical interviews and coding agility\",
          \"target_period\": \"Month 1 - 2\",
          \"expected_outcome\": \"Demonstrated ability to solve medium-difficulty algorithmic problems under time constraints\",
          \"priority_level\": \"critical\",
          \"category_hint\": \"competition\"
        }
      ]
    }
  ]
}"
                ], JSON_UNESCAPED_SLASHES);

                $raw = $this->gemini->callGeminiApi($sys, $prompt, 20);
                $parsed = $this->gemini->extractAndValidateJson($raw);
                if (!empty($parsed['phases']) && is_array($parsed['phases'])) {
                    return $parsed['phases'];
                }
            } catch (Exception $e) {
                // Fall back gracefully to curated domain heuristics
            }
        }

        // Curated Heuristic Roadmap for Career Pathway
        return $this->getCuratedRoadmapHeuristics($careerTitle, $availableActivities);
    }

    /**
     * High-quality verified fallback roadmap aligned with the user's career pathway
     */
    private function getCuratedRoadmapHeuristics(string $careerTitle, array $availableActivities): array
    {
        $hackathonActId = null;
        $compActId = null;
        $workshopActId = null;
        $projectActId = null;

        foreach ($availableActivities as $act) {
            $cat = strtolower((string)$act['category_code']);
            if (!$hackathonActId && strpos($cat, 'hackathon') !== false) {
                $hackathonActId = (int)$act['id'];
            } elseif (!$compActId && strpos($cat, 'competition') !== false) {
                $compActId = (int)$act['id'];
            } elseif (!$workshopActId && (strpos($cat, 'workshop') !== false || strpos($cat, 'course') !== false)) {
                $workshopActId = (int)$act['id'];
            } elseif (!$projectActId && (strpos($cat, 'project') !== false || strpos($cat, 'research') !== false)) {
                $projectActId = (int)$act['id'];
            }
        }

        return [
            [
                'phase_number' => 1,
                'phase_title'  => "Stage 1: Strengthen {$careerTitle} Fundamentals",
                'items' => [
                    [
                        'competency_title' => 'Core Technical & Analytical Foundations',
                        'suggested_action' => 'Participate in structured technical workshops and solve daily foundational challenges.',
                        'reason_text'      => "Essential baseline competencies required across all junior {$careerTitle} positions.",
                        'target_period'    => 'Month 1 - 2',
                        'expected_outcome' => 'Solid understanding of core domain principles and clean implementation workflows.',
                        'priority_level'   => 'critical',
                        'activity_id'      => $workshopActId,
                        'category_hint'    => 'workshop'
                    ],
                    [
                        'competency_title' => 'Tactical Problem Solving & Debugging',
                        'suggested_action' => 'Enroll in algorithm or diagnostic coding sprint competitions.',
                        'reason_text'      => 'Builds speed, edge-case vigilance, and structured trouble-shooting habits.',
                        'target_period'    => 'Month 2 - 3',
                        'expected_outcome' => 'Ability to diagnose performance bottlenecks and deliver reliable algorithmic solutions.',
                        'priority_level'   => 'high',
                        'activity_id'      => $compActId,
                        'category_hint'    => 'competition'
                    ]
                ]
            ],
            [
                'phase_number' => 2,
                'phase_title'  => 'Stage 2: Develop Teamwork & Practical Competitive Skills',
                'items' => [
                    [
                        'competency_title' => 'Collaborative Rapid Prototyping (Hackathons)',
                        'suggested_action' => 'Form a multidisciplinary team and compete in a 24-48hr hackathon sprint.',
                        'reason_text'      => 'Challenges you to handle tight deadlines, team communication, and practical MVP delivery.',
                        'target_period'    => 'Month 3 - 4',
                        'expected_outcome' => 'Functional software or analytical prototype deployed live with verifiable team credits.',
                        'priority_level'   => 'critical',
                        'activity_id'      => $hackathonActId,
                        'category_hint'    => 'hackathon'
                    ],
                    [
                        'competency_title' => 'Stakeholder Communication & Technical Pitching',
                        'suggested_action' => 'Present technical solutions in department symposiums, hackathon demos, or poster sessions.',
                        'reason_text'      => 'Converts raw technical capability into persuasive professional communication.',
                        'target_period'    => 'Month 4 - 5',
                        'expected_outcome' => 'Confident articulation of technical trade-offs, architecture decisions, and business impact.',
                        'priority_level'   => 'medium',
                        'activity_id'      => $projectActId,
                        'category_hint'    => 'project'
                    ]
                ]
            ],
            [
                'phase_number' => 3,
                'phase_title'  => 'Stage 3: Build a Professional Portfolio & Artifacts',
                'items' => [
                    [
                        'competency_title' => 'End-to-End Production Project Showcase',
                        'suggested_action' => 'Design, build, and document an open-source tool, complete web system, or research paper.',
                        'reason_text'      => 'Provides undeniable evidence of craftsmanship that recruiters and hiring managers examine.',
                        'target_period'    => 'Month 5 - 6',
                        'expected_outcome' => 'Comprehensive GitHub repository with README, unit test coverage, and clear user documentation.',
                        'priority_level'   => 'high',
                        'activity_id'      => $projectActId,
                        'category_hint'    => 'project'
                    ],
                    [
                        'competency_title' => 'Continuous Integration & Quality Assurance',
                        'suggested_action' => 'Implement automated testing, code linting, and continuous deployment workflows.',
                        'reason_text'      => 'Differentiates amateur code from production-ready industry software.',
                        'target_period'    => 'Month 6 - 7',
                        'expected_outcome' => 'Automated deployment pipeline and reproducible testing suite in your project.',
                        'priority_level'   => 'medium',
                        'activity_id'      => $workshopActId,
                        'category_hint'    => 'workshop'
                    ]
                ]
            ],
            [
                'phase_number' => 4,
                'phase_title'  => "Stage 4: Career Readiness & {$careerTitle} Mastery",
                'items' => [
                    [
                        'competency_title' => 'Technical Interview & System Design Readiness',
                        'suggested_action' => 'Conduct peer mock technical interviews, system design reviews, and behavioral evaluations.',
                        'reason_text'      => 'Prepares you for rigorous technical screenings and on-the-spot vocational questions.',
                        'target_period'    => 'Month 7 - 8',
                        'expected_outcome' => 'Refined situational response delivery and structured system architecture whiteboard skills.',
                        'priority_level'   => 'critical',
                        'activity_id'      => $compActId,
                        'category_hint'    => 'competition'
                    ],
                    [
                        'competency_title' => 'Mentorship, Community Leadership & Networking',
                        'suggested_action' => 'Mentor junior students, organize campus tech talks, or contribute to open-source communities.',
                        'reason_text'      => 'Establishes personal leadership credibility, professional network, and mentorship qualities.',
                        'target_period'    => 'Ongoing',
                        'expected_outcome' => 'Recognized community contribution and strong recommendations on your portfolio.',
                        'priority_level'   => 'advanced',
                        'activity_id'      => $hackathonActId,
                        'category_hint'    => 'leadership'
                    ]
                ]
            ]
        ];
    }
}

