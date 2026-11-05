<?php
/**
 * CareerAct — AI Skill-Gap Analysis Service (Module 7)
 * 
 * Analyzes verified competencies and improvement areas strictly based on
 * real, completed assessment sessions from Module 6 (ai_skill_assessments).
 * 
 * Enforces strict skill scope, backend-driven gap thresholds, immutable
 * historical persistence, and seamless dashboard integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/gemini_service.php';

class SkillGapService
{
    private PDO $db;
    private GeminiService $gemini;

    public function __construct(PDO $db, ?GeminiService $gemini = null)
    {
        $this->db = $db;
        $this->gemini = $gemini ?? new GeminiService();
    }

    /**
     * Checks if the student has at least one completed assessment in Module 6
     */
    public function hasCompletedAssessments(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM ai_skill_assessments 
            WHERE user_id = :user_id AND status = 'completed'
        ");
        $stmt->execute([':user_id' => $userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Retrieves all completed assessments for the student (for attempt selector)
     */
    public function getStudentCompletedAssessments(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.id AS assessment_id,
                a.skill_id,
                s.name AS skill_name,
                COALESCE(sc.name, 'General') AS category_name,
                a.overall_score,
                a.demonstration_level,
                a.attempt_number,
                a.completed_at,
                ga.id AS gap_analysis_id,
                ga.gap_status
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            LEFT JOIN ai_skill_gap_analyses ga ON a.id = ga.assessment_id AND ga.user_id = :uid2
            WHERE a.user_id = :user_id AND a.status = 'completed'
            ORDER BY a.completed_at DESC, a.id DESC
        ");
        $stmt->execute([':user_id' => $userId, ':uid2' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves the latest completed assessment for a student
     */
    public function getLatestCompletedAssessment(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.id AS assessment_id,
                a.skill_id,
                s.name AS skill_name,
                COALESCE(sc.name, 'General') AS category_name,
                a.overall_score,
                a.demonstration_level,
                a.attempt_number,
                a.completed_at
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE a.user_id = :user_id AND a.status = 'completed'
            ORDER BY a.completed_at DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Loads a specific completed assessment with full questions, student answers, and evaluations.
     * Enforces strict student ownership.
     */
    public function getAssessmentForGapAnalysis(int $assessmentId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                s.name AS skill_name,
                COALESCE(sc.name, 'General') AS category_name
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE a.id = :id AND a.user_id = :user_id AND a.status = 'completed'
            LIMIT 1
        ");
        $stmt->execute([':id' => $assessmentId, ':user_id' => $userId]);
        $assessment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assessment) {
            return null;
        }

        // Fetch questions
        $stmtQ = $this->db->prepare("
            SELECT * FROM ai_assessment_questions 
            WHERE assessment_id = :id 
            ORDER BY question_index ASC
        ");
        $stmtQ->execute([':id' => $assessmentId]);
        $assessment['questions'] = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        // Fetch answers
        $stmtA = $this->db->prepare("
            SELECT * FROM ai_assessment_answers 
            WHERE assessment_id = :id 
            ORDER BY id ASC
        ");
        $stmtA->execute([':id' => $assessmentId]);
        $assessment['answers'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);

        return $assessment;
    }

    /**
     * Calculates backend gap metrics and thresholds strictly matching Module 6 rules
     */
    public function calculateGapMetrics(float $score): array
    {
        if ($score >= 80.0) {
            return [
                'gap_status'          => 'proficient',
                'gap_title'           => 'Strong Competency (No Gap)',
                'gap_color'           => '#059669',
                'gap_bg'              => '#ECFDF5',
                'gap_border'          => '#A7F3D0',
                'gap_description'     => 'You demonstrated high proficiency and sound practical trade-offs. Continue with advanced challenges to maintain mastery.',
                'classification'      => 'strong'
            ];
        } elseif ($score >= 50.0) {
            return [
                'gap_status'          => 'moderate_gap',
                'gap_title'           => 'Moderate Development Gap',
                'gap_color'           => '#D97706',
                'gap_bg'              => '#FFFBEB',
                'gap_border'          => '#FDE68A',
                'gap_description'     => 'Foundational understanding is present, but handling complex trade-offs, escalations, or preventive documentation requires targeted practice.',
                'classification'      => 'developing'
            ];
        } else {
            return [
                'gap_status'          => 'critical_gap',
                'gap_title'           => 'Critical Improvement Gap',
                'gap_color'           => '#DC2626',
                'gap_bg'              => '#FEF2F2',
                'gap_border'          => '#FECACA',
                'gap_description'     => 'Responses indicated significant developmental gaps. Formulating structured, stepwise resolution workflows with clear rationale is recommended.',
                'classification'      => 'needs_improvement'
            ];
        }
    }

    /**
     * Retrieves saved gap analysis from database to prevent redundant Gemini API calls
     */
    public function getSavedSkillGapAnalysis(int $assessmentId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ga.*, s.name AS skill_name, COALESCE(sc.name, 'General') AS category_name
            FROM ai_skill_gap_analyses ga
            JOIN skills s ON ga.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE ga.assessment_id = :assessment_id AND ga.user_id = :user_id 
            LIMIT 1
        ");
        $stmt->execute([':assessment_id' => $assessmentId, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['strengths'] = json_decode((string)$row['strengths_json'], true) ?: [];
        $row['improvement_areas'] = json_decode((string)$row['improvements_json'], true) ?: [];
        $row['practice_actions'] = json_decode((string)$row['practice_actions_json'], true) ?: [];
        $row['metrics'] = $this->calculateGapMetrics((float)$row['overall_score']);

        return $row;
    }

    /**
     * Generates or retrieves the skill gap analysis for an assessment session
     */
    public function generateSkillGapAnalysis(int $assessmentId, int $userId, bool $forceRefresh = false): array
    {
        // 1. Verify student ownership and load assessment
        $assessment = $this->getAssessmentForGapAnalysis($assessmentId, $userId);
        if (!$assessment) {
            throw new InvalidArgumentException("Assessment ID {$assessmentId} not found or unauthorized for student {$userId}.");
        }

        // 2. Return cached analysis if valid and not forcing refresh
        if (!$forceRefresh) {
            $cached = $this->getSavedSkillGapAnalysis($assessmentId, $userId);
            if ($cached) {
                return $cached;
            }
        }

        // 3. Strict Scope Check: Assessed skill MUST come from Module 6 record
        $skillId = (int)$assessment['skill_id'];
        $skillName = (string)$assessment['skill_name'];
        $categoryName = (string)$assessment['category_name'];
        $score = (float)$assessment['overall_score'];

        // 4. Calculate backend gap classification (never let AI invent it)
        $metrics = $this->calculateGapMetrics($score);
        $gapStatus = $metrics['gap_status'];

        // 5. Load student's primary career goal for optional context
        $stmtGoal = $this->db->prepare("
            SELECT c.id, c.title 
            FROM student_career_goals scg 
            JOIN careers c ON scg.career_id = c.id 
            WHERE scg.user_id = :user_id AND scg.is_primary = 1 
            LIMIT 1
        ");
        $stmtGoal->execute([':user_id' => $userId]);
        $career = $stmtGoal->fetch(PDO::FETCH_ASSOC);
        $careerId = $career ? (int)$career['id'] : null;
        $careerTitle = $career ? (string)$career['title'] : null;

        // 6. Format assessment evidence for Gemini
        $evidence = [];
        $answers = $assessment['answers'] ?? [];
        foreach ($assessment['questions'] as $i => $q) {
            $ans = $answers[$i] ?? null;
            $evidence[] = [
                'scenario' => (string)$q['scenario_text'],
                'answer'   => $ans ? (string)$ans['answer_text'] : '',
                'score'    => $ans ? (float)$ans['score'] : 50.0,
                'feedback' => $ans ? (string)$ans['feedback'] : ''
            ];
        }

        // 7. Generate structured gap analysis via GeminiService
        $analysisResult = $this->gemini->analyzeSkillGap(
            $skillName,
            $categoryName,
            $score,
            $gapStatus,
            $evidence,
            $careerTitle
        );

        // 8. Server-side validation of Gemini's returned skill
        if ($analysisResult['skill_name'] !== $skillName) {
            throw new RuntimeException("Gemini returned unauthorized skill '{$analysisResult['skill_name']}', expected '{$skillName}'.");
        }

        // 9. Persist analysis to ai_skill_gap_analyses table
        $strengthsJson = json_encode($analysisResult['strengths']);
        $improvementsJson = json_encode($analysisResult['improvement_areas']);
        $practiceActionsJson = json_encode($analysisResult['practice_actions']);
        $fullAnalysisJson = json_encode($analysisResult);

        $stmtSave = $this->db->prepare("
            INSERT INTO ai_skill_gap_analyses (
                user_id, assessment_id, career_id, skill_id, gap_status, overall_score,
                summary_text, strengths_json, improvements_json, practice_actions_json,
                evidence_explanation, career_context_note, full_analysis_json, created_at, updated_at
            ) VALUES (
                :user_id, :assessment_id, :career_id, :skill_id, :gap_status, :overall_score,
                :summary_text, :strengths_json, :improvements_json, :practice_actions_json,
                :evidence_explanation, :career_context_note, :full_analysis_json, NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                gap_status = VALUES(gap_status),
                overall_score = VALUES(overall_score),
                summary_text = VALUES(summary_text),
                strengths_json = VALUES(strengths_json),
                improvements_json = VALUES(improvements_json),
                practice_actions_json = VALUES(practice_actions_json),
                evidence_explanation = VALUES(evidence_explanation),
                career_context_note = VALUES(career_context_note),
                full_analysis_json = VALUES(full_analysis_json),
                updated_at = NOW()
        ");

        $stmtSave->execute([
            ':user_id'              => $userId,
            ':assessment_id'        => $assessmentId,
            ':career_id'            => $careerId,
            ':skill_id'             => $skillId,
            ':gap_status'           => $gapStatus,
            ':overall_score'        => $score,
            ':summary_text'         => $analysisResult['summary_text'],
            ':strengths_json'       => $strengthsJson,
            ':improvements_json'    => $improvementsJson,
            ':practice_actions_json'=> $practiceActionsJson,
            ':evidence_explanation' => $analysisResult['evidence_explanation'],
            ':career_context_note'  => $analysisResult['career_context_note'],
            ':full_analysis_json'   => $fullAnalysisJson
        ]);

        if (file_exists(__DIR__ . '/notification_service.php')) {
            require_once __DIR__ . '/notification_service.php';
            createNotification(
                $this->db,
                $userId,
                'skill_gap_ready',
                'Skill-Gap Analysis Ready',
                "Your personalized skill-gap diagnostic report is available for review.",
                'skill_gap.php',
                "skill_gap_ready_asmt{$assessmentId}_u{$userId}"
            );
        }

        return $this->getSavedSkillGapAnalysis($assessmentId, $userId);
    }

    /**
     * Retrieves the latest skill gap analysis for the student (for Dashboard integration)
     */
    public function getLatestAnalysisForStudent(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ga.*, s.name AS skill_name, COALESCE(sc.name, 'General') AS category_name,
                   c.title AS career_title, a.attempt_number, a.completed_at
            FROM ai_skill_gap_analyses ga
            JOIN skills s ON ga.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            LEFT JOIN careers c ON ga.career_id = c.id
            JOIN ai_skill_assessments a ON ga.assessment_id = a.id
            WHERE ga.user_id = :user_id
            ORDER BY ga.updated_at DESC, ga.id DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['strengths'] = json_decode((string)$row['strengths_json'], true) ?: [];
        $row['improvement_areas'] = json_decode((string)$row['improvements_json'], true) ?: [];
        $row['practice_actions'] = json_decode((string)$row['practice_actions_json'], true) ?: [];
        $row['metrics'] = $this->calculateGapMetrics((float)$row['overall_score']);

        return $row;
    }
}
