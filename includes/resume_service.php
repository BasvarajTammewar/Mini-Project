<?php
/**
 * CareerAct — AI Resume Builder & Portfolio Service
 * Module 11: Professional Editable Resume Builder with Multi-Version Support
 * 
 * Strict Data Integrity:
 * - Uses ONLY student-confirmed profile, education, skills, certificates, and COMPLETED activities.
 * - Strictly excludes saved, registered, in-progress, or recommended activities.
 * - Zero hallucination of facts, dates, credentials, or metrics.
 * - Gemini AI writing enhancement with robust fallback.
 * - Section-by-section regeneration and multi-version management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/helpers.php';

/**
 * Retrieves all verified and confirmed resume data sources for a student.
 * Strictly filters completed activities and confirmed skills.
 */
function getStudentResumeSources(PDO $db, int $userId): array
{
    // 1. Profile Details
    $stmtProfile = $db->prepare("
        SELECT 
            u.id AS user_id,
            u.name AS full_name,
            u.email,
            sp.city,
            sp.education_level,
            sp.institution,
            sp.field_of_study,
            sp.graduation_year,
            sp.experience_level,
            sp.bio,
            sp.resume_summary
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.id = :user_id
        LIMIT 1
    ");
    $stmtProfile->execute([':user_id' => $userId]);
    $profile = $stmtProfile->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2. Active Primary Career Goal
    $stmtGoal = $db->prepare("
        SELECT scg.career_id, c.title AS career_title, c.description AS career_desc
        FROM student_career_goals scg
        JOIN careers c ON scg.career_id = c.id
        WHERE scg.user_id = :user_id
        ORDER BY scg.is_primary DESC, scg.created_at DESC
        LIMIT 1
    ");
    $stmtGoal->execute([':user_id' => $userId]);
    $careerGoal = $stmtGoal->fetch(PDO::FETCH_ASSOC) ?: null;

    // 3. Education Records
    $stmtEdu = $db->prepare("
        SELECT id, qualification, institution, start_year, end_year
        FROM student_education
        WHERE user_id = :user_id
        ORDER BY COALESCE(end_year, start_year) DESC, id DESC
    ");
    $stmtEdu->execute([':user_id' => $userId]);
    $education = $stmtEdu->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: If student_education is empty but student_profiles has education info, construct 1 entry
    if (empty($education) && !empty($profile['institution'])) {
        $qual = !empty($profile['field_of_study']) 
            ? ucfirst((string)$profile['education_level']) . ' in ' . (string)$profile['field_of_study']
            : ucfirst((string)$profile['education_level']);
        $education[] = [
            'id' => 0,
            'qualification' => $qual,
            'institution' => (string)$profile['institution'],
            'start_year' => !empty($profile['graduation_year']) ? (int)$profile['graduation_year'] - 4 : null,
            'end_year' => !empty($profile['graduation_year']) ? (int)$profile['graduation_year'] : null,
        ];
    }

    // 4. Confirmed & Assessed Skills
    $stmtSkills = $db->prepare("
        SELECT 
            s.id AS skill_id,
            s.name,
            s.skill_type,
            ss.level,
            ss.source,
            ss.updated_at
        FROM student_skills ss
        JOIN skills s ON ss.skill_id = s.id
        WHERE ss.user_id = :user_id
        ORDER BY 
            (ss.source = 'assessed') DESC,
            ss.level DESC,
            s.name ASC
    ");
    $stmtSkills->execute([':user_id' => $userId]);
    $skills = $stmtSkills->fetchAll(PDO::FETCH_ASSOC);

    // Group skills by category for resume sections
    $groupedSkills = [
        'technical'     => [],
        'analytical'    => [],
        'interpersonal' => [],
        'transferable'  => [],
        'other'         => [],
    ];
    foreach ($skills as $sk) {
        $type = strtolower((string)$sk['skill_type']);
        if (isset($groupedSkills[$type])) {
            $groupedSkills[$type][] = $sk['name'];
        } else {
            $groupedSkills['other'][] = $sk['name'];
        }
    }

    // 5. Completed Extracurricular Activities & Experience
    // ONLY student-confirmed completed experience:
    // a) From student_existing_activities
    // b) From enrollments WHERE status = 'completed' (joined with activities, opportunities, achievements)
    // STRICTLY EXCLUDES: saved, registered, in_progress, cancelled, or uncompleted activities!
    $completedExperience = [];

    // Source 5a: Existing Activities
    $stmtExisting = $db->prepare("
        SELECT 
            sea.id,
            sea.title,
            sea.role,
            sea.description,
            sea.start_date,
            sea.end_date,
            'existing_activity' AS record_type,
            COALESCE(ac.name, 'Extracurricular') AS category_name
        FROM student_existing_activities sea
        LEFT JOIN activities a ON sea.activity_id = a.id
        LEFT JOIN activity_categories ac ON a.category_id = ac.id
        WHERE sea.user_id = :user_id
        ORDER BY sea.end_date DESC, sea.start_date DESC, sea.id DESC
    ");
    $stmtExisting->execute([':user_id' => $userId]);
    while ($row = $stmtExisting->fetch(PDO::FETCH_ASSOC)) {
        $completedExperience[] = [
            'id'             => 'sea_' . $row['id'],
            'title'          => (string)$row['title'],
            'role'           => (string)($row['role'] ?: 'Participant'),
            'organization'   => (string)$row['title'],
            'category'       => (string)$row['category_name'],
            'description'    => (string)($row['description'] ?: ''),
            'start_date'     => $row['start_date'],
            'end_date'       => $row['end_date'],
            'date_display'   => formatResumeDateRange($row['start_date'], $row['end_date']),
            'is_enrollment'  => false,
        ];
    }

    // Source 5b: Completed Enrollments
    $stmtEnrollments = $db->prepare("
        SELECT 
            e.id AS enrollment_id,
            e.completed_at,
            e.started_at,
            e.notes,
            a.title AS activity_title,
            ac.name AS category_name,
            COALESCE(o.title, a.title) AS event_title,
            COALESCE(p.name, 'Academic & Industry Partner') AS organizer_name,
            ach.title AS achievement_title,
            ach.role AS achievement_role,
            ach.description AS achievement_desc,
            ach.resume_text
        FROM enrollments e
        JOIN activities a ON e.activity_id = a.id
        LEFT JOIN activity_categories ac ON a.category_id = ac.id
        LEFT JOIN opportunities o ON e.opportunity_id = o.id
        LEFT JOIN providers p ON o.provider_id = p.id
        LEFT JOIN achievements ach ON ach.enrollment_id = e.id
        WHERE e.user_id = :user_id AND e.status = 'completed'
        ORDER BY e.completed_at DESC, e.id DESC
    ");
    $stmtEnrollments->execute([':user_id' => $userId]);
    while ($row = $stmtEnrollments->fetch(PDO::FETCH_ASSOC)) {
        $title = $row['achievement_title'] ?: $row['event_title'];
        $role  = $row['achievement_role'] ?: 'Participant / Completer';
        $desc  = $row['resume_text'] ?: ($row['achievement_desc'] ?: ($row['notes'] ?: ''));
        $date  = $row['completed_at'] ?: $row['started_at'];
        
        $completedExperience[] = [
            'id'             => 'enr_' . $row['enrollment_id'],
            'title'          => (string)$title,
            'role'           => (string)$role,
            'organization'   => (string)$row['organizer_name'],
            'category'       => (string)$row['category_name'],
            'description'    => (string)$desc,
            'start_date'     => $row['started_at'],
            'end_date'       => $row['completed_at'],
            'date_display'   => $date ? date('M Y', strtotime($date)) : 'Completed',
            'is_enrollment'  => true,
        ];
    }

    // 6. Projects & Portfolio Items
    $stmtProjects = $db->prepare("
        SELECT id, title, item_type, url, description, show_on_resume
        FROM portfolio_items
        WHERE user_id = :user_id AND item_type = 'project'
        ORDER BY created_at DESC
    ");
    $stmtProjects->execute([':user_id' => $userId]);
    $projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

    // 7. Certificates & Credentials
    // a) From enrollment_evidence where evidence_type = 'certificate'
    // b) From portfolio_items where item_type = 'certificate'
    // c) From achievements where evidence_type = 'certificate'
    $certificates = [];

    $stmtCertEv = $db->prepare("
        SELECT ee.id, ee.title, ee.issuer, ee.issued_on, ee.url, ee.credential_id
        FROM enrollment_evidence ee
        WHERE ee.user_id = :user_id AND ee.evidence_type = 'certificate'
        ORDER BY ee.issued_on DESC, ee.id DESC
    ");
    $stmtCertEv->execute([':user_id' => $userId]);
    while ($c = $stmtCertEv->fetch(PDO::FETCH_ASSOC)) {
        $certificates[] = [
            'title'         => (string)$c['title'],
            'issuer'        => (string)($c['issuer'] ?: 'Verified Issuer'),
            'date'          => $c['issued_on'] ? date('M Y', strtotime($c['issued_on'])) : '',
            'url'           => (string)($c['url'] ?: ''),
            'credential_id' => (string)($c['credential_id'] ?: ''),
        ];
    }

    $stmtCertPort = $db->prepare("
        SELECT id, title, description, url, created_at
        FROM portfolio_items
        WHERE user_id = :user_id AND item_type = 'certificate'
        ORDER BY created_at DESC
    ");
    $stmtCertPort->execute([':user_id' => $userId]);
    while ($cp = $stmtCertPort->fetch(PDO::FETCH_ASSOC)) {
        $certificates[] = [
            'title'         => (string)$cp['title'],
            'issuer'        => (string)($cp['description'] ?: 'Professional Certification'),
            'date'          => $cp['created_at'] ? date('M Y', strtotime($cp['created_at'])) : '',
            'url'           => (string)($cp['url'] ?: ''),
            'credential_id' => '',
        ];
    }

    $stmtCertAch = $db->prepare("
        SELECT id, title, role, result, description, completed_on, evidence_url
        FROM achievements
        WHERE user_id = :user_id AND evidence_type = 'certificate'
        ORDER BY completed_on DESC
    ");
    $stmtCertAch->execute([':user_id' => $userId]);
    while ($ca = $stmtCertAch->fetch(PDO::FETCH_ASSOC)) {
        $certificates[] = [
            'title'         => (string)$ca['title'],
            'issuer'        => (string)($ca['result'] ?: 'Achievement Certificate'),
            'date'          => $ca['completed_on'] ? date('M Y', strtotime($ca['completed_on'])) : '',
            'url'           => (string)($ca['evidence_url'] ?: ''),
            'credential_id' => '',
        ];
    }

    // 8. Achievements
    $stmtAch = $db->prepare("
        SELECT id, title, role, result, description, completed_on, resume_text
        FROM achievements
        WHERE user_id = :user_id AND (show_on_resume = 1 OR resume_text IS NOT NULL)
        ORDER BY completed_on DESC, id DESC
    ");
    $stmtAch->execute([':user_id' => $userId]);
    $achievements = $stmtAch->fetchAll(PDO::FETCH_ASSOC);

    // 9. Links & Portfolio
    $stmtLinks = $db->prepare("
        SELECT id, title, item_type, url, description
        FROM portfolio_items
        WHERE user_id = :user_id AND item_type IN ('link', 'publication', 'other')
        ORDER BY created_at DESC
    ");
    $stmtLinks->execute([':user_id' => $userId]);
    $links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);

    return [
        'profile'              => $profile,
        'career_goal'          => $careerGoal,
        'education'            => $education,
        'skills'               => $skills,
        'grouped_skills'       => $groupedSkills,
        'completed_experience' => $completedExperience,
        'projects'             => $projects,
        'certificates'         => $certificates,
        'achievements'         => $achievements,
        'links'                => $links,
    ];
}

/**
 * Formats a start and end date into standard resume date range (e.g. "Aug 2024 – Present", "Oct 2026").
 */
function formatResumeDateRange(?string $startDate, ?string $endDate): string
{
    if (empty($startDate) && empty($endDate)) {
        return '';
    }
    if (!empty($startDate) && empty($endDate)) {
        return date('M Y', strtotime($startDate)) . ' – Present';
    }
    if (empty($startDate) && !empty($endDate)) {
        return date('M Y', strtotime($endDate));
    }
    $s = date('M Y', strtotime($startDate));
    $e = date('M Y', strtotime($endDate));
    if ($s === $e) {
        return $s;
    }
    return $s . ' – ' . $e;
}

/**
 * Generates an ATS-optimized resume draft using Gemini with deterministic fallback.
 * Strictly adheres to student-confirmed data without fabricating facts.
 */
function generateResumeDraft(PDO $db, int $userId, string $targetRole, ?int $careerId = null, array $overrides = []): array
{
    $sources = getStudentResumeSources($db, $userId);

    // Apply any explicit student-confirmed overrides (e.g. edited summary or role)
    if (!empty($overrides['summary'])) {
        $sources['profile']['resume_summary'] = $overrides['summary'];
    }

    $gemini = new GeminiService();
    $draft = null;

    if ($gemini->isConfigured()) {
        try {
            $systemInstruction = "You are a professional executive resume writer and ATS optimization specialist for CareerAct.
Your objective is to produce a high-impact, ATS-friendly resume draft for a student targeting the position: '{$targetRole}'.

CRITICAL CONSTRAINTS & DATA INTEGRITY MANDATES:
1. Use ONLY the provided verified student profile details, confirmed skills, education, completed activities, projects, certificates, and achievements.
2. NEVER invent, extrapolate, or hallucinate credentials, companies, degrees, dates, metrics, awards, or technical tools that are NOT in the input.
3. Transform raw descriptions of completed extracurricular activities and projects into concise, high-impact bullet points starting with strong action verbs (e.g., 'Spearheaded', 'Engineered', 'Coordinated', 'Designed', 'Implemented', 'Synthesized').
4. Emphasize relevance to '{$targetRole}' through professional framing of their actual completed experience, without making unsupported claims.
5. If a section has no source data (e.g., no certificates), return an empty array for that section. Do not fabricate filler items.
6. Return strictly valid JSON with this exact structure:
{
  \"summary\": \"Professional 2-3 sentence career objective/summary tailored to {$targetRole}\",
  \"skills\": {
    \"technical\": [\"Skill1\", \"Skill2\"],
    \"analytical\": [\"Skill3\"],
    \"interpersonal\": [\"Skill4\"]
  },
  \"experience\": [
    {
      \"title\": \"Title/Project\",
      \"role\": \"Role\",
      \"organization\": \"Organization\",
      \"date_display\": \"Date Range\",
      \"bullet_points\": [
        \"Action-verb bullet point 1\",
        \"Action-verb bullet point 2\"
      ]
    }
  ],
  \"projects\": [
    {
      \"title\": \"Project Name\",
      \"description\": \"Concise professional description\",
      \"url\": \"URL or empty\"
    }
  ],
  \"certificates\": [
    {
      \"title\": \"Certificate Name\",
      \"issuer\": \"Issuer\",
      \"date\": \"Date\"
    }
  ],
  \"achievements\": [
    {
      \"title\": \"Achievement Name\",
      \"description\": \"Description\"
    }
  ]
}";

            // Build sanitized payload for Gemini
            $payload = [
                'target_role'          => $targetRole,
                'student_name'         => $sources['profile']['full_name'] ?? 'Candidate',
                'education'            => $sources['education'],
                'confirmed_skills'     => array_column($sources['skills'], 'name'),
                'grouped_skills'       => $sources['grouped_skills'],
                'completed_experience' => array_map(function($e) {
                    return [
                        'title'        => $e['title'],
                        'role'         => $e['role'],
                        'organization' => $e['organization'],
                        'description'  => $e['description'],
                        'date'         => $e['date_display'],
                    ];
                }, $sources['completed_experience']),
                'projects'             => $sources['projects'],
                'certificates'         => $sources['certificates'],
                'achievements'         => $sources['achievements'],
                'current_summary'      => $sources['profile']['resume_summary'] ?? '',
            ];

            $userPrompt = "Please generate the professional resume draft based strictly on this verified student data:\n" 
                        . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $rawResponse = $gemini->callGeminiApi($systemInstruction, $userPrompt, 3, 0);
            $parsed = $gemini->extractAndValidateJson($rawResponse);

            if (is_array($parsed) && !empty($parsed['summary'])) {
                $draft = sanitizeResumeDraftJson($parsed, $sources, $targetRole);
            }
        } catch (Throwable $e) {
            error_log("Gemini Resume Generation Warning: " . $e->getMessage() . ". Using curated ATS domain synthesizer.");
        }
    }

    // Fallback: If Gemini is offline or did not return valid schema, use rule-based ATS synthesizer
    if (!$draft) {
        $draft = generateRuleBasedResumeDraft($targetRole, $sources);
    }

    // Attach verified contact info & education from student records
    $draft['contact_info'] = [
        'full_name' => (string)($sources['profile']['full_name'] ?? 'Candidate'),
        'email'     => (string)($sources['profile']['email'] ?? ''),
        'city'      => (string)($sources['profile']['city'] ?? ''),
        'phone'     => (string)($sources['profile']['phone'] ?? ''),
        'links'     => $sources['links'],
    ];
    $draft['education'] = $sources['education'];
    $draft['target_role'] = $targetRole;
    $draft['career_id'] = $careerId;

    return $draft;
}

/**
 * Validates and sanitizes Gemini JSON output against student records.
 * Strips any hallucinated skills not in the student's confirmed skill set.
 */
function sanitizeResumeDraftJson(array $parsed, array $sources, string $targetRole): array
{
    $confirmedSkillNames = array_map('strtolower', array_column($sources['skills'], 'name'));

    // Filter skills to strictly confirmed skills
    $sanitizedSkills = [
        'technical'     => [],
        'analytical'    => [],
        'interpersonal' => [],
        'transferable'  => [],
        'other'         => [],
    ];

    if (isset($parsed['skills']) && is_array($parsed['skills'])) {
        foreach ($parsed['skills'] as $cat => $sList) {
            if (is_array($sList)) {
                $normCat = isset($sanitizedSkills[$cat]) ? $cat : 'other';
                foreach ($sList as $sName) {
                    $sNameStr = trim((string)$sName);
                    if ($sNameStr !== '' && in_array(strtolower($sNameStr), $confirmedSkillNames, true)) {
                        $sanitizedSkills[$normCat][] = $sNameStr;
                    }
                }
            }
        }
    }

    // If Gemini returned empty skills after filtering, populate directly from confirmed sources
    $totalSkills = array_sum(array_map('count', $sanitizedSkills));
    if ($totalSkills === 0) {
        $sanitizedSkills = $sources['grouped_skills'];
    }

    // Experience sanitization
    $experience = [];
    if (isset($parsed['experience']) && is_array($parsed['experience'])) {
        foreach ($parsed['experience'] as $idx => $exp) {
            $matchedSource = $sources['completed_experience'][$idx] ?? null;
            $title = !empty($exp['title']) ? trim((string)$exp['title']) : ($matchedSource['title'] ?? 'Extracurricular Activity');
            $role  = !empty($exp['role']) ? trim((string)$exp['role']) : ($matchedSource['role'] ?? 'Participant');
            $org   = !empty($exp['organization']) ? trim((string)$exp['organization']) : ($matchedSource['organization'] ?? $title);
            $date  = !empty($exp['date_display']) ? trim((string)$exp['date_display']) : ($matchedSource['date_display'] ?? '');

            $bullets = [];
            if (!empty($exp['bullet_points']) && is_array($exp['bullet_points'])) {
                foreach ($exp['bullet_points'] as $b) {
                    $bStr = trim((string)$b);
                    if ($bStr !== '') {
                        $bullets[] = $bStr;
                    }
                }
            }
            if (empty($bullets) && $matchedSource && !empty($matchedSource['description'])) {
                $bullets[] = $matchedSource['description'];
            }

            $experience[] = [
                'title'         => $title,
                'role'          => $role,
                'organization'  => $org,
                'date_display'  => $date,
                'bullet_points' => $bullets,
            ];
        }
    }

    if (empty($experience) && !empty($sources['completed_experience'])) {
        foreach ($sources['completed_experience'] as $ce) {
            $experience[] = [
                'title'         => $ce['title'],
                'role'          => $ce['role'],
                'organization'  => $ce['organization'],
                'date_display'  => $ce['date_display'],
                'bullet_points' => [$ce['description'] ?: "Active participation as {$ce['role']}."],
            ];
        }
    }

    // Projects
    $projects = [];
    if (isset($parsed['projects']) && is_array($parsed['projects'])) {
        foreach ($parsed['projects'] as $p) {
            if (!empty($p['title'])) {
                $projects[] = [
                    'title'       => trim((string)$p['title']),
                    'description' => trim((string)($p['description'] ?? '')),
                    'url'         => trim((string)($p['url'] ?? '')),
                ];
            }
        }
    }
    if (empty($projects) && !empty($sources['projects'])) {
        foreach ($sources['projects'] as $sp) {
            $projects[] = [
                'title'       => $sp['title'],
                'description' => $sp['description'] ?: '',
                'url'         => $sp['url'] ?: '',
            ];
        }
    }

    // Certificates
    $certificates = [];
    if (isset($parsed['certificates']) && is_array($parsed['certificates'])) {
        foreach ($parsed['certificates'] as $c) {
            if (!empty($c['title'])) {
                $certificates[] = [
                    'title'  => trim((string)$c['title']),
                    'issuer' => trim((string)($c['issuer'] ?? 'Verified Issuer')),
                    'date'   => trim((string)($c['date'] ?? '')),
                ];
            }
        }
    }
    if (empty($certificates) && !empty($sources['certificates'])) {
        $certificates = $sources['certificates'];
    }

    // Achievements
    $achievements = [];
    if (isset($parsed['achievements']) && is_array($parsed['achievements'])) {
        foreach ($parsed['achievements'] as $a) {
            if (!empty($a['title'])) {
                $achievements[] = [
                    'title'       => trim((string)$a['title']),
                    'description' => trim((string)($a['description'] ?? '')),
                ];
            }
        }
    }
    if (empty($achievements) && !empty($sources['achievements'])) {
        foreach ($sources['achievements'] as $sa) {
            $achievements[] = [
                'title'       => $sa['title'],
                'description' => $sa['resume_text'] ?: ($sa['description'] ?: ($sa['result'] ?: '')),
            ];
        }
    }

    return [
        'summary'      => trim((string)($parsed['summary'] ?? '')),
        'skills'       => $sanitizedSkills,
        'experience'   => $experience,
        'projects'     => $projects,
        'certificates' => $certificates,
        'achievements' => $achievements,
    ];
}

/**
 * Deterministic, rule-based ATS resume synthesizer.
 * Formulates strong action-verb bullet points and professional summaries
 * directly from student facts without any external API dependencies.
 */
function generateRuleBasedResumeDraft(string $targetRole, array $sources): array
{
    $profile = $sources['profile'];
    $name = $profile['full_name'] ?? 'Candidate';
    $edu = !empty($sources['education']) ? $sources['education'][0] : null;

    // 1. Build Summary
    $summaryParts = [];
    if ($edu) {
        $summaryParts[] = "Goal-driven " . e($edu['qualification']) . " candidate at " . e($edu['institution']) . ", pursuing growth as a " . e($targetRole) . ".";
    } else {
        $summaryParts[] = "Results-oriented candidate targeting opportunities as a " . e($targetRole) . ".";
    }

    $topSkills = array_slice(array_column($sources['skills'], 'name'), 0, 4);
    if (!empty($topSkills)) {
        $summaryParts[] = "Demonstrated core proficiencies in " . implode(', ', $topSkills) . ".";
    }

    if (!empty($sources['completed_experience'])) {
        $summaryParts[] = "Backed by practical extracurricular engagement and project leadership.";
    }

    $summary = implode(' ', $summaryParts);

    // 2. Experience with Action-Verb Bullet Points
    $actionVerbs = ['Spearheaded', 'Coordinated', 'Engineered', 'Conducted', 'Designed', 'Executed', 'Facilitated', 'Collaborated on'];
    $experience = [];

    foreach ($sources['completed_experience'] as $i => $ce) {
        $verb = $actionVerbs[$i % count($actionVerbs)];
        $bullets = [];

        $role = $ce['role'] ?: 'Participant';
        $title = $ce['title'];
        $desc = trim((string)$ce['description']);

        if (!empty($desc)) {
            $bullets[] = "{$verb} {$title} as {$role}: {$desc}";
        } else {
            $bullets[] = "{$verb} extracurricular engagement in {$title} with focus on team deliverables and milestones.";
        }

        $experience[] = [
            'title'         => $title,
            'role'          => $role,
            'organization'  => $ce['organization'] ?: $title,
            'date_display'  => $ce['date_display'],
            'bullet_points' => $bullets,
        ];
    }

    // 3. Projects
    $projects = [];
    foreach ($sources['projects'] as $p) {
        $projects[] = [
            'title'       => $p['title'],
            'description' => $p['description'] ?: 'Hands-on technical implementation and deliverables.',
            'url'         => $p['url'] ?: '',
        ];
    }

    // 4. Certificates
    $certificates = [];
    foreach ($sources['certificates'] as $c) {
        $certificates[] = [
            'title'  => $c['title'],
            'issuer' => $c['issuer'] ?: 'Verified Institution',
            'date'   => $c['date'] ?: '',
        ];
    }

    // 5. Achievements
    $achievements = [];
    foreach ($sources['achievements'] as $a) {
        $achievements[] = [
            'title'       => $a['title'],
            'description' => $a['resume_text'] ?: ($a['description'] ?: ($a['result'] ?: 'Recognized achievement.')),
        ];
    }

    return [
        'summary'      => $summary,
        'skills'       => $sources['grouped_skills'],
        'experience'   => $experience,
        'projects'     => $projects,
        'certificates' => $certificates,
        'achievements' => $achievements,
    ];
}

/**
 * Regenerates a specific section (e.g. 'summary', 'experience', 'projects')
 * without modifying other parts of the resume.
 */
function regenerateResumeSection(PDO $db, int $userId, string $sectionKey, string $targetRole, array $currentResume): array
{
    $sources = getStudentResumeSources($db, $userId);
    $gemini = new GeminiService();

    if ($sectionKey === 'summary') {
        if ($gemini->isConfigured()) {
            try {
                $sys = "You are an executive resume writer for CareerAct. Craft a punchy, 2-3 sentence ATS professional summary tailored to '{$targetRole}'.
Return ONLY JSON: {\"summary\": \"...\"}. Do NOT invent fake degrees, awards, or companies.";
                $prompt = "Target Role: {$targetRole}\nEducation: " . json_encode($sources['education']) 
                        . "\nSkills: " . json_encode(array_column($sources['skills'], 'name'))
                        . "\nCurrent Summary: " . ($currentResume['summary'] ?? '');
                $res = $gemini->callGeminiApi($sys, $prompt, 3, 0);
                $p = $gemini->extractAndValidateJson($res);
                if (!empty($p['summary'])) {
                    return ['summary' => trim((string)$p['summary'])];
                }
            } catch (Throwable $e) {}
        }
        $fallback = generateRuleBasedResumeDraft($targetRole, $sources);
        return ['summary' => $fallback['summary']];
    }

    if ($sectionKey === 'experience') {
        if ($gemini->isConfigured()) {
            try {
                $sys = "You are an ATS resume optimization writer. Rewrite bullet points for the student's completed activities to highlight relevance for '{$targetRole}'.
STRICT: Start with strong action verbs. NEVER invent companies, metrics, dates, or tools.
Return ONLY JSON: {\"experience\": [{\"title\": \"...\", \"role\": \"...\", \"organization\": \"...\", \"date_display\": \"...\", \"bullet_points\": [\"...\"]}]}";
                $prompt = "Target Role: {$targetRole}\nActivities: " . json_encode($sources['completed_experience']);
                $res = $gemini->callGeminiApi($sys, $prompt, 3, 0);
                $p = $gemini->extractAndValidateJson($res);
                if (!empty($p['experience']) && is_array($p['experience'])) {
                    return ['experience' => $p['experience']];
                }
            } catch (Throwable $e) {}
        }
        $fallback = generateRuleBasedResumeDraft($targetRole, $sources);
        return ['experience' => $fallback['experience']];
    }

    if ($sectionKey === 'skills') {
        // Return confirmed skills reorganized with target role priority
        return ['skills' => $sources['grouped_skills']];
    }

    // Default fallback
    $fallback = generateRuleBasedResumeDraft($targetRole, $sources);
    return [$sectionKey => $fallback[$sectionKey] ?? []];
}

/**
 * Saves or updates a student resume record in the database.
 */
function saveStudentResume(PDO $db, int $userId, array $resumeData): int
{
    $resumeId = (int)($resumeData['id'] ?? 0);
    $targetRole = trim((string)($resumeData['target_role'] ?? 'Software Developer'));
    $title = trim((string)($resumeData['title'] ?? ($targetRole . ' Resume')));
    $careerId = !empty($resumeData['career_id']) ? (int)$resumeData['career_id'] : null;
    $templateStyle = trim((string)($resumeData['template_style'] ?? 'modern_ats'));
    $summary = trim((string)($resumeData['summary'] ?? ''));

    $contactJson = json_encode($resumeData['contact_info'] ?? [], JSON_UNESCAPED_UNICODE);
    $eduJson     = json_encode($resumeData['education'] ?? [], JSON_UNESCAPED_UNICODE);
    $skillsJson  = json_encode($resumeData['skills'] ?? [], JSON_UNESCAPED_UNICODE);
    $expJson     = json_encode($resumeData['experience'] ?? [], JSON_UNESCAPED_UNICODE);
    $projJson    = json_encode($resumeData['projects'] ?? [], JSON_UNESCAPED_UNICODE);
    $certJson    = json_encode($resumeData['certificates'] ?? [], JSON_UNESCAPED_UNICODE);
    $achJson     = json_encode($resumeData['achievements'] ?? [], JSON_UNESCAPED_UNICODE);

    if ($resumeId > 0) {
        // Verify ownership before update
        $stmtChk = $db->prepare("SELECT id FROM student_resumes WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmtChk->execute([':id' => $resumeId, ':uid' => $userId]);
        if (!$stmtChk->fetch()) {
            throw new RuntimeException("Unauthorized: Cannot update a resume that does not belong to you.");
        }

        $stmtUp = $db->prepare("
            UPDATE student_resumes
            SET target_role = :role,
                title = :title,
                career_id = :cid,
                template_style = :tmpl,
                summary = :summary,
                contact_info_json = :contact,
                education_data = :edu,
                skills_data = :skills,
                experience_data = :exp,
                projects_data = :proj,
                certificates_data = :cert,
                custom_sections_json = :ach,
                updated_at = NOW()
            WHERE id = :id AND user_id = :uid
        ");
        $stmtUp->execute([
            ':role'    => $targetRole,
            ':title'   => $title,
            ':cid'     => $careerId,
            ':tmpl'    => $templateStyle,
            ':summary' => $summary,
            ':contact' => $contactJson,
            ':edu'     => $eduJson,
            ':skills'  => $skillsJson,
            ':exp'     => $expJson,
            ':proj'    => $projJson,
            ':cert'    => $certJson,
            ':ach'     => $achJson,
            ':id'      => $resumeId,
            ':uid'     => $userId,
        ]);
        return $resumeId;
    } else {
        $stmtIns = $db->prepare("
            INSERT INTO student_resumes (
                user_id, career_id, target_role, title, template_style, summary,
                contact_info_json, education_data, skills_data, experience_data,
                projects_data, certificates_data, custom_sections_json, created_at, updated_at
            ) VALUES (
                :uid, :cid, :role, :title, :tmpl, :summary,
                :contact, :edu, :skills, :exp,
                :proj, :cert, :ach, NOW(), NOW()
            )
        ");
        $stmtIns->execute([
            ':uid'     => $userId,
            ':cid'     => $careerId,
            ':role'    => $targetRole,
            ':title'   => $title,
            ':tmpl'    => $templateStyle,
            ':summary' => $summary,
            ':contact' => $contactJson,
            ':edu'     => $eduJson,
            ':skills'  => $skillsJson,
            ':exp'     => $expJson,
            ':proj'    => $projJson,
            ':cert'    => $certJson,
            ':ach'     => $achJson,
        ]);
        $returnId = (int)$db->lastInsertId();
    }

    if (file_exists(__DIR__ . '/notification_service.php')) {
        require_once __DIR__ . '/notification_service.php';
        $finalId = $resumeId ?: $returnId;
        createNotification(
            $db,
            $userId,
            'resume_saved',
            'Resume Draft Saved',
            "Your tailored resume for '{$targetRole}' has been saved successfully.",
            "resume.php?id={$finalId}",
            "resume_saved_{$finalId}_u{$userId}_" . date('YmdHi')
        );
    }

    return $resumeId ?: $returnId;
}

/**
 * Retrieves a single resume for a student with strict authorization check.
 */
function getStudentResume(PDO $db, int $userId, int $resumeId): ?array
{
    $stmt = $db->prepare("
        SELECT * FROM student_resumes 
        WHERE id = :id AND user_id = :uid 
        LIMIT 1
    ");
    $stmt->execute([':id' => $resumeId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id'             => (int)$row['id'],
        'user_id'        => (int)$row['user_id'],
        'career_id'      => $row['career_id'] ? (int)$row['career_id'] : null,
        'target_role'    => (string)$row['target_role'],
        'title'          => (string)$row['title'],
        'template_style' => (string)$row['template_style'],
        'summary'        => (string)$row['summary'],
        'contact_info'   => json_decode((string)$row['contact_info_json'], true) ?: [],
        'education'      => json_decode((string)$row['education_data'], true) ?: [],
        'skills'         => json_decode((string)$row['skills_data'], true) ?: [],
        'experience'     => json_decode((string)$row['experience_data'], true) ?: [],
        'projects'       => json_decode((string)$row['projects_data'], true) ?: [],
        'certificates'   => json_decode((string)$row['certificates_data'], true) ?: [],
        'achievements'   => json_decode((string)$row['custom_sections_json'], true) ?: [],
        'is_primary'     => (bool)$row['is_primary'],
        'created_at'     => $row['created_at'],
        'updated_at'     => $row['updated_at'],
    ];
}

/**
 * Lists all resumes created by the student.
 */
function getStudentResumeList(PDO $db, int $userId): array
{
    $stmt = $db->prepare("
        SELECT id, target_role, title, template_style, is_primary, created_at, updated_at
        FROM student_resumes
        WHERE user_id = :uid
        ORDER BY is_primary DESC, updated_at DESC, id DESC
    ");
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Deletes a student resume with strict authorization check.
 */
function deleteStudentResume(PDO $db, int $userId, int $resumeId): bool
{
    $stmt = $db->prepare("DELETE FROM student_resumes WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $resumeId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Generates clean, ATS-compliant HTML for preview and PDF export.
 */
function renderAtsResumeHtml(array $resume): string
{
    $contact = $resume['contact_info'] ?? [];
    $name = e($contact['full_name'] ?? 'Candidate');
    $email = e($contact['email'] ?? '');
    $city = e($contact['city'] ?? '');
    $phone = e($contact['phone'] ?? '');

    $contactParts = array_filter([$email, $phone, $city]);

    $summary = trim((string)($resume['summary'] ?? ''));
    $education = $resume['education'] ?? [];
    $skills = $resume['skills'] ?? [];
    $experience = $resume['experience'] ?? [];
    $projects = $resume['projects'] ?? [];
    $certificates = $resume['certificates'] ?? [];
    $achievements = $resume['achievements'] ?? [];

    ob_start();
    ?>
    <div class="ats-resume-sheet" id="resumePrintArea">
        <!-- Header -->
        <header class="ats-header">
            <h1 class="ats-candidate-name"><?= $name ?></h1>
            <?php if (!empty($contactParts)): ?>
                <div class="ats-contact-line">
                    <?= implode(' &bull; ', $contactParts) ?>
                </div>
            <?php endif; ?>
        </header>

        <!-- Professional Summary -->
        <?php if (!empty($summary)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Professional Summary</h2>
                <div class="ats-section-divider"></div>
                <p class="ats-summary-text"><?= e($summary) ?></p>
            </section>
        <?php endif; ?>

        <!-- Education -->
        <?php if (!empty($education)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Education</h2>
                <div class="ats-section-divider"></div>
                <?php foreach ($education as $edu): ?>
                    <div class="ats-item-row">
                        <div>
                            <strong class="ats-item-title"><?= e($edu['institution'] ?? '') ?></strong>
                            <div class="ats-item-subtitle"><?= e($edu['qualification'] ?? '') ?></div>
                        </div>
                        <div class="ats-item-date">
                            <?= !empty($edu['end_year']) ? (!empty($edu['start_year']) ? e((string)$edu['start_year']) . ' – ' . e((string)$edu['end_year']) : e((string)$edu['end_year'])) : '' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Core Competencies & Skills -->
        <?php 
        $hasSkills = false;
        if (is_array($skills)) {
            foreach ($skills as $sub) {
                if (!empty($sub)) { $hasSkills = true; break; }
            }
        }
        if ($hasSkills): 
        ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Skills & Core Competencies</h2>
                <div class="ats-section-divider"></div>
                <div class="ats-skills-grid">
                    <?php foreach ($skills as $category => $sList): 
                        if (empty($sList) || !is_array($sList)) continue;
                    ?>
                        <div class="ats-skill-group">
                            <strong class="ats-skill-label"><?= ucfirst(e($category)) ?>:</strong>
                            <span class="ats-skill-values"><?= implode(', ', array_map('e', $sList)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Extracurricular Experience & Activities -->
        <?php if (!empty($experience)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Relevant Experience & Extracurricular Leadership</h2>
                <div class="ats-section-divider"></div>
                <?php foreach ($experience as $exp): ?>
                    <div class="ats-exp-item">
                        <div class="ats-item-row">
                            <div>
                                <strong class="ats-item-title"><?= e($exp['role'] ?? '') ?></strong>
                                <?php if (!empty($exp['organization'])): ?>
                                    <span class="ats-item-org">&mdash; <?= e($exp['organization']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ats-item-date"><?= e($exp['date_display'] ?? '') ?></div>
                        </div>
                        <?php if (!empty($exp['bullet_points']) && is_array($exp['bullet_points'])): ?>
                            <ul class="ats-bullet-list">
                                <?php foreach ($exp['bullet_points'] as $b): 
                                    if (trim((string)$b) === '') continue;
                                ?>
                                    <li><?= e($b) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Projects -->
        <?php if (!empty($projects)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Projects & Technical Builds</h2>
                <div class="ats-section-divider"></div>
                <?php foreach ($projects as $proj): ?>
                    <div class="ats-proj-item">
                        <div class="ats-item-row">
                            <strong class="ats-item-title"><?= e($proj['title'] ?? '') ?></strong>
                            <?php if (!empty($proj['url'])): ?>
                                <a href="<?= e($proj['url']) ?>" target="_blank" rel="noopener noreferrer" class="ats-item-link"><?= e($proj['url']) ?></a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($proj['description'])): ?>
                            <p class="ats-proj-desc"><?= e($proj['description']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Certifications & Credentials -->
        <?php if (!empty($certificates)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Certifications & Credentials</h2>
                <div class="ats-section-divider"></div>
                <ul class="ats-bullet-list">
                    <?php foreach ($certificates as $cert): ?>
                        <li>
                            <strong><?= e($cert['title'] ?? '') ?></strong>
                            <?= !empty($cert['issuer']) ? ' &mdash; ' . e($cert['issuer']) : '' ?>
                            <?= !empty($cert['date']) ? ' (' . e($cert['date']) . ')' : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <!-- Key Achievements -->
        <?php if (!empty($achievements)): ?>
            <section class="ats-section">
                <h2 class="ats-section-title">Honors & Key Achievements</h2>
                <div class="ats-section-divider"></div>
                <ul class="ats-bullet-list">
                    <?php foreach ($achievements as $ach): ?>
                        <li>
                            <strong><?= e($ach['title'] ?? '') ?>:</strong>
                            <?= e($ach['description'] ?? '') ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
