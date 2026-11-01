<?php
$lines = file(__DIR__ . '/../assets/css/dashboard.css');
$depth = 0;
$inMedia = [];
foreach ($lines as $i => $line) {
    $lineNum = $i + 1;
    if (preg_match('/@media[^{]+{/', $line, $m)) {
        $inMedia[$depth] = trim($m[0]);
    }
    $opens = substr_count($line, '{');
    $closes = substr_count($line, '}');
    
    if (preg_match('/\.profile-dropdown-menu|\.dropdown-menu-item/', $line)) {
        echo "Line $lineNum (Depth $depth): " . trim($line) . "\n";
        if (!empty($inMedia)) {
            echo "   -> INSIDE MEDIA: " . implode(" > ", $inMedia) . "\n";
        } else {
            echo "   -> Top-level (NOT in media)\n";
        }
    }
    
    $depth += $opens - $closes;
    if ($depth < count($inMedia)) {
        array_pop($inMedia);
    }
}

