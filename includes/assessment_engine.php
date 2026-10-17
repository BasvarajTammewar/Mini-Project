<?php
/**
 * CareerAct — Career-Relevance and Skill Assessment Engine
 * Comprehensive, transparent, rule-based evaluation engine
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Executes a full career assessment for a student
 *
 * @param PDO $db
 * @param int $studentId
 * @param int $careerId
 * @return array Assessment results package
 */
function runCareerAssessment(PDO $db, int $studentId, int $careerId): array {
    // 1. Fetch Student Profile
    $stmtStudent = $db->prepare("
        SELECT sp.*, u.email 
        FROM student_profiles sp 
        JOIN users u ON sp.user_id = u.user_id 
        WHERE sp.student_id = :student_id LIMIT 1
    ");
    $stmtStudent->execute([':student_id' => $studentId]);
    $student = $stmtStudent->fetch();

    if (!$student) {
        throw new InvalidArgumentException("Student record not found for ID: {$studentId}");
    }

    // 2. Fetch Career Path & Core Roles
    $stmtCareer = $db->prepare("
        SELECT cp.*, d.domain_name,
               (SELECT role_title FROM career_roles cr WHERE cr.career_id = cp.career_id ORDER BY cr.sort_order ASC LIMIT 1) AS primary_role
        FROM career_paths cp
        LEFT JOIN domains d ON cp.domain_id = d.domain_id
        WHERE cp.career_id = :career_id LIMIT 1
    ");
    $stmtCareer->execute([':career_id' => $careerId]);
    $career = $stmtCareer->fetch();

    if (!$career) {
        throw new InvalidArgumentException("Career pathway not found for ID: {$careerId}");
    }

    // 3. Fetch Required Skills for this Career
    $stmtReqSkills = $db->prepare("
        SELECT 
            crs.skill_id,
            crs.expected_level_id,
            crs.importance_weight,
            s.skill_name,
            s.skill_slug,
            sc.category_name,
            pl.level_name AS expected_level_name,
            pl.level_value AS expected_level_value
        FROM career_required_skills crs
        JOIN skills s ON crs.skill_id = s.skill_id
        JOIN skill_categories sc ON s.skill_category_id = sc.skill_category_id
        JOIN proficiency_levels pl ON crs.expected_level_id = pl.proficiency_level_id
        WHERE crs.career_id = :career_id
        ORDER BY crs.importance_weight DESC, crs.expected_level_id DESC
    ");
    $stmtReqSkills->execute([':career_id' => $careerId]);
    $requiredSkills = $stmtReqSkills->fetchAll();

    // 4. Fetch Student's Current Skills
    $stmtStudentSkills = $db->prepare("
        SELECT 
            ss.skill_id,
            ss.proficiency_level_id,
            ss.source_code,
            s.skill_name,
            pl.level_name,
            pl.level_value
        FROM student_skills ss
        JOIN skills s ON ss.skill_id = s.skill_id
        JOIN proficiency_levels pl ON ss.proficiency_level_id = pl.proficiency_level_id
        WHERE ss.student_id = :student_id
    ");
    $stmtStudentSkills->execute([':student_id' => $studentId]);
    $studentSkills = [];
    while ($row = $stmtStudentSkills->fetch()) {
        $studentSkills[$row['skill_id']] = $row;
    }

    // 5. Fetch Student's Extracurricular Activities & Practical Experiences
    $stmtActivities = $db->prepare("
        SELECT 
            sae.*,
            a.title,
            a.description AS activity_description,
            t.type_name,
            t.is_technical,
            m.mode_name,
            COALESCE(o.organizer_name, 'Campus Organizer') AS organizer_name
        FROM student_activity_enrollments sae
        JOIN activities a ON sae.activity_id = a.activity_id
        JOIN activity_types t ON a.activity_type_id = t.activity_type_id
        JOIN activity_modes m ON a.mode_id = m.mode_id
        LEFT JOIN organizers o ON a.organizer_id = o.organizer_id
        WHERE sae.student_id = :student_id
        ORDER BY sae.enrolled_at DESC
    ");
    $stmtActivities->execute([':student_id' => $studentId]);
    $activities = $stmtActivities->fetchAll();

    // Also fetch custom student projects & achievements
    $stmtProjects = $db->prepare("SELECT * FROM student_projects WHERE student_id = :student_id ORDER BY project_id DESC");
    $stmtProjects->execute([':student_id' => $studentId]);
    $projects = $stmtProjects->fetchAll();

    $stmtAchievements = $db->prepare("SELECT * FROM achievements WHERE student_id = :student_id ORDER BY achievement_id DESC");
    $stmtAchievements->execute([':student_id' => $studentId]);
    $achievements = $stmtAchievements->fetchAll();

    // -------------------------------------------------------------------------
    // EVALUATION PIPELINE
    // -------------------------------------------------------------------------

    $evidenceVerifiedCount = 0;
    $selfReportedCount = 0;
    $totalDescriptionLength = 0;
    $activityCount = count($activities) + count($projects);

    // Track evidence links and descriptions
    foreach ($activities as $act) {
        $hasProof = !empty($act['proof_url']) || !empty($act['certificate_reference']) || ($act['completion_verification'] === 'verified');
        if ($hasProof) {
            $evidenceVerifiedCount++;
        } else {
            $selfReportedCount++;
        }

        $desc = ($act['student_reflection'] ?? '') . ' ' . ($act['skills_gained_text'] ?? '');
        $totalDescriptionLength += mb_strlen(trim($desc));
    }

    foreach ($projects as $proj) {
        if (!empty($proj['project_url'])) {
            $evidenceVerifiedCount++;
        } else {
            $selfReportedCount++;
        }
        $totalDescriptionLength += mb_strlen(trim($proj['description'] ?? ''));
    }

    $avgDescriptionLength = $activityCount > 0 ? (int)round($totalDescriptionLength / $activityCount) : 0;

    // A. Detect Path C: Insufficient Information (Vague or empty description)
    $isInsufficientInfo = false;
    $clarificationQuestions = [];

    if ($activityCount > 0 && $avgDescriptionLength < 35 && $evidenceVerifiedCount === 0) {
        $isInsufficientInfo = true;
        $clarificationQuestions = [
            "What specific role or module did you implement in your reported extracurricular projects?",
            "What concrete tools, languages, or architectural patterns were utilized?",
            "Can you provide a repository link, live application demo URL, or certificate of completion?"
        ];
    }

    // B. Skill Gap Analysis & Scoring
    $totalPossibleWeight = 0;
    $earnedWeight = 0;
    $gaps = [];
    $strengths = [];
    $improvements = [];

    // Clear previous skill gaps for this student and career
    $db->prepare("DELETE FROM skill_gap_results WHERE student_id = :student_id AND career_id = :career_id")
       ->execute([':student_id' => $studentId, ':career_id' => $careerId]);

    $stmtInsertGap = $db->prepare("
        INSERT INTO skill_gap_results (
            student_id, career_id, skill_id, current_level_value, required_level_value, gap_score, suggested_action, calculated_at
        ) VALUES (
            :student_id, :career_id, :skill_id, :curr_val, :req_val, :gap_score, :action, NOW()
        )
    ");

    foreach ($requiredSkills as $req) {
        $skillId = (int)$req['skill_id'];
        $weight = (float)$req['importance_weight'];
        $reqLevelVal = (int)$req['expected_level_value'];
        $totalPossibleWeight += ($weight * $reqLevelVal);

        $currLevelVal = 0;
        $currLevelName = 'Unassessed';
        $isEvidenceBacked = false;

        // Check if student has recorded this skill
        if (isset($studentSkills[$skillId])) {
            $currLevelVal = (int)$studentSkills[$skillId]['level_value'];
            $currLevelName = $studentSkills[$skillId]['level_name'];
            $isEvidenceBacked = ($studentSkills[$skillId]['source_code'] === 'activity_completed' || $studentSkills[$skillId]['source_code'] === 'certificate');
        }

        // Cross-examine if demonstrated in activities
        foreach ($activities as $act) {
            $combinedText = strtolower(($act['title'] ?? '') . ' ' . ($act['student_reflection'] ?? '') . ' ' . ($act['skills_gained_text'] ?? ''));
            if (stripos($combinedText, strtolower($req['skill_name'])) !== false) {
                if ($currLevelVal < 3) {
                    $currLevelVal = max($currLevelVal, 2); // Boost to elementary if demonstrated in real activity
                }
                if (!empty($act['proof_url']) || !empty($act['certificate_reference'])) {
                    $isEvidenceBacked = true;
                }
            }
        }

        $gapScore = max(0, $reqLevelVal - $currLevelVal);
        $earned = min($reqLevelVal, $currLevelVal) * $weight;
        // Evidence bonus
        if ($isEvidenceBacked && $currLevelVal > 0) {
            $earned = min($weight * $reqLevelVal, $earned * 1.1);
        }
        $earnedWeight += $earned;

        // Suggested action lookup
        $action = "Practice {$req['skill_name']} through focused hackathons and practical portfolio projects.";
        if ($gapScore > 0) {
            $improvements[] = [
                'skill_id'           => $skillId,
                'skill_name'         => $req['skill_name'],
                'category_name'      => $req['category_name'],
                'current_level'      => $currLevelName,
                'required_level'     => $req['expected_level_name'],
                'gap_score'          => $gapScore,
                'reason'             => "Essential for {$career['career_title']} to handle real-world challenges.",
                'is_evidence_backed' => $isEvidenceBacked
            ];
            $action = "Close competency gap from {$currLevelName} to {$req['expected_level_name']} via targeted coursework and competition builds.";
        } else {
            $strengths[] = [
                'skill_id'           => $skillId,
                'skill_name'         => $req['skill_name'],
                'category_name'      => $req['category_name'],
                'current_level'      => $currLevelName,
                'is_evidence_backed' => $isEvidenceBacked,
                'evidence_note'      => $isEvidenceBacked ? 'Verified via project/certificate record' : 'Self-declared competency'
            ];
        }

        // Save gap record
        $stmtInsertGap->execute([
            ':student_id' => $studentId,
            ':career_id'  => $careerId,
            ':skill_id'   => $skillId,
            ':curr_val'   => $currLevelVal,
            ':req_val'    => $reqLevelVal,
            ':gap_score'  => $gapScore,
            ':action'     => $action
        ]);
    }

    // Compute Overall Readiness Score (0 to 100)
    $readinessScore = $totalPossibleWeight > 0 ? (int)round(($earnedWeight / $totalPossibleWeight) * 100) : 0;

    // C. Determine Decision Branching Path
    if ($isInsufficientInfo) {
        $decisionPath = 'path_c';
        $pathTitle = 'Path C — Insufficient Information (Provisional Assessment)';
        $confidenceLevel = 'provisional';
    } elseif ($readinessScore >= 75 && count($strengths) >= 3 && $evidenceVerifiedCount >= 2) {
        $decisionPath = 'path_a';
        $pathTitle = 'Path A — Strong Alignment / Advance Competencies';
        $confidenceLevel = 'high';
    } else {
        $decisionPath = 'path_b';
        $pathTitle = 'Path B — Improvement Needed / Structured Roadmap';
        $confidenceLevel = $evidenceVerifiedCount > 0 ? 'moderate' : 'provisional';
    }

    // D. Generate Curated Recommendations & Reasons
    $stmtRun = $db->prepare("INSERT INTO recommendation_runs (student_id, generated_at, trigger_code) VALUES (:sid, NOW(), 'assessment')");
    $stmtRun->execute([':sid' => $studentId]);
    $runId = (int)$db->lastInsertId();

    // Query published, non-expired activities
    $stmtCandidateActs = $db->prepare("
        SELECT a.activity_id, a.title, t.type_name, m.mode_name, a.registration_deadline
        FROM activities a
        JOIN activity_types t ON a.activity_type_id = t.activity_type_id
        JOIN activity_modes m ON a.mode_id = m.mode_id
        WHERE a.activity_status_id = 3
          AND (a.registration_deadline IS NULL OR a.registration_deadline >= NOW())
        ORDER BY a.activity_id DESC
        LIMIT 6
    ");
    $stmtCandidateActs->execute();
    $candidateActivities = $stmtCandidateActs->fetchAll();

    // Delete prior active recommendations
    $db->prepare("DELETE FROM activity_recommendations WHERE student_id = :student_id AND is_dismissed = 0")
       ->execute([':student_id' => $studentId]);

    $stmtInsReco = $db->prepare("
        INSERT INTO activity_recommendations (run_id, student_id, activity_id, career_id, relevance_score, is_dismissed)
        VALUES (:run_id, :student_id, :activity_id, :career_id, :relevance, 0)
    ");
    $stmtInsReason = $db->prepare("
        INSERT INTO recommendation_reasons (recommendation_id, reason_text, created_at)
        VALUES (:reco_id, :reason_text, NOW())
    ");

    foreach ($candidateActivities as $idx => $cAct) {
        $actId = (int)$cAct['activity_id'];
        $relevance = $decisionPath === 'path_a' ? 95 - ($idx * 3) : 92 - ($idx * 4);
        
        $reasonText = "Matches your {$career['career_title']} track by providing hands-on practical application in competitive environments.";
        if (!empty($improvements)) {
            $missingSkill = $improvements[0]['skill_name'];
            $reasonText = "Directly addresses your competency gap in {$missingSkill} through real-world team build challenges.";
        }

        $stmtInsReco->execute([
            ':run_id'      => $runId,
            ':student_id'  => $studentId,
            ':activity_id' => $actId,
            ':career_id'   => $careerId,
            ':relevance'   => $relevance
        ]);
        $recoId = (int)$db->lastInsertId();

        $stmtInsReason->execute([
            ':reco_id'     => $recoId,
            ':reason_text' => $reasonText
        ]);
    }

    // E. Generate Personalized Career Roadmap
    $db->prepare("DELETE FROM student_roadmap_milestones WHERE student_id = :student_id AND is_completed = 0")
       ->execute([':student_id' => $studentId]);

    $stmtInsMilestone = $db->prepare("
        INSERT INTO student_roadmap_milestones (
            student_id, career_id, phase_number, phase_title, competency_title, reason_text, suggested_action, priority_level, target_period, expected_outcome
        ) VALUES (
            :student_id, :career_id, :phase_num, :phase_title, :comp_title, :reason, :action, :priority, :target_period, :outcome
        )
    ");

    if ($decisionPath === 'path_a') {
        // Path A: Advanced milestones
        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 1,
            ':phase_title'   => 'Phase 1: Advanced Open-Source & Architecture',
            ':comp_title'    => 'Distributed Systems & Microservices',
            ':reason'        => 'Deepen core engineering from application development to scalable cloud architecture.',
            ':action'        => 'Contribute to established open-source repositories and build multi-node microservices.',
            ':priority'      => 'critical',
            ':target_period' => 'Weeks 1-4',
            ':outcome'       => 'Public open-source PRs merged and documented architectural design specs.'
        ]);

        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 2,
            ':phase_title'   => 'Phase 2: High-Stakes Competitive Extracurriculars',
            ':comp_title'    => 'National / Collegiate Hackathons',
            ':reason'        => 'Showcase rapid prototyping, team leadership, and product presentation under deadline pressure.',
            ':action'        => 'Lead a collegiate team at Smart India Hackathon or Devpost Cloud hackathons.',
            ':priority'      => 'high',
            ':target_period' => 'Weeks 5-8',
            ':outcome'       => 'Finalist presentation, award recognition, and demonstrable proof on transcript.'
        ]);

        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 3,
            ':phase_title'   => 'Phase 3: Industry Mentorship & Fellowship',
            ':comp_title'    => 'Engineering Fellowship & Capstone Deployment',
            ':reason'        => 'Transition academic accomplishments into direct industry placement readiness.',
            ':action'        => 'Complete a technical fellowship or internship with production deployment pipelines.',
            ':priority'      => 'medium',
            ':target_period' => 'Weeks 9-12',
            ':outcome'       => 'Verified industry reference, ATS-ready portfolio, and production GitHub repository.'
        ]);
    } else {
        // Path B & C: Targeted foundational and gap-closing roadmap
        $phase1Skill = !empty($improvements[0]) ? $improvements[0]['skill_name'] : 'Programming Fundamentals';
        $phase2Skill = !empty($improvements[1]) ? $improvements[1]['skill_name'] : 'Data Structures & System Design';

        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 1,
            ':phase_title'   => "Phase 1: Core Competency Acquisition ({$phase1Skill})",
            ':comp_title'    => "Master {$phase1Skill} Fundamentals",
            ':reason'        => "Your assessment identified {$phase1Skill} as an essential requirement for {$career['career_title']}.",
            ':action'        => "Complete guided practice exercises and implement 2 mini-projects targeting {$phase1Skill}.",
            ':priority'      => 'critical',
            ':target_period' => 'Weeks 1-3',
            ':outcome'       => "Verified understanding through code assessments and functional GitHub repositories."
        ]);

        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 2,
            ':phase_title'   => "Phase 2: Applied Extracurricular Collaboration",
            ':comp_title'    => "Participate in Beginner-Friendly Hackathons & Workshops",
            ':reason'        => 'Extracurricular build competitions convert theoretical syntax into problem-solving evidence.',
            ':action'        => 'Enroll in a collegiate hackathon or practical engineering workshop from the recommendations catalog.',
            ':priority'      => 'high',
            ':target_period' => 'Weeks 4-7',
            ':outcome'       => 'Demonstrated team collaboration, prototype delivery, and verified activity participation.'
        ]);

        $stmtInsMilestone->execute([
            ':student_id'    => $studentId,
            ':career_id'     => $careerId,
            ':phase_num'     => 3,
            ':phase_title'   => "Phase 3: Portfolio Consolidation & Verification",
            ':comp_title'    => "Deploy Capstone Project & Record Verified Evidence",
            ':reason'        => 'Recruiters and placement panels require verifiable proof rather than self-reported assertions.',
            ':action'        => 'Deploy your project with live demonstration URLs, test coverage, and upload certification credentials.',
            ':priority'      => 'medium',
            ':target_period' => 'Weeks 8-12',
            ':outcome'       => 'Verified resume entry, transcript integration, and comprehensive ATS portfolio.'
        ]);
    }

    // F. Persist Assessment Record
    $strengthsSummary = json_encode($strengths);
    $improvementsSummary = json_encode($improvements);

    $stmtSaveRecord = $db->prepare("
        INSERT INTO student_assessment_records (
            student_id, career_id, readiness_score, decision_path, path_title, strengths_summary, improvements_summary,
            confidence_level, evidence_verified_count, self_reported_count, created_at
        ) VALUES (
            :student_id, :career_id, :readiness, :path, :title, :strengths, :improvements, :confidence, :evidence, :self_reported, NOW()
        )
    ");
    $stmtSaveRecord->execute([
        ':student_id'    => $studentId,
        ':career_id'     => $careerId,
        ':readiness'     => $readinessScore,
        ':path'          => $decisionPath,
        ':title'         => $pathTitle,
        ':strengths'     => $strengthsSummary,
        ':improvements'  => $improvementsSummary,
        ':confidence'    => $confidenceLevel,
        ':evidence'      => $evidenceVerifiedCount,
        ':self_reported' => $selfReportedCount
    ]);
    $assessmentRecordId = (int)$db->lastInsertId();

    // G. Mark Student Profile and Onboarding Complete
    $db->prepare("UPDATE student_profiles SET profile_completed_at = NOW() WHERE student_id = :student_id")
       ->execute([':student_id' => $studentId]);

    return [
        'record_id'              => $assessmentRecordId,
        'student_id'             => $studentId,
        'career'                 => $career,
        'readiness_score'        => $readinessScore,
        'decision_path'          => $decisionPath,
        'path_title'             => $pathTitle,
        'confidence_level'       => $confidenceLevel,
        'evidence_verified_count'=> $evidenceVerifiedCount,
        'self_reported_count'    => $selfReportedCount,
        'strengths'              => $strengths,
        'improvements'           => $improvements,
        'clarification_questions'=> $clarificationQuestions,
        'activities_evaluated'   => $activityCount,
        'assessment_date'        => date('F j, Y')
    ];
}
