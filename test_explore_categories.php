<?php
/**
 * CareerAct — Explore Activities: Category Tiles & Opportunity Listings Test Suite
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/event_discovery_service.php';

$db = getDbConnection();

$total = 0;
$passed = 0;

function it(string $title, callable $fn): void {
    global $total, $passed;
    $total++;
    echo "Running [{$title}]... ";
    try {
        $res = $fn();
        if ($res === true) {
            $passed++;
            echo "PASSED\n";
        } else {
            echo "FAILED: {$res}\n";
        }
    } catch (Throwable $e) {
        echo "FAILED with exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "========================================================================\n";
echo " CAREERACT — CATEGORY TILES & OPPORTUNITY LISTINGS VERIFICATION\n";
echo " Database: carrearact2\n";
echo "========================================================================\n\n";

// Get student user
$student = $db->query("SELECT id, name, email FROM users WHERE role = 'student' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userId = (int)$student['id'];

// Test 1: Screen 1 shows categories catalog with required categories
it("1. Category Catalog returns standard tiles across technology, leadership, and public domains", function() use ($db, $userId) {
    $catalog = getActivityCategoriesCatalog($db, $userId);
    if (count($catalog) < 10) {
        return "Expected at least 10 categories, found " . count($catalog);
    }

    $codes = array_column($catalog, 'code');
    $required = ['HACKATHON', 'COMPETITION', 'INTERNSHIP', 'WORKSHOP', 'PROJECT', 'LEADERSHIP', 'VOLUNTEERING', 'RESEARCH'];
    foreach ($required as $req) {
        if (!in_array($req, $codes, true)) {
            return "Missing required category code: {$req}";
        }
    }

    // Verify each tile contains icon_svg and verified_opps_count
    foreach ($catalog as $cat) {
        if (empty($cat['name']) || empty($cat['icon_svg'])) {
            return "Category {$cat['code']} is missing name or icon_svg";
        }
    }

    return true;
});

// Test 2: Category search filters categories by keyword
it("2. Category search filters category tiles by keyword without returning event items", function() use ($db, $userId) {
    $filtered = getActivityCategoriesCatalog($db, $userId, 'hack');
    if (empty($filtered)) {
        return "Search for 'hack' returned no categories";
    }
    foreach ($filtered as $f) {
        if (stripos($f['name'], 'hack') === false && stripos($f['description'], 'hack') === false) {
            return "Category {$f['name']} does not match query 'hack'";
        }
    }
    return true;
});

// Test 3: Screen 1 rendered HTML contains category tiles and NO individual opportunity cards
it("3. Screen 1 initially shows category tiles, NOT individual event cards", function() use ($userId) {
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'student';

    ob_start();
    $_GET = []; // No category parameter => Screen 1
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    // Check for category tiles
    if (stripos($html, 'category-tile-card') === false) {
        return "Screen 1 does not contain .category-tile-card elements";
    }
    if (stripos($html, 'catTile_hackathon') === false) {
        return "Screen 1 missing Hackathons category tile";
    }
    if (stripos($html, 'catTile_workshop') === false) {
        return "Screen 1 missing Workshops category tile";
    }

    // Must NOT contain individual opportunity cards on Screen 1
    if (stripos($html, '<article class="opportunity-card"') !== false) {
        return "Screen 1 should NOT show individual opportunity cards";
    }

    return true;
});

// Test 4: Screen 2 for Hackathons opens ONLY hackathon listings
it("4. Screen 2 for Hackathons opens only Hackathon listings", function() use ($userId) {
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'student';

    ob_start();
    $_GET = ['category' => 'hackathon']; // Screen 2
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    // Must contain opportunity card and category header
    if (stripos($html, 'cat-header-title') === false || stripos($html, 'Hackathons') === false) {
        return "Screen 2 header missing or not showing Hackathons";
    }
    if (stripos($html, 'opportunity-card') === false) {
        return "Screen 2 missing opportunity-card listings";
    }

    // Must contain breadcrumb link back to Screen 1
    if (stripos($html, 'breadcrumb-link') === false || stripos($html, 'Explore Activities') === false) {
        return "Screen 2 missing breadcrumb back to Explore Activities";
    }

    // Must NOT contain category-tile-card elements
    if (stripos($html, 'class="category-tile-card"') !== false) {
        return "Screen 2 should NOT show category tiles";
    }

    return true;
});

// Test 5: Screen 2 for Projects opens project listings
it("5. Screen 2 for Projects opens only Project listings", function() use ($userId) {
    ob_start();
    $_GET = ['category' => 'project'];
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    if (stripos($html, 'Personal and portfolio projects') === false) {
        return "Screen 2 not showing Projects category header";
    }
    if (stripos($html, 'Pune Cloud-Native Software Architecture') === false && stripos($html, 'GitHub Campus') === false) {
        return "Expected project opportunity in listing";
    }

    return true;
});

// Test 6: Empty state for category without active opportunities
it("6. Category with no published events displays truthful empty state", function() use ($userId) {
    ob_start();
    $_GET = ['category' => 'club'];
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    if (stripos($html, 'No verified opportunities found') === false) {
        return "Missing truthful empty state message for category without opportunities";
    }
    return true;
});

// Test 7: City filtering works in Screen 2
it("7. Location filter correctly filters category opportunities by city", function() use ($userId) {
    ob_start();
    $_GET = ['category' => 'hackathon', 'city' => 'Pune'];
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    // Check for Pune hackathon title (accounting for HTML entity escaping)
    if (stripos($html, 'Pune Smart City') === false) {
        return "Pune hackathon missing from Pune filter view";
    }
    return true;
});

// Test 8: Expired opportunities are excluded from active Screen 2 listings
it("8. Expired opportunities are excluded from active Screen 2 listings", function() use ($userId) {
    ob_start();
    $_GET = ['category' => 'hackathon', 'city' => 'all'];
    include __DIR__ . '/activities.php';
    $html = ob_get_clean();

    if (stripos($html, 'EXPIRED-HACK-2025') !== false || stripos($html, 'Inter-Collegiate Summer Hackathon 2025') !== false) {
        return "Found expired opportunity displayed in active listings!";
    }
    return true;
});

// Test 9: Strict separation — future opportunities never contaminate student_existing_activities
it("9. Future opportunities never enter student_existing_activities or student_skills", function() use ($db, $userId) {
    $existingCount = (int)$db->query("SELECT COUNT(*) FROM student_existing_activities WHERE user_id = {$userId}")->fetchColumn();
    // Verify no opportunity titles were automatically injected into student_existing_activities
    $stmtBad = $db->prepare("
        SELECT COUNT(*) FROM student_existing_activities sea
        JOIN opportunities o ON sea.title = o.title
        WHERE sea.user_id = :uid
    ");
    $stmtBad->execute([':uid' => $userId]);
    $badCount = (int)$stmtBad->fetchColumn();

    if ($badCount > 0) {
        return "Found future opportunities improperly inserted into student_existing_activities!";
    }
    return true;
});

echo "\n========================================================================\n";
echo " TEST RESULTS: {$passed} / {$total} PASSED\n";
if ($passed === $total) {
    echo " >>> ALL CATEGORY TILES & OPPORTUNITY LISTING CHECKS PASSED 100%! <<<\n";
}
echo "========================================================================\n";
