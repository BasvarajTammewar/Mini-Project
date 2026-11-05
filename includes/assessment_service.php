<?php
/**
 * CareerAct — Assessment Service
 * 
 * Manages skill identification, assessment persistence, historical tracking,
 * rubric scoring, and activity recommendations from carrearact2.
 */

declare(strict_types=1);

class AssessmentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieves the strict list of assessable skills explicitly claimed by the authenticated student
     * in student_skills or linked to their verified past activities in student_existing_activities.
     *
     * EXPLICITLY DOES NOT infer skills from career goals, future recommendations, or unverified profile fields.
     */
    public function getStrictAllowedSkills(int $userId): array
    {
        // A. Skills explicitly entered by student in student_skills
        $stmtClaimed = $this->db->prepare("
            SELECT 
                ss.skill_id,
                s.name AS skill_name,
                s.description,
                s.category_id,
                COALESCE(sc.name, 'General') AS category_name,
                ss.level AS current_level,
                ss.source AS skill_source,
                'student_skills' AS origin
            FROM student_skills ss
            JOIN skills s ON ss.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE ss.user_id = :user_id AND s.is_active = 1
            ORDER BY s.name ASC
        ");
        $stmtClaimed->execute([':user_id' => $userId]);
        $claimed = $stmtClaimed->fetchAll(PDO::FETCH_ASSOC);

        // B. Skills linked to student's existing activities in student_existing_activities
        $stmtActSkills = $this->db->prepare("
            SELECT DISTINCT
                ask.skill_id,
                s.name AS skill_name,
                s.description,
                s.category_id,
                COALESCE(sc.name, 'General') AS category_name,
                ask.gain_level AS current_level,
                'self_reported' AS skill_source,
                'student_existing_activities' AS origin
            FROM student_existing_activities sea
            JOIN activity_skills ask ON sea.activity_id = ask.activity_id
            JOIN skills s ON ask.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE sea.user_id = :user_id AND s.is_active = 1
            ORDER BY s.name ASC
        ");
        $stmtActSkills->execute([':user_id' => $userId]);
        $actSkills = $stmtActSkills->fetchAll(PDO::FETCH_ASSOC);

        // Combine and index by skill_id
        $allowed = [];
        foreach ($claimed as $c) {
            $allowed[(int)$c['skill_id']] = $c;
        }
        foreach ($actSkills as $a) {
            $sId = (int)$a['skill_id'];
            if (!isset($allowed[$sId])) {
                $allowed[$sId] = $a;
            }
        }

        return $allowed;
    }

    /**
     * Validates whether a specific skill is eligible to be assessed for the student
     */
    public function isSkillEligibleForAssessment(int $userId, int $skillId): bool
    {
        $allowed = $this->getStrictAllowedSkills($userId);
        return isset($allowed[$skillId]);
    }

    /**
     * Retrieves the student's existing activities to provide realistic context for question generation
     */
    public function getStudentExistingActivitiesContext(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                sea.id,
                sea.title,
                sea.role,
                sea.description,
                sea.start_date,
                sea.end_date,
                a.title AS catalog_title
            FROM student_existing_activities sea
            LEFT JOIN activities a ON sea.activity_id = a.id
            WHERE sea.user_id = :user_id
            ORDER BY sea.start_date DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Identifies all assessable skills for a student, categorized by Soft, Technical, and Professional
     */
    public function getStudentAssessableSkills(int $userId): array
    {
        // 1. Strict allowed skills claimed by the student
        $allowedSkills = $this->getStrictAllowedSkills($userId);

        // 2. Career required skills from student's active primary goal (context only, NOT assessable unless in allowed)
        $stmtCareerSkills = $this->db->prepare("
            SELECT cs.skill_id, cs.required_level, cs.importance, cg.title AS career_title
            FROM student_career_goals scg
            JOIN career_skills cs ON scg.career_id = cs.career_id
            JOIN careers cg ON scg.career_id = cg.id
            WHERE scg.user_id = :user_id AND scg.is_primary = 1
        ");
        $stmtCareerSkills->execute([':user_id' => $userId]);
        $careerSkills = [];
        $activeCareerTitle = '';
        while ($row = $stmtCareerSkills->fetch()) {
            $careerSkills[(int)$row['skill_id']] = $row;
            if (empty($activeCareerTitle)) {
                $activeCareerTitle = $row['career_title'];
            }
        }

        // 3. Latest AI assessment history per skill
        $stmtAssess = $this->db->prepare("
            SELECT 
                a.id AS assessment_id,
                a.skill_id,
                a.overall_score,
                a.demonstration_level,
                a.status,
                a.completed_at,
                a.attempt_number
            FROM ai_skill_assessments a
            INNER JOIN (
                SELECT skill_id, MAX(id) AS max_id
                FROM ai_skill_assessments
                WHERE user_id = :user_id AND status = 'completed'
                GROUP BY skill_id
            ) latest ON a.id = latest.max_id
        ");
        $stmtAssess->execute([':user_id' => $userId]);
        $latestAssessments = [];
        while ($row = $stmtAssess->fetch()) {
            $latestAssessments[(int)$row['skill_id']] = $row;
        }

        // 4. Total attempts count per skill
        $stmtAttempts = $this->db->prepare("
            SELECT skill_id, COUNT(*) AS attempts_count
            FROM ai_skill_assessments
            WHERE user_id = :user_id AND status = 'completed'
            GROUP BY skill_id
        ");
        $stmtAttempts->execute([':user_id' => $userId]);
        $attemptsCounts = $stmtAttempts->fetchAll(PDO::FETCH_KEY_PAIR);

        // Categorize each assessable skill into standard clusters: Soft, Technical, Professional
        $categorized = [
            'soft'         => [],
            'technical'    => [],
            'professional' => [],
            'other'        => []
        ];

        $summary = [
            'total_skills'          => count($allowedSkills),
            'student_skills_count'  => count($allowedSkills),
            'assessed_skills_count' => 0,
            'pending_skills_count'  => 0,
            'average_score'         => 0.0,
            'career_title'          => $activeCareerTitle,
            'has_assessable_skills' => !empty($allowedSkills),
            'allowed_skill_ids'     => array_keys($allowedSkills)
        ];

        $totalScoreSum = 0;
        $scoredCount = 0;

        foreach ($allowedSkills as $sId => $sk) {
            $name = $sk['skill_name'];
            $catName = $sk['category_name'];

            $isCareerReq = isset($careerSkills[$sId]);
            $latest = $latestAssessments[$sId] ?? null;
            $hasAssessed = ($latest !== null);
            $score = $hasAssessed ? (float)$latest['overall_score'] : null;

            if ($hasAssessed && $score !== null) {
                $totalScoreSum += $score;
                $scoredCount++;
            }

            // Cluster determination
            $cluster = $this->determineSkillCluster($name, $catName);

            $item = [
                'skill_id'             => $sId,
                'skill_name'           => $name,
                'description'          => $sk['description'] ?? '',
                'category_name'        => $catName,
                'cluster'              => $cluster,
                'is_my_skill'          => true,
                'current_level'        => (int)($sk['current_level'] ?? 1),
                'skill_source'         => $sk['skill_source'] ?? 'self_reported',
                'is_career_required'   => $isCareerReq,
                'career_required_level'=> $isCareerReq ? (int)$careerSkills[$sId]['required_level'] : 0,
                'is_activity_linked'   => ($sk['origin'] === 'student_existing_activities'),
                'is_assessed'          => $hasAssessed,
                'assessment_id'        => $hasAssessed ? (int)$latest['assessment_id'] : null,
                'score'                => $score,
                'demonstration_level'  => $hasAssessed ? $latest['demonstration_level'] : 'Pending Assessment',
                'completed_at'         => $hasAssessed ? $latest['completed_at'] : null,
                'attempts_count'       => (int)($attemptsCounts[$sId] ?? 0)
            ];

            $categorized[$cluster][] = $item;
        }

        $summary['assessed_skills_count'] = $scoredCount;
        $summary['pending_skills_count']  = max(0, count($allowedSkills) - $scoredCount);

        if ($scoredCount > 0) {
            $summary['average_score'] = round($totalScoreSum / $scoredCount, 1);
        }

        return [
            'summary'     => $summary,
            'categorized' => $categorized,
            'all'         => array_values($allowedSkills)
        ];
    }

    /**
     * Determines whether a skill is Soft, Technical, or Professional
     */
    public function determineSkillCluster(string $skillName, string $categoryName): string
    {
        $text = strtolower($skillName . ' ' . $categoryName);

        // Technical skills
        if (preg_match('/(programming|code|coding|software|python|java|javascript|php|web|database|sql|networking|cybersecurity|data analysis|debugging|structures|algorithm|html|css|devops|cloud|machine learning|api|git)/i', $text)) {
            return 'technical';
        }

        // Soft skills
        if (preg_match('/(leadership|communication|teamwork|team|public speaking|presentation|problem solving|critical thinking|adaptability|conflict|creativity|emotional|collaboration|decision making|interpersonal|active listening|empathy)/i', $text)) {
            return 'soft';
        }

        // Professional skills
        if (preg_match('/(project management|planning|organization|time management|research|entrepreneurship|negotiation|strategic|business|agile|scrum|budget|governance|consulting|marketing)/i', $text)) {
            return 'professional';
        }

        return 'other';
    }

    /**
     * Starts a new assessment session and persists generated questions along with a snapshot of assessed input
     */
    public function createAssessmentSession(int $userId, int $skillId, array $questions, array $snapshotContext = []): int
    {
        // Enforce strict eligibility: Skill MUST be explicitly claimed or activity-linked
        if (!$this->isSkillEligibleForAssessment($userId, $skillId)) {
            throw new InvalidArgumentException("Skill ID {$skillId} is not in the student's claimed skills or verified activities.");
        }

        // Find existing attempts count for this skill
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) FROM ai_skill_assessments 
            WHERE user_id = :user_id AND skill_id = :skill_id
        ");
        $stmtCount->execute([':user_id' => $userId, ':skill_id' => $skillId]);
        $attemptNum = ((int)$stmtCount->fetchColumn()) + 1;

        // Build immutable snapshot of the student's input at the time of assessment
        $allowedSkills = $this->getStrictAllowedSkills($userId);
        $skillInfo = $allowedSkills[$skillId] ?? ['skill_name' => 'Skill ' . $skillId, 'category_name' => 'General'];
        $activities = $this->getStudentExistingActivitiesContext($userId);

        $snapshot = [
            'assessed_skill_id'     => $skillId,
            'assessed_skill_name'   => $skillInfo['skill_name'] ?? '',
            'assessed_category'     => $skillInfo['category_name'] ?? '',
            'claimed_level'         => $skillInfo['current_level'] ?? 1,
            'skill_source'          => $skillInfo['skill_source'] ?? 'self_reported',
            'existing_activities'   => $activities,
            'snapshot_timestamp'    => date('Y-m-d H:i:s'),
            'extra_context'         => $snapshotContext
        ];

        $rubricVersion = '1.0';
        $modelName = 'gemini-3.5-flash-lite';

        $this->db->beginTransaction();
        try {
            $stmtInsert = $this->db->prepare("
                INSERT INTO ai_skill_assessments 
                    (user_id, skill_id, status, attempt_number, rubric_version, model_name, snapshot_input_json, created_at)
                VALUES 
                    (:user_id, :skill_id, 'in_progress', :attempt_number, :rubric_version, :model_name, :snapshot_input_json, NOW())
            ");
            $stmtInsert->execute([
                ':user_id'             => $userId,
                ':skill_id'            => $skillId,
                ':attempt_number'      => $attemptNum,
                ':rubric_version'      => $rubricVersion,
                ':model_name'          => $modelName,
                ':snapshot_input_json' => json_encode($snapshot)
            ]);
            $assessmentId = (int)$this->db->lastInsertId();

            $stmtQ = $this->db->prepare("
                INSERT INTO ai_assessment_questions 
                    (assessment_id, question_index, scenario_text, evaluation_criteria, question_fingerprint, scenario_category, difficulty_level, created_at)
                VALUES 
                    (:assessment_id, :question_index, :scenario_text, :evaluation_criteria, :question_fingerprint, :scenario_category, :difficulty_level, NOW())
            ");

            foreach ($questions as $q) {
                $scen = (string)($q['scenario'] ?? ($q['scenario_text'] ?? ''));
                $criteria = (string)($q['evaluation_focus'] ?? ($q['evaluation_criteria'] ?? 'Practical Reasoning'));
                $cat = (string)($q['scenario_category'] ?? ($snapshot['assessed_category'] ?? 'General'));
                $diff = (string)($q['difficulty_level'] ?? 'intermediate');
                $fp = $this->computeQuestionFingerprint($scen);

                $stmtQ->execute([
                    ':assessment_id'       => $assessmentId,
                    ':question_index'      => (int)($q['index'] ?? ($q['question_index'] ?? 1)),
                    ':scenario_text'       => $scen,
                    ':evaluation_criteria' => $criteria,
                    ':question_fingerprint'=> $fp,
                    ':scenario_category'   => $cat,
                    ':difficulty_level'    => $diff
                ]);
            }

            $this->db->commit();
            return $assessmentId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Loads an assessment session with its questions
     */
    public function getAssessmentSession(int $assessmentId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                s.name AS skill_name,
                COALESCE(sc.name, 'General') AS category_name
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE a.id = :id AND a.user_id = :user_id
        ");
        $stmt->execute([':id' => $assessmentId, ':user_id' => $userId]);
        $assessment = $stmt->fetch();
        if (!$assessment) {
            return null;
        }

        $stmtQ = $this->db->prepare("
            SELECT * FROM ai_assessment_questions
            WHERE assessment_id = :assessment_id
            ORDER BY question_index ASC
        ");
        $stmtQ->execute([':assessment_id' => $assessmentId]);
        $assessment['questions'] = $stmtQ->fetchAll();

        // If completed, fetch answers
        if ($assessment['status'] === 'completed') {
            $stmtA = $this->db->prepare("
                SELECT * FROM ai_assessment_answers
                WHERE assessment_id = :assessment_id
                ORDER BY id ASC
            ");
            $stmtA->execute([':assessment_id' => $assessmentId]);
            $assessment['answers'] = $stmtA->fetchAll();
        }

        return $assessment;
    }

    /**
     * Transactionally saves evaluation results and updates student competencies
     */
    public function finalizeAssessmentEvaluation(int $assessmentId, int $userId, array $questionAnswers, array $evalResult): bool
    {
        $this->db->beginTransaction();
        try {
            // 1. Fetch questions to match question_id
            $stmtQ = $this->db->prepare("
                SELECT id, question_index FROM ai_assessment_questions
                WHERE assessment_id = :assessment_id
                ORDER BY question_index ASC
            ");
            $stmtQ->execute([':assessment_id' => $assessmentId]);
            $qRows = $stmtQ->fetchAll();
            $qMap = [];
            foreach ($qRows as $qr) {
                $qMap[(int)$qr['question_index']] = (int)$qr['id'];
            }

            // 2. Insert answers and individual scores
            $stmtAns = $this->db->prepare("
                INSERT INTO ai_assessment_answers 
                    (assessment_id, question_id, answer_text, score, feedback, rubric_breakdown_json, created_at)
                VALUES 
                    (:assessment_id, :question_id, :answer_text, :score, :feedback, :rubric_breakdown_json, NOW())
            ");

            $qEvals = $evalResult['question_evaluations'] ?? [];
            $qEvalMap = [];
            foreach ($qEvals as $qe) {
                $qEvalMap[(int)$qe['question_index']] = $qe;
            }

            foreach ($questionAnswers as $idx => $ansText) {
                $qIndex = $idx + 1;
                $questionId = $qMap[$qIndex] ?? 0;
                if ($questionId <= 0) {
                    continue;
                }

                $eval = $qEvalMap[$qIndex] ?? null;
                $score = $eval ? (float)$eval['score'] : 50.0;
                $feedback = $eval ? (string)$eval['feedback'] : 'Answer recorded.';
                $rubricsJson = $eval && !empty($eval['rubrics']) ? json_encode($eval['rubrics']) : null;

                $stmtAns->execute([
                    ':assessment_id'          => $assessmentId,
                    ':question_id'            => $questionId,
                    ':answer_text'            => trim((string)$ansText),
                    ':score'                  => $score,
                    ':feedback'               => $feedback,
                    ':rubric_breakdown_json'  => $rubricsJson
                ]);
            }

            // 3. Update assessment overall record
            $overallScore = (float)$evalResult['overall_score'];
            $demonstrationLevel = (string)$evalResult['demonstration_level'];
            $aiFeedback = (string)$evalResult['ai_feedback'];
            $strengthsJson = json_encode($evalResult['strengths'] ?? []);
            $improvementsJson = json_encode($evalResult['improvement_areas'] ?? []);
            $recommendationsJson = json_encode($evalResult['recommended_actions'] ?? []);
            $rubricSummaryJson = json_encode($evalResult['rubric_summary'] ?? []);

            $stmtUpdate = $this->db->prepare("
                UPDATE ai_skill_assessments SET
                    status = 'completed',
                    overall_score = :overall_score,
                    demonstration_level = :demonstration_level,
                    ai_feedback = :ai_feedback,
                    strengths_json = :strengths_json,
                    improvements_json = :improvements_json,
                    recommendations_json = :recommendations_json,
                    rubric_summary_json = :rubric_summary_json,
                    completed_at = NOW()
                WHERE id = :id AND user_id = :user_id
            ");
            $stmtUpdate->execute([
                ':id'                   => $assessmentId,
                ':user_id'              => $userId,
                ':overall_score'        => $overallScore,
                ':demonstration_level'  => $demonstrationLevel,
                ':ai_feedback'          => $aiFeedback,
                ':strengths_json'       => $strengthsJson,
                ':improvements_json'    => $improvementsJson,
                ':recommendations_json' => $recommendationsJson,
                ':rubric_summary_json'  => $rubricSummaryJson
            ]);

            // 4. Update student skill competency level in student_skills
            $stmtSkillId = $this->db->prepare("SELECT skill_id FROM ai_skill_assessments WHERE id = :id");
            $stmtSkillId->execute([':id' => $assessmentId]);
            $skillId = (int)$stmtSkillId->fetchColumn();

            // Convert 0-100 score to 1-5 level
            $derivedLevel = 1;
            if ($overallScore >= 85) {
                $derivedLevel = 5;
            } elseif ($overallScore >= 70) {
                $derivedLevel = 4;
            } elseif ($overallScore >= 50) {
                $derivedLevel = 3;
            } elseif ($overallScore >= 35) {
                $derivedLevel = 2;
            }

            $stmtUpsertSkill = $this->db->prepare("
                INSERT INTO student_skills (user_id, skill_id, level, source, updated_at)
                VALUES (:user_id, :skill_id, :level, 'assessed', NOW())
                ON DUPLICATE KEY UPDATE
                    level = VALUES(level),
                    source = 'assessed',
                    updated_at = NOW()
            ");
            $stmtUpsertSkill->execute([
                ':user_id'  => $userId,
                ':skill_id' => $skillId,
                ':level'    => $derivedLevel
            ]);

            // 5. Synchronize with assessment_attempts & attempt_skill_scores for Dashboard & Module integration
            $stmtGoal = $this->db->prepare("SELECT career_id FROM student_career_goals WHERE user_id = :user_id AND is_primary = 1 LIMIT 1");
            $stmtGoal->execute([':user_id' => $userId]);
            $careerId = (int)$stmtGoal->fetchColumn();
            if ($careerId <= 0) {
                $careerId = 4; // Default Software Developer if none set
            }

            $stmtAttempt = $this->db->prepare("
                INSERT INTO assessment_attempts (user_id, assessment_id, career_id, status, started_at, submitted_at, score_pct)
                VALUES (:user_id, 1, :career_id, 'submitted', NOW(), NOW(), :score_pct)
            ");
            $stmtAttempt->execute([
                ':user_id'   => $userId,
                ':career_id' => $careerId,
                ':score_pct' => $overallScore
            ]);
            $attemptId = (int)$this->db->lastInsertId();

            if ($attemptId > 0) {
                $stmtScore = $this->db->prepare("
                    INSERT INTO attempt_skill_scores (attempt_id, skill_id, method, correct_count, total_count, score_pct, derived_level, feedback)
                    VALUES (:attempt_id, :skill_id, 'self_rubric', 5, 5, :score_pct, :derived_level, :feedback)
                ");
                $stmtScore->execute([
                    ':attempt_id'    => $attemptId,
                    ':skill_id'      => $skillId,
                    ':score_pct'     => $overallScore,
                    ':derived_level' => $derivedLevel,
                    ':feedback'      => substr($aiFeedback, 0, 390)
                ]);
            }

            $this->db->commit();

            // Notify student in-app that assessment results are ready
            if (file_exists(__DIR__ . '/notification_service.php')) {
                require_once __DIR__ . '/notification_service.php';
                createNotification(
                    $this->db,
                    $userId,
                    'assessment_completed',
                    'Skill Assessment Evaluated',
                    "Your AI skill assessment has been evaluated. Review your verified diagnostic report.",
                    "assessment_results.php?id={$assessmentId}",
                    "asmt_completed_{$assessmentId}_u{$userId}"
                );
            }

            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Failed to finalize assessment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves historical assessment attempts for student
     */
    public function getAssessmentHistory(int $userId, ?int $skillId = null): array
    {
        $sql = "
            SELECT 
                a.id AS assessment_id,
                a.skill_id,
                s.name AS skill_name,
                COALESCE(sc.name, 'General') AS category_name,
                a.overall_score,
                a.demonstration_level,
                a.status,
                a.attempt_number,
                a.completed_at,
                a.created_at
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE a.user_id = :user_id AND a.status = 'completed'
        ";

        $params = [':user_id' => $userId];
        if ($skillId !== null && $skillId > 0) {
            $sql .= " AND a.skill_id = :skill_id";
            $params[':skill_id'] = $skillId;
        }

        $sql .= " ORDER BY a.completed_at DESC, a.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate improvement delta compared with previous attempt for the same skill
        // Group by skill_id in chronological order
        $bySkill = [];
        foreach ($history as $idx => $row) {
            $sId = (int)$row['skill_id'];
            $bySkill[$sId][] = $idx;
        }

        foreach ($bySkill as $sId => $indexes) {
            // $indexes are sorted newest to oldest because of ORDER BY completed_at DESC
            $count = count($indexes);
            for ($i = 0; $i < $count; $i++) {
                $currIdx = $indexes[$i];
                $history[$currIdx]['score_delta'] = null;
                $history[$currIdx]['score_delta_label'] = 'Baseline Attempt';
                
                // Compare with older attempt if one exists
                if ($i + 1 < $count) {
                    $prevIdx = $indexes[$i + 1];
                    $prevScore = (float)$history[$prevIdx]['overall_score'];
                    $currScore = (float)$history[$currIdx]['overall_score'];
                    $delta = round($currScore - $prevScore, 1);
                    $history[$currIdx]['score_delta'] = $delta;
                    if ($delta > 0) {
                        $history[$currIdx]['score_delta_label'] = "+{$delta}% vs Attempt #" . $history[$prevIdx]['attempt_number'];
                    } elseif ($delta < 0) {
                        $history[$currIdx]['score_delta_label'] = "{$delta}% vs Attempt #" . $history[$prevIdx]['attempt_number'];
                    } else {
                        $history[$currIdx]['score_delta_label'] = "Same score as Attempt #" . $history[$prevIdx]['attempt_number'];
                    }
                }
            }
        }

        return $history;
    }

    /**
     * Retrieves all previous questions asked to the student for a given skill across all attempts.
     */
    public function getStudentPreviousQuestions(int $userId, int $skillId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                q.id AS question_id,
                q.assessment_id,
                q.question_index,
                q.scenario_text,
                q.evaluation_criteria,
                q.question_fingerprint,
                q.scenario_category,
                q.difficulty_level,
                a.attempt_number,
                a.status AS assessment_status,
                q.created_at
            FROM ai_assessment_questions q
            JOIN ai_skill_assessments a ON q.assessment_id = a.id
            WHERE a.user_id = :user_id 
              AND a.skill_id = :skill_id
            ORDER BY a.attempt_number DESC, q.question_index ASC
        ");
        $stmt->execute([':user_id' => $userId, ':skill_id' => $skillId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Computes a normalized SHA-256 fingerprint for a question scenario.
     */
    public function computeQuestionFingerprint(string $text): string
    {
        $normalized = strtolower(trim($text));
        $normalized = preg_replace('/[^\w\s]/u', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $stopWords = ['the','a','an','is','in','at','of','on','and','or','for','to','with','you','your','would','how','what','are','can','could','this','that','from','into','about','their','there','team','work','project','handle','situation','describe','steps'];
        $words = explode(' ', $normalized);
        $filtered = [];
        foreach ($words as $w) {
            if (strlen($w) > 2 && !in_array($w, $stopWords, true)) {
                $filtered[] = $w;
            }
        }
        return hash('sha256', implode(' ', $filtered));
    }

    /**
     * Extracts meaningful content word tokens for semantic similarity checking.
     */
    public function extractMeaningfulTokens(string $text): array
    {
        $normalized = strtolower(trim($text));
        $normalized = preg_replace('/[^\w\s]/u', '', $normalized);
        $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $stopWords = ['the','a','an','is','in','at','of','on','and','or','for','to','with','you','your','would','how','what','are','can','could','this','that','from','into','about','their','there','team','work','project','handle','situation','describe','steps'];
        $tokens = [];
        foreach ($words as $w) {
            if (strlen($w) > 2 && !in_array($w, $stopWords, true)) {
                $tokens[] = $w;
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Checks if a candidate question is an exact duplicate or close paraphrase of any previous question.
     */
    public function isQuestionDuplicate(string $candidateText, array $previousQuestions, float $similarityThreshold = 0.55): bool
    {
        if (empty($previousQuestions)) {
            return false;
        }

        $candidateFp = $this->computeQuestionFingerprint($candidateText);
        $candidateTokens = $this->extractMeaningfulTokens($candidateText);

        foreach ($previousQuestions as $prev) {
            $prevText = (string)($prev['scenario_text'] ?? ($prev['scenario'] ?? ''));
            if (empty($prevText)) continue;

            // 1. Exact or fingerprint match
            $prevFp = !empty($prev['question_fingerprint']) ? $prev['question_fingerprint'] : $this->computeQuestionFingerprint($prevText);
            if ($candidateFp === $prevFp) {
                return true;
            }

            // 2. Jaccard token overlap to catch paraphrases
            $prevTokens = $this->extractMeaningfulTokens($prevText);
            if (!empty($candidateTokens) && !empty($prevTokens)) {
                $intersection = array_intersect($candidateTokens, $prevTokens);
                $union = array_unique(array_merge($candidateTokens, $prevTokens));
                if (!empty($union)) {
                    $similarity = count($intersection) / count($union);
                    if ($similarity >= $similarityThreshold) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Saves or updates a student's answer for a single question in the conversational assessment.
     */
    public function saveAssessmentAnswer(int $assessmentId, int $questionId, string $answerText, ?float $score = null, ?string $feedback = null): bool
    {
        $stmtCheck = $this->db->prepare("SELECT id FROM ai_assessment_answers WHERE assessment_id = :aid AND question_id = :qid LIMIT 1");
        $stmtCheck->execute([':aid' => $assessmentId, ':qid' => $questionId]);
        $existingId = $stmtCheck->fetchColumn();

        if ($existingId) {
            $stmtUp = $this->db->prepare("
                UPDATE ai_assessment_answers 
                SET answer_text = :ans, score = :score, feedback = :fb 
                WHERE id = :id
            ");
            return $stmtUp->execute([
                ':ans'   => trim($answerText),
                ':score' => $score,
                ':fb'    => $feedback,
                ':id'    => $existingId
            ]);
        } else {
            $stmtIns = $this->db->prepare("
                INSERT INTO ai_assessment_answers (assessment_id, question_id, answer_text, score, feedback, created_at)
                VALUES (:aid, :qid, :ans, :score, :fb, NOW())
            ");
            return $stmtIns->execute([
                ':aid'   => $assessmentId,
                ':qid'   => $questionId,
                ':ans'   => trim($answerText),
                ':score' => $score,
                ':fb'    => $feedback
            ]);
        }
    }

    /**
     * Curates real extracurricular activity recommendations from carrearact2
     * matching the assessed skill and identified weaknesses.
     */
    public function getRecommendedActivitiesForSkill(int $skillId, int $userId, int $limit = 4): array
    {
        // 1. Direct skill matches in activity_skills
        $stmt = $this->db->prepare("
            SELECT 
                a.id AS activity_id,
                a.title AS activity_title,
                a.description,
                a.delivery_mode,
                a.difficulty,
                a.typical_hours,
                COALESCE(ac.name, 'Campus Initiative') AS category_name,
                ask.gain_level,
                CASE 
                    WHEN e.id IS NOT NULL THEN 1 
                    ELSE 0 
                END AS is_enrolled
            FROM activities a
            JOIN activity_skills ask ON a.id = ask.activity_id AND ask.skill_id = :skill_id
            LEFT JOIN activity_categories ac ON a.category_id = ac.id
            LEFT JOIN enrollments e ON a.id = e.activity_id AND e.user_id = :user_id
            WHERE a.is_active = 1
            ORDER BY ask.gain_level DESC, a.difficulty ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':skill_id', $skillId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $activities = $stmt->fetchAll();

        // 2. If direct matches are fewer than limit, pull domain activities
        if (count($activities) < $limit) {
            $existingIds = array_column($activities, 'activity_id');
            $existingPlaceholders = empty($existingIds) ? '0' : implode(',', array_map('intval', $existingIds));

            $stmtFallback = $this->db->prepare("
                SELECT 
                    a.id AS activity_id,
                    a.title AS activity_title,
                    a.description,
                    a.delivery_mode,
                    a.difficulty,
                    a.typical_hours,
                    COALESCE(ac.name, 'Campus Initiative') AS category_name,
                    2 AS gain_level,
                    0 AS is_enrolled,
                    0 AS is_saved
                FROM activities a
                LEFT JOIN activity_categories ac ON a.category_id = ac.id
                WHERE a.is_active = 1 AND a.id NOT IN ({$existingPlaceholders})
                ORDER BY a.id ASC
                LIMIT :rem_limit
            ");
            $rem = $limit - count($activities);
            $stmtFallback->bindValue(':rem_limit', $rem, PDO::PARAM_INT);
            $stmtFallback->execute();
            $more = $stmtFallback->fetchAll();
            $activities = array_merge($activities, $more);
        }

        return $activities;
    }
}
