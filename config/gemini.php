<?php
/**
 * CareerAct — Google Gemini API Configuration & Credentials Manager
 * 
 * Secure backend configuration for AI-Powered Skill Assessment.
 * NEVER exposes credentials to frontend JavaScript or client responses.
 */

declare(strict_types=1);

// Attempt to load .env file if available in project root
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!isset($_SERVER[$key]) && !isset($_ENV[$key])) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Retrieve Gemini API Key from environment (.env) or local fallback
$geminiApiKey = getenv('GEMINI_API_KEY') 
    ?: ($_ENV['GEMINI_API_KEY'] ?? ($_SERVER['GEMINI_API_KEY'] ?? ''));

// Optional direct fallback: You can also paste your API key inside the quotes below if not using .env
$directApiKey = ''; 

if (empty($geminiApiKey)) {
    if (!empty($directApiKey)) {
        $geminiApiKey = $directApiKey;
    } elseif (defined('CAREERACT_GEMINI_KEY')) {
        $geminiApiKey = constant('CAREERACT_GEMINI_KEY');
    }
}

return [
    'api_key'      => $geminiApiKey,
    'model'        => 'gemini-3.5-flash-lite',
    'api_url'      => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
    'fallback_model' => 'gemini-3.5-flash',
    'fallback_url'   => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent',
    'timeout_sec'  => 30,
    'max_retries'  => 2,
    
    // Configurable Performance Thresholds
    'thresholds' => [
        'strongly_demonstrated' => 80.0,
        'partially_demonstrated' => 50.0,
        'not_yet_demonstrated'   => 0.0,
    ],
    
    // Evaluation Rubrics (Weights totaling 100)
    'rubrics' => [
        'practical_approach' => [
            'name'        => 'Relevance & Practical Application',
            'max_points'  => 25,
            'description' => 'Realism, feasibility, and appropriateness of the proposed action.'
        ],
        'critical_thinking' => [
            'name'        => 'Critical Thinking & Analysis',
            'max_points'  => 25,
            'description' => 'Depth of situational understanding, problem identification, and logic.'
        ],
        'strategy_communication' => [
            'name'        => 'Strategy, Communication & Initiative',
            'max_points'  => 25,
            'description' => 'Proactive leadership, diplomacy, collaboration, and stakeholder communication.'
        ],
        'impact_resolution' => [
            'name'        => 'Problem Resolution & Long-Term Impact',
            'max_points'  => 25,
            'description' => 'Effectiveness in resolving the core challenge and preventing recurrence.'
        ]
    ]
];

