<?php
/**
 * CareerAct — Student Portfolio & Achievement Showcase Service
 * Module 12: Professional Portfolio Showcase with Selective Privacy Controls
 * 
 * Strict Data Integrity & Privacy:
 * - Sourced strictly from verified education, confirmed skills, and COMPLETED activities.
 * - Saved, registered, in-progress, or recommended activities are strictly excluded.
 * - Private-by-default with configurable public sharing via secure tokens.
 * - Granular section and item visibility toggles.
 * - Zero exposure of internal IDs, private assessment attempts, or account passwords.
 * - Gemini AI writing enhancement with draft review before acceptance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/resume_service.php';

/**
 * Retrieves or initializes portfolio settings for a student (private by default).
 */
function getStudentPortfolioSettings(PDO $db, int $userId): array
{
    $stmt = $db->prepare("SELECT * FROM student_portfolios WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'id'                   => (int)$row['id'],
            'user_id'              => (int)$row['user_id'],
            'share_token'          => (string)$row['share_token'],
            'is_public'            => (bool)$row['is_public'],
            'headline'             => (string)($row['headline'] ?? ''),
            'bio'                  => (string)($row['bio'] ?? ''),
            'display_email'        => (bool)$row['display_email'],
            'display_phone'        => (bool)$row['display_phone'],
            'linkedin_url'         => (string)($row['linkedin_url'] ?? ''),
            'github_url'           => (string)($row['github_url'] ?? ''),
            'website_url'          => (string)($row['website_url'] ?? ''),
            'theme_style'          => (string)($row['theme_style'] ?? 'modern_slate'),
            'visible_sections'     => json_decode((string)($row['visible_sections_json'] ?? '[]'), true) ?: [
                'summary' => true, 'education' => true, 'skills' => true,
                'activities' => true, 'projects' => true, 'certificates' => true, 'achievements' => true
            ],
            'item_visibility'      => json_decode((string)($row['item_visibility_json'] ?? '{}'), true) ?: [],
            'custom_descriptions' => json_decode((string)($row['custom_descriptions_json'] ?? '{}'), true) ?: [],
            'created_at'           => $row['created_at'],
            'updated_at'           => $row['updated_at'],
        ];
    }

    // Initialize private-by-default portfolio
    $shareToken = bin2hex(random_bytes(16));
    $stmtIns = $db->prepare("
        INSERT INTO student_portfolios (
            user_id, share_token, is_public, display_email, display_phone,
            theme_style, visible_sections_json, item_visibility_json, custom_descriptions_json,
            created_at, updated_at
        ) VALUES (
            :uid, :token, 0, 0, 0,
            'modern_slate', :sections, '{}', '{}', NOW(), NOW()
        )
    ");
    $defaultSections = json_encode([
        'summary' => true, 'education' => true, 'skills' => true,
        'activities' => true, 'projects' => true, 'certificates' => true, 'achievements' => true
    ]);
    $stmtIns->execute([
        ':uid'      => $userId,
        ':token'    => $shareToken,
        ':sections' => $defaultSections,
    ]);

    return getStudentPortfolioSettings($db, $userId);
}

/**
 * Saves or updates portfolio settings for a student.
 */
function updateStudentPortfolioSettings(PDO $db, int $userId, array $data): bool
{
    $settings = getStudentPortfolioSettings($db, $userId);

    $isPublic     = !empty($data['is_public']) ? 1 : 0;
    $headline     = trim((string)($data['headline'] ?? $settings['headline']));
    $bio          = trim((string)($data['bio'] ?? $settings['bio']));
    $displayEmail = !empty($data['display_email']) ? 1 : 0;
    $displayPhone = !empty($data['display_phone']) ? 1 : 0;
    $linkedin     = trim((string)($data['linkedin_url'] ?? $settings['linkedin_url']));
    $github       = trim((string)($data['github_url'] ?? $settings['github_url']));
    $website      = trim((string)($data['website_url'] ?? $settings['website_url']));
    $theme        = trim((string)($data['theme_style'] ?? $settings['theme_style']));

    $sections = isset($data['visible_sections']) ? json_encode($data['visible_sections']) : json_encode($settings['visible_sections']);
    $itemVis  = isset($data['item_visibility']) ? json_encode($data['item_visibility']) : json_encode($settings['item_visibility']);
    $custDesc = isset($data['custom_descriptions']) ? json_encode($data['custom_descriptions']) : json_encode($settings['custom_descriptions']);

    $stmt = $db->prepare("
        UPDATE student_portfolios
        SET is_public = :is_pub,
            headline = :headline,
            bio = :bio,
            display_email = :disp_email,
            display_phone = :disp_phone,
            linkedin_url = :linkedin,
            github_url = :github,
            website_url = :website,
            theme_style = :theme,
            visible_sections_json = :sections,
            item_visibility_json = :item_vis,
            custom_descriptions_json = :cust_desc,
            updated_at = NOW()
        WHERE user_id = :uid
    ");

    $res = $stmt->execute([
        ':is_pub'     => $isPublic,
        ':headline'   => $headline,
        ':bio'        => $bio,
        ':disp_email' => $displayEmail,
        ':disp_phone' => $displayPhone,
        ':linkedin'   => $linkedin,
        ':github'     => $github,
        ':website'    => $website,
        ':theme'      => $theme,
        ':sections'   => $sections,
        ':item_vis'   => $itemVis,
        ':cust_desc'  => $custDesc,
        ':uid'        => $userId,
    ]);

    if ($res && isset($settings['is_public']) && (int)$settings['is_public'] !== $isPublic) {
        if (file_exists(__DIR__ . '/notification_service.php')) {
            require_once __DIR__ . '/notification_service.php';
            $statusText = $isPublic ? 'enabled (Public)' : 'disabled (Private)';
            createNotification(
                $db,
                $userId,
                'portfolio_shared',
                'Portfolio Visibility Updated',
                "Your public portfolio showcase visibility has been {$statusText}.",
                'portfolio.php',
                "portfolio_vis_{$isPublic}_u{$userId}_" . date('YmdHi')
            );
        }
    }

    return $res;
}

/**
 * Regenerates the share token, invalidating any old links.
 */
function regeneratePortfolioShareToken(PDO $db, int $userId): string
{
    $newToken = bin2hex(random_bytes(16));
    $stmt = $db->prepare("UPDATE student_portfolios SET share_token = :token, updated_at = NOW() WHERE user_id = :uid");
    $stmt->execute([':token' => $newToken, ':uid' => $userId]);
    return $newToken;
}

/**
 * Retrieves full portfolio content for display, applying privacy filters when in public mode.
 */
function getFullPortfolioData(PDO $db, int $userId, bool $publicView = false): ?array
{
    $settings = getStudentPortfolioSettings($db, $userId);

    // If accessed via public view, verify it is published
    if ($publicView && !$settings['is_public']) {
        return null;
    }

    // Retrieve verified data sources from resume service (already strictly filters completed activities)
    $sources = getStudentResumeSources($db, $userId);

    $visibleSections = $settings['visible_sections'];
    $itemVisibility  = $settings['item_visibility'];
    $customDesc      = $settings['custom_descriptions'];

    // 1. Candidate Info
    $candidate = [
        'full_name'    => $sources['profile']['full_name'] ?? 'Candidate',
        'city'         => $sources['profile']['city'] ?? '',
        'headline'     => $settings['headline'] ?: ($sources['career_goal']['career_title'] ? "Aspiring " . $sources['career_goal']['career_title'] : 'Student & Extracurricular Innovator'),
        'bio'          => $settings['bio'] ?: ($sources['profile']['bio'] ?: ($sources['profile']['resume_summary'] ?: '')),
        'career_goal'  => $sources['career_goal']['career_title'] ?? '',
        'email'        => (!$publicView || $settings['display_email']) ? ($sources['profile']['email'] ?? '') : '',
        'phone'        => (!$publicView || $settings['display_phone']) ? ($sources['profile']['phone'] ?? '') : '',
        'linkedin_url' => $settings['linkedin_url'],
        'github_url'   => $settings['github_url'],
        'website_url'  => $settings['website_url'],
    ];

    // 2. Education Section
    $education = [];
    if (!$publicView || !empty($visibleSections['education'])) {
        foreach ($sources['education'] as $ed) {
            $itemKey = 'edu_' . ($ed['id'] ?? 0);
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $education[] = [
                'item_key'      => $itemKey,
                'qualification' => $ed['qualification'],
                'institution'   => $ed['institution'],
                'start_year'    => $ed['start_year'],
                'end_year'      => $ed['end_year'],
                'date_display'  => !empty($ed['end_year']) ? (!empty($ed['start_year']) ? "{$ed['start_year']} – {$ed['end_year']}" : (string)$ed['end_year']) : '',
            ];
        }
    }

    // 3. Confirmed Skills Section
    $skills = [];
    if (!$publicView || !empty($visibleSections['skills'])) {
        foreach ($sources['skills'] as $sk) {
            $itemKey = 'sk_' . $sk['skill_id'];
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $skills[] = [
                'item_key'    => $itemKey,
                'name'        => $sk['name'],
                'skill_type'  => $sk['skill_type'],
                'level'       => $sk['level'],
                'source'      => $sk['source'],
                'is_assessed' => ($sk['source'] === 'assessed'),
            ];
        }
    }

    // 4. Completed Activities & Practical Experience
    // Strictly verified completed activities only
    $activities = [];
    if (!$publicView || !empty($visibleSections['activities'])) {
        foreach ($sources['completed_experience'] as $exp) {
            $itemKey = $exp['id'];
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $desc = $customDesc[$itemKey] ?? $exp['description'];
            $activities[] = [
                'item_key'      => $itemKey,
                'title'         => $exp['title'],
                'role'          => $exp['role'],
                'organization'  => $exp['organization'],
                'category'      => $exp['category'],
                'description'   => $desc,
                'date_display'  => $exp['date_display'],
                'is_verified'   => true,
                'is_enrollment' => $exp['is_enrollment'],
            ];
        }
    }

    // 5. Projects
    $projects = [];
    if (!$publicView || !empty($visibleSections['projects'])) {
        foreach ($sources['projects'] as $pj) {
            $itemKey = 'proj_' . $pj['id'];
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $desc = $customDesc[$itemKey] ?? ($pj['description'] ?? '');
            $projects[] = [
                'item_key'    => $itemKey,
                'title'       => $pj['title'],
                'description' => $desc,
                'url'         => $pj['url'] ?? '',
            ];
        }
    }

    // 6. Certificates
    $certificates = [];
    if (!$publicView || !empty($visibleSections['certificates'])) {
        foreach ($sources['certificates'] as $ci => $cert) {
            $itemKey = 'cert_' . $ci;
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $certificates[] = [
                'item_key'      => $itemKey,
                'title'         => $cert['title'],
                'issuer'        => $cert['issuer'],
                'date'          => $cert['date'],
                'url'           => $cert['url'] ?? '',
                'credential_id' => $cert['credential_id'] ?? '',
            ];
        }
    }

    // 7. Achievements
    $achievements = [];
    if (!$publicView || !empty($visibleSections['achievements'])) {
        foreach ($sources['achievements'] as $ai => $ach) {
            $itemKey = 'ach_' . ($ach['id'] ?? $ai);
            if ($publicView && isset($itemVisibility[$itemKey]) && !$itemVisibility[$itemKey]) {
                continue;
            }
            $desc = $customDesc[$itemKey] ?? ($ach['resume_text'] ?: ($ach['description'] ?: ($ach['result'] ?: '')));
            $achievements[] = [
                'item_key'    => $itemKey,
                'title'       => $ach['title'],
                'role'        => $ach['role'] ?? '',
                'description' => $desc,
                'date'        => !empty($ach['completed_on']) ? date('M Y', strtotime($ach['completed_on'])) : '',
            ];
        }
    }

    return [
        'settings'     => $settings,
        'candidate'    => $candidate,
        'education'    => $education,
        'skills'       => $skills,
        'activities'   => $activities,
        'projects'     => $projects,
        'certificates' => $certificates,
        'achievements' => $achievements,
        'public_view'  => $publicView,
    ];
}

/**
 * Uses Gemini AI to draft an engaging professional portfolio bio.
 * Returns draft text for student review without automatically overwriting.
 */
function generatePortfolioBioDraft(PDO $db, int $userId): string
{
    $sources = getStudentResumeSources($db, $userId);
    $gemini = new GeminiService();

    $careerGoal = $sources['career_goal']['career_title'] ?? 'Software Developer';
    $skillsList = array_slice(array_column($sources['skills'], 'name'), 0, 5);
    $actsList   = array_slice(array_column($sources['completed_experience'], 'title'), 0, 3);
    $edu        = $sources['education'][0]['qualification'] ?? 'Undergraduate Student';

    if ($gemini->isConfigured()) {
        try {
            $sys = "You are an expert career branding advisor for CareerAct.
Craft a professional, compelling, 3-4 sentence portfolio biography for a student targeting a career in '{$careerGoal}'.
STRICT CONSTRAINTS:
1. Base the bio strictly on the provided facts: qualification, confirmed skills, and completed activities.
2. NEVER invent achievements, degrees, or companies.
3. Return ONLY the plain text biography paragraph.";

            $prompt = "Student: {$sources['profile']['full_name']}\nEducation: {$edu}\nConfirmed Skills: " . implode(', ', $skillsList) . "\nCompleted Activities: " . implode(', ', $actsList) . "\nTarget Career: {$careerGoal}";
            $raw = $gemini->callGeminiApi($sys, $prompt, 3, 0);
            $clean = trim(strip_tags($raw));
            if (strlen($clean) > 30) {
                return $clean;
            }
        } catch (Throwable $e) {}
    }

    // High quality deterministic fallback
    $p = [];
    $p[] = "I am a passionate {$edu} targeting growth as a {$careerGoal}.";
    if (!empty($skillsList)) {
        $p[] = "Equipped with verified competencies in " . implode(', ', $skillsList) . ".";
    }
    if (!empty($actsList)) {
        $p[] = "Demonstrated practical commitment through participation in " . implode(' and ', $actsList) . ".";
    }
    $p[] = "Driven to solve challenging problems and build impactful digital solutions.";
    return implode(' ', $p);
}

/**
 * Uses Gemini AI to polish a project or activity description.
 */
function improvePortfolioDescriptionDraft(string $title, string $rawDesc, string $careerGoal): string
{
    $gemini = new GeminiService();
    if ($gemini->isConfigured() && strlen(trim($rawDesc)) > 5) {
        try {
            $sys = "You are a professional career portfolio editor.
Enhance the following student-provided project/activity description to make it clearer, action-oriented, and impactful for a career in '{$careerGoal}'.
STRICT: Do NOT fabricate fake metrics, companies, or outcomes not mentioned. Keep it concise (1-2 sentences). Return ONLY the enhanced text.";

            $raw = $gemini->callGeminiApi($sys, "Title: {$title}\nDescription: {$rawDesc}", 3, 0);
            $clean = trim(strip_tags($raw));
            if (strlen($clean) > 10) {
                return $clean;
            }
        } catch (Throwable $e) {}
    }

    return trim($rawDesc);
}

