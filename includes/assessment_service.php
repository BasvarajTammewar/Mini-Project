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
     * Identifies all assessable skills for a student, categorized by Soft, Technical, and Professional
     */
    public function getStudentAssessableSkills(int $userId): array
    {
        // 1. Student's self-reported/existing skills
        $stmtMySkills = $this->db->prepare("
            SELECT skill_id, level, source
            FROM student_skills
            WHERE user_id = :user_id
        ");
        $stmtMySkills->execute([':user_id' => $userId]);
        $mySkills = [];
        while ($row = $stmtMySkills->fetch()) {
            $mySkills[(int)$row['skill_id']] = $row;
        }

        // 2. Career required skills from student's active primary goal
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

        // 3. Activity-associated skills from student enrollments/activities
        $stmtActSkills = $this->db->prepare("
            SELECT DISTINCT ask.skill_id
            FROM enrollments e
            JOIN activity_skills ask ON e.activity_id = ask.activity_id
            WHERE e.user_id = :user_id
            UNION
            SELECT DISTINCT ask.skill_id
            FROM student_existing_activities sea
            JOIN activity_skills ask ON sea.activity_id = ask.activity_id
            WHERE sea.user_id = :user_id2
        ");
        $stmtActSkills->execute([':user_id' => $userId, ':user_id2' => $userId]);
        $activitySkillIds = $stmtActSkills->fetchAll(PDO::FETCH_COLUMN);
        $activitySkillIds = array_flip(array_map('intval', $activitySkillIds));

        // 4. Latest AI assessment history per skill
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

        // 5. Total attempts count per skill
        $stmtAttempts = $this->db->prepare("
            SELECT skill_id, COUNT(*) AS attempts_count
            FROM ai_skill_assessments
            WHERE user_id = :user_id AND status = 'completed'
            GROUP BY skill_id
        ");
        $stmtAttempts->execute([':user_id' => $userId]);
        $attemptsCounts = $stmtAttempts->fetchAll(PDO::FETCH_KEY_PAIR);

        // 6. Fetch all active skills in carrearact2
        $stmtAllSkills = $this->db->query("
            SELECT 
                s.id AS skill_id,
                s.name AS skill_name,
                s.description,
                s.category_id,
                COALESCE(sc.name, 'General') AS category_name
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE s.is_active = 1
            ORDER BY sc.name ASC, s.name ASC
        ");
        $allSkills = $stmtAllSkills->fetchAll();

        // Categorize each skill into standard clusters: Soft, Technical, Professional
        $categorized = [
            'soft'         => [],
            'technical'    => [],
            'professional' => [],
            'other'        => []
        ];

        $summary = [
            'total_skills'          => count($allSkills),
            'student_skills_count'  => count($mySkills),
            'assessed_skills_count' => count($latestAssessments),
            'pending_skills_count'  => max(0, count($mySkills) - count($latestAssessments)),
            'average_score'         => 0.0,
            'career_title'          => $activeCareerTitle
        ];

        $totalScoreSum = 0;
        $scoredCount = 0;

        foreach ($allSkills as $sk) {
            $sId = (int)$sk['skill_id'];
            $name = $sk['skill_name'];
            $catName = $sk['category_name'];

            $isMySkill = isset($mySkills[$sId]);
            $isCareerReq = isset($careerSkills[$sId]);
            $isActLinked = isset($activitySkillIds[$sId]);

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
                'is_my_skill'          => $isMySkill,
                'current_level'        => $isMySkill ? (int)$mySkills[$sId]['level'] : 0,
                'skill_source'         => $isMySkill ? $mySkills[$sId]['source'] : 'untracked',
                'is_career_required'   => $isCareerReq,
                'career_required_level'=> $isCareerReq ? (int)$careerSkills[$sId]['required_level'] : 0,
                'is_activity_linked'   => $isActLinked,
                'is_assessed'          => $hasAssessed,
                'assessment_id'        => $hasAssessed ? (int)$latest['assessment_id'] : null,
                'score'                => $score,
                'demonstration_level'  => $hasAssessed ? $latest['demonstration_level'] : 'Pending Assessment',
                'completed_at'         => $hasAssessed ? $latest['completed_at'] : null,
                'attempts_count'       => (int)($attemptsCounts[$sId] ?? 0)
            ];

            $categorized[$cluster][] = $item;
        }

        if ($scoredCount > 0) {
            $summary['average_score'] = round($totalScoreSum / $scoredCount, 1);
        }

        return [
            'summary'     => $summary,
            'categorized' => $categorized,
            'all'         => $allSkills
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
     * Starts a new assessment session and persists generated questions
     */
    public function createAssessmentSession(int $userId, int $skillId, array $questions): int
    {
        // Find existing attempts count
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) FROM ai_skill_assessments 
            WHERE user_id = :user_id AND skill_id = :skill_id
        ");
        $stmtCount->execute([':user_id' => $userId, ':skill_id' => $skillId]);
        $attemptNum = ((int)$stmtCount->fetchColumn()) + 1;

        $this->db->beginTransaction();
        try {
            $stmtInsert = $this->db->prepare("
                INSERT INTO ai_skill_assessments (user_id, skill_id, status, attempt_number, created_at)
                VALUES (:user_id, :skill_id, 'in_progress', :attempt_number, NOW())
            ");
            $stmtInsert->execute([
                ':user_id'        => $userId,
                ':skill_id'       => $skillId,
                ':attempt_number' => $attemptNum
            ]);
            $assessmentId = (int)$this->db->lastInsertId();

            $stmtQ = $this->db->prepare("
                INSERT INTO ai_assessment_questions (assessment_id, question_index, scenario_text, evaluation_criteria, created_at)
                VALUES (:assessment_id, :question_index, :scenario_text, :evaluation_criteria, NOW())
            ");

            foreach ($questions as $q) {
                $stmtQ->execute([
                    ':assessment_id'      => $assessmentId,
                    ':question_index'     => (int)$q['index'],
                    ':scenario_text'      => (string)$q['scenario'],
                    ':evaluation_criteria'=> (string)($q['evaluation_focus'] ?? 'Practical Reasoning')
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

            $this->db->commit();
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
        return $stmt->fetchAll();
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
