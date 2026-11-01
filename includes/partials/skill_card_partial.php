<?php
/**
 * Skill Card Partial for CareerAct Skill Assessment Catalog
 */
$scoreBadgeColor = 'neutral';
if ($sk['is_assessed']) {
    $sc = (float)$sk['score'];
    $scoreBadgeColor = ($sc >= 80) ? 'success' : (($sc >= 50) ? 'warning' : 'danger');
}
?>
<div class="skill-card">
    <div>
        <div class="skill-card-top">
            <span class="skill-card-name"><?= e($sk['skill_name']) ?></span>
            <?php if ($sk['is_assessed']): ?>
                <span class="badge-pill <?= $scoreBadgeColor ?>" style="font-size: 11px;">
                    Score: <?= (int)$sk['score'] ?>%
                </span>
            <?php else: ?>
                <span class="badge-pill neutral" style="font-size: 11px;">Pending</span>
            <?php endif; ?>
        </div>

        <div class="skill-card-category"><?= e($sk['category_name']) ?></div>

        <div class="skill-card-badges">
            <?php if ($sk['is_my_skill']): ?>
                <span class="badge-pill info" style="font-size: 10px;" title="Tracked in your profile">
                    Level <?= (int)$sk['current_level'] ?>/5 • <?= ucfirst($sk['skill_source']) ?>
                </span>
            <?php endif; ?>

            <?php if ($sk['is_career_required']): ?>
                <span class="badge-pill primary" style="font-size: 10px; background:#EEF2FF; color:#4338CA; border:1px solid #C7D2FE;" title="Required by target career pathway">
                    Career Target
                </span>
            <?php endif; ?>

            <?php if ($sk['is_activity_linked']): ?>
                <span class="badge-pill" style="font-size: 10px; background:#F0FDF4; color:#15803D; border:1px solid #BBF7D0;" title="Linked to extracurricular activities">
                    Activity Match
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if ($sk['is_assessed']): ?>
            <div style="display: flex; gap: 6px;">
                <a href="skills_assessment.php?action=result&id=<?= (int)$sk['assessment_id'] ?>" class="btn-retake" style="flex: 1; text-align: center; text-decoration: none; border-radius: 6px; padding: 8px 10px; font-size: 12px; font-weight: 600;">
                    View Report
                </a>
                <a href="skills_assessment.php?action=start&skill_id=<?= (int)$sk['skill_id'] ?>" class="btn-take-assess" style="flex: 1; text-align: center; font-size: 12px; padding: 8px 10px;">
                    Retake (<?= (int)$sk['attempts_count'] ?>)
                </a>
            </div>
        <?php else: ?>
            <a href="skills_assessment.php?action=start&skill_id=<?= (int)$sk['skill_id'] ?>" class="btn-take-assess">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                <span>Take Scenario Assessment</span>
            </a>
        <?php endif; ?>
    </div>
</div>

