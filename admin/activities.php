<?php
/**
 * CareerAct — Admin Activities Redirect to Modern Opportunities Catalog
 */
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
header('Location: opportunities.php');
exit;
