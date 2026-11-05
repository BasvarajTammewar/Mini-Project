<?php
/**
 * CareerAct — Module 12: Public Student Portfolio Showcase
 * 
 * Secure, public-facing portfolio view accessible via unique share token.
 * Only displays student-approved sections and items when public sharing is enabled.
 * Strictly hides personal phone/email unless explicitly opted-in by the student.
 * Never exposes internal database IDs or private assessment details.
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Cache-Control: public, max-age=60');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/portfolio_service.php';

$db = getDbConnection();
$shareToken = trim((string)($_GET['share'] ?? ''));

$portfolio = null;
$isAuthorized = false;

if ($shareToken !== '') {
    $stmt = $db->prepare("SELECT user_id, is_public FROM student_portfolios WHERE share_token = :token LIMIT 1");
    $stmt->execute([':token' => $shareToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['is_public'])) {
        $userId = (int)$row['user_id'];
        $portfolio = getFullPortfolioData($db, $userId, true);
        $isAuthorized = ($portfolio !== null);
    }
}

if (!$isAuthorized || !$portfolio):
    http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Unavailable — CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body { background: #F8FAFC; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
        .private-card { background: #FFF; border: 1px solid #E2E8F0; border-radius: 16px; padding: 48px 32px; max-width: 480px; text-align: center; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
    </style>
</head>
<body>
    <div class="private-card">
        <div style="width: 56px; height: 56px; background: #F1F5F9; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        </div>
        <h1 style="font-size: 20px; font-weight: 800; color: #0F172A; margin: 0 0 8px;">Portfolio is Private</h1>
        <p style="font-size: 13.5px; color: #64748B; line-height: 1.5; margin: 0 0 24px;">
            This student portfolio is currently private or the share link has been revoked by the owner.
        </p>
        <a href="login.php" style="display: inline-block; background: #1769E8; color: #FFF; font-weight: 700; font-size: 13px; text-decoration: none; padding: 10px 22px; border-radius: 8px;">
            Go to CareerAct Portal
        </a>
    </div>
</body>
</html>
<?php
exit;
endif;

$cand = $portfolio['candidate'];
$candName = e($cand['full_name']);
$initials = strtoupper(substr($candName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $candName ?> — Verified Student Portfolio | CareerAct</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body {
            background: #F8FAFC;
            color: #0F172A;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }
        .public-showcase-container {
            max-width: 900px;
            margin: 40px auto 60px;
            padding: 0 20px;
        }
        .showcase-header-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 36px 32px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.03);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        .sh-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .sh-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #EEF4FF;
            color: #1769E8;
            font-size: 26px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #C7D9FB;
        }
        .sh-name {
            font-size: 24px;
            font-weight: 800;
            color: #0F172A;
            margin: 0 0 4px;
        }
        .sh-headline {
            font-size: 14.5px;
            color: #475569;
            margin: 0 0 6px;
        }
        .sh-meta-row {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12.5px;
            color: #64748B;
            flex-wrap: wrap;
        }
        .badge-verified {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .sh-links {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .sh-social-btn {
            background: #F1F5F9;
            color: #1E293B;
            border: 1px solid #CBD5E1;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .sh-social-btn:hover {
            background: #E2E8F0;
        }

        .showcase-section-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 28px 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            margin-bottom: 20px;
        }
        .sec-title {
            font-size: 14px;
            font-weight: 800;
            color: #1769E8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0 0 16px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #F1F5F9;
        }

        .skills-chips-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .skill-chip {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12.5px;
            color: #1E293B;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .skill-chip.assessed {
            background: #EEF4FF;
            border-color: #C7D9FB;
            color: #1769E8;
            font-weight: 700;
        }

        .item-card {
            border: 1px solid #F1F5F9;
            background: #FAFCFF;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 12px;
        }
        .item-card:last-child {
            margin-bottom: 0;
        }
        .item-top {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 6px;
        }
        .item-name {
            font-size: 15px;
            font-weight: 700;
            color: #0F172A;
            margin: 0;
        }
        .item-date {
            font-size: 12px;
            color: #64748B;
            font-weight: 600;
            white-space: nowrap;
        }
        .item-sub {
            font-size: 12.5px;
            color: #475569;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .item-desc {
            font-size: 13px;
            color: #475569;
            margin: 0;
            line-height: 1.45;
        }

        .showcase-footer {
            text-align: center;
            padding: 24px 0;
            font-size: 12.5px;
            color: #94A3B8;
        }
        .showcase-footer a {
            color: #1769E8;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>

    <div class="public-showcase-container">

        <!-- Header Card -->
        <header class="showcase-header-card">
            <div class="sh-left">
                <div class="sh-avatar"><?= e($initials) ?></div>
                <div>
                    <h1 class="sh-name"><?= $candName ?></h1>
                    <p class="sh-headline"><?= e($cand['headline']) ?></p>
                    <div class="sh-meta-row">
                        <?php if (!empty($cand['city'])): ?>
                            <span>📍 <?= e($cand['city']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($cand['career_goal'])): ?>
                            <span>🎯 Target: <strong><?= e($cand['career_goal']) ?></strong></span>
                        <?php endif; ?>
                        <span class="badge-verified">✓ Verified Institutional Artifacts</span>
                    </div>
                </div>
            </div>

            <div class="sh-links">
                <?php if (!empty($cand['github_url'])): ?>
                    <a href="<?= e($cand['github_url']) ?>" target="_blank" rel="noopener noreferrer" class="sh-social-btn">GitHub &#8599;</a>
                <?php endif; ?>
                <?php if (!empty($cand['linkedin_url'])): ?>
                    <a href="<?= e($cand['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer" class="sh-social-btn">LinkedIn &#8599;</a>
                <?php endif; ?>
                <?php if (!empty($cand['website_url'])): ?>
                    <a href="<?= e($cand['website_url']) ?>" target="_blank" rel="noopener noreferrer" class="sh-social-btn">Portfolio Site &#8599;</a>
                <?php endif; ?>
                <?php if (!empty($cand['email'])): ?>
                    <a href="mailto:<?= e($cand['email']) ?>" class="sh-social-btn">Email Contact</a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Professional Biography -->
        <?php if (!empty($cand['bio'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">About & Career Focus</h2>
                <p style="font-size: 14px; color: #334155; line-height: 1.6; margin: 0; text-align: justify;">
                    <?= nl2br(e($cand['bio'])) ?>
                </p>
            </section>
        <?php endif; ?>

        <!-- Education -->
        <?php if (!empty($portfolio['education'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Education & Academic Background</h2>
                <?php foreach ($portfolio['education'] as $edu): ?>
                    <div class="item-card">
                        <div class="item-top">
                            <strong class="item-name"><?= e($edu['institution']) ?></strong>
                            <span class="item-date"><?= e($edu['date_display']) ?></span>
                        </div>
                        <div class="item-sub"><?= e($edu['qualification']) ?></div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Confirmed Skills -->
        <?php if (!empty($portfolio['skills'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Confirmed Competencies & Skills</h2>
                <div class="skills-chips-wrap">
                    <?php foreach ($portfolio['skills'] as $sk): ?>
                        <span class="skill-chip <?= $sk['is_assessed'] ? 'assessed' : '' ?>" title="<?= $sk['is_assessed'] ? 'Standardized Assessment Verified' : 'Self-Confirmed' ?>">
                            <?= e($sk['name']) ?>
                            <?php if ($sk['is_assessed']): ?>
                                <span style="font-size: 10px; background: #C7D9FB; color: #1255C0; padding: 1px 5px; border-radius: 4px;">Assessed Level <?= (int)$sk['level'] ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Completed Extracurricular Activities -->
        <?php if (!empty($portfolio['activities'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Completed Extracurricular Activities & Leadership</h2>
                <?php foreach ($portfolio['activities'] as $act): ?>
                    <div class="item-card">
                        <div class="item-top">
                            <h3 class="item-name"><?= e($act['title']) ?></h3>
                            <span class="item-date"><?= e($act['date_display']) ?></span>
                        </div>
                        <div class="item-sub">Role: <strong><?= e($act['role']) ?></strong> &bull; <?= e($act['organization']) ?></div>
                        <?php if (!empty($act['description'])): ?>
                            <p class="item-desc"><?= nl2br(e($act['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Projects -->
        <?php if (!empty($portfolio['projects'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Technical Projects & Builds</h2>
                <?php foreach ($portfolio['projects'] as $pj): ?>
                    <div class="item-card">
                        <div class="item-top">
                            <h3 class="item-name"><?= e($pj['title']) ?></h3>
                            <?php if (!empty($pj['url'])): ?>
                                <a href="<?= e($pj['url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; color: #1769E8; font-weight: 700; text-decoration: underline;">
                                    View Repository &#8599;
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pj['description'])): ?>
                            <p class="item-desc"><?= nl2br(e($pj['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Certifications -->
        <?php if (!empty($portfolio['certificates'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Certifications & Credentials</h2>
                <?php foreach ($portfolio['certificates'] as $cert): ?>
                    <div class="item-card">
                        <div class="item-top">
                            <h3 class="item-name"><?= e($cert['title']) ?></h3>
                            <span class="item-date"><?= e($cert['date']) ?></span>
                        </div>
                        <div class="item-sub">Issued by: <strong><?= e($cert['issuer']) ?></strong></div>
                        <?php if (!empty($cert['url'])): ?>
                            <a href="<?= e($cert['url']) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 11.5px; color: #1769E8; text-decoration: underline;">
                                Verify Credential &#8599;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Achievements -->
        <?php if (!empty($portfolio['achievements'])): ?>
            <section class="showcase-section-card">
                <h2 class="sec-title">Honors & Achievements</h2>
                <?php foreach ($portfolio['achievements'] as $ach): ?>
                    <div class="item-card">
                        <div class="item-top">
                            <h3 class="item-name"><?= e($ach['title']) ?></h3>
                            <span class="item-date"><?= e($ach['date']) ?></span>
                        </div>
                        <?php if (!empty($ach['description'])): ?>
                            <p class="item-desc"><?= nl2br(e($ach['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="showcase-footer">
            <p>Showcased via <a href="login.php">CareerAct</a> &mdash; Verified Collegiate Extracurricular Intelligence & Portfolio Platform.</p>
        </footer>

    </div>

</body>
</html>

