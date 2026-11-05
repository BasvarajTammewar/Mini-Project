<?php
/**
 * CareerAct — Admin Navigation Bar Component
 */

declare(strict_types=1);

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$adminEmail = $_SESSION['email'] ?? 'admin@careeract.edu';
$adminName  = $_SESSION['name'] ?? 'Administrator';

// Check pending verification count if not already loaded
if (!isset($pendingVerificationCount)) {
    try {
        $dbConn = $db ?? getDbConnection();
        $stmtBadge = $dbConn->query("SELECT COUNT(*) FROM opportunities WHERE verification_status IN ('unverified', 'needs_correction')");
        $pendingVerificationCount = (int)$stmtBadge->fetchColumn();
    } catch (Exception $e) {
        $pendingVerificationCount = 0;
    }
}
?>
<header class="admin-navbar">
    <div class="admin-nav-container">
        <div class="admin-nav-left">
            <a href="dashboard.php" class="admin-brand">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                </svg>
                <span>CareerAct <span class="admin-badge-role">Admin</span></span>
            </a>
            <nav class="admin-menu">
                <a href="dashboard.php" class="admin-nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    Dashboard
                </a>
                <a href="categories.php" class="admin-nav-link <?= $currentPage === 'categories.php' ? 'active' : '' ?>">
                    Categories
                </a>
                <a href="opportunities.php" class="admin-nav-link <?= $currentPage === 'opportunities.php' ? 'active' : '' ?>">
                    Opportunities
                </a>
                <a href="verification_queue.php" class="admin-nav-link <?= $currentPage === 'verification_queue.php' ? 'active' : '' ?>">
                    Verification Queue
                    <?php if ($pendingVerificationCount > 0): ?>
                        <span class="nav-badge-pill"><?= $pendingVerificationCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="archived.php" class="admin-nav-link <?= $currentPage === 'archived.php' ? 'active' : '' ?>">
                    Expired & Archived
                </a>
                <a href="announcements.php" class="admin-nav-link <?= $currentPage === 'announcements.php' ? 'active' : '' ?>">
                    Announcements
                </a>
                <a href="audit_log.php" class="admin-nav-link <?= $currentPage === 'audit_log.php' ? 'active' : '' ?>">
                    Audit Log
                </a>
            </nav>
        </div>
        <div class="admin-nav-right">
            <div class="admin-user-info">
                <span class="admin-name"><?= htmlspecialchars($adminName) ?></span>
                <span class="admin-email"><?= htmlspecialchars($adminEmail) ?></span>
            </div>
            <a href="../dashboard.php" class="admin-btn-secondary" title="View Student Portal">
                Student View
            </a>
            <a href="../logout.php" class="admin-btn-logout">
                Sign Out
            </a>
        </div>
    </div>
</header>

