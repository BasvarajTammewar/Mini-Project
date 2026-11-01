<?php
$css = file_get_contents(__DIR__ . '/../assets/css/dashboard.css');

$openComments = substr_count($css, '/*');
$closeComments = substr_count($css, '*/');
echo "Comments: $openComments open, $closeComments close\n";

$tokens = preg_split('/([{}])/', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
$depth = 0;
$line = 1;
foreach ($tokens as $token) {
    if ($token === '{') {
        $depth++;
    } elseif ($token === '}') {
        $depth--;
        if ($depth < 0) {
            echo "Negative depth at line $line\n";
            break;
        }
    } else {
        $line += substr_count($token, "\n");
    }
}
echo "Final depth: $depth\n";

