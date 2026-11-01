<?php
/**
 * CareerAct — Gemini AI Service
 * 
 * Handles real-world scenario question generation and answer evaluation
 * using Google Gemini API with strict validation and structured JSON.
 */

declare(strict_types=1);

class GeminiService
{
    private array $config;
    private string $apiKey;
    private string $apiUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct()
    {
        $this->config = require dirname(__DIR__) . '/config/gemini.php';
        $this->apiKey = (string)($this->config['api_key'] ?? '');
        $this->apiUrl = (string)($this->config['api_url'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent');
        $this->timeout = (int)($this->config['timeout_sec'] ?? 30);
        $this->maxRetries = (int)($this->config['max_retries'] ?? 2);
    }

    /**
     * Checks if Gemini API key is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && strlen($this->apiKey) > 10;
    }

    /**
     * Generate 5 practical situation-based assessment questions for a skill
     * 
     * @param string $skillName Skill to assess (e.g., 'Leadership', 'Communication', 'Python')
     * @param string $categoryName Category (e.g., 'Soft Skills', 'Technical Skills')
     * @param array $context Additional context (e.g., student target career goal)
     * @return array Array with 'skill' and 'questions' (5 questions)
     */
    public function generateScenarioQuestions(string $skillName, string $categoryName = 'General', array $context = []): array
    {
        $careerContext = !empty($context['career_title']) ? "The student is targeting a career in '{$context['career_title']}'." : "";

        $systemInstruction = "You are a senior vocational skills evaluator and scenario designer for CareerAct.
Generate exactly 5 realistic, situation-based assessment questions to test a student's demonstrated ability in '{$skillName}' ({$categoryName}).
{$careerContext}

CRITICAL RULES:
1. Each question MUST present a concrete, challenging situation/scenario rather than asking for textbook definitions or abstract theory.
2. For Soft/Professional Skills (e.g. Leadership, Communication, Conflict Resolution, Teamwork):
   - Place the student in realistic university, club, event, internship, or project dilemmas with conflicting priorities, uncooperative team members, tight deadlines, or ethical decisions.
3. For Technical Skills (e.g. Programming, Database, Python, Web Development, Debugging):
   - Present practical engineering dilemmas, system crashes, debugging puzzles, database deadlocks, performance bottlenecks, or architectural trade-offs.
4. Each scenario must ask: 'What specific steps would you take, and why?'
5. Respond ONLY with a valid JSON object matching the requested schema. No markdown formatting outside the JSON, no backticks, no introductory text.";

        $userPrompt = "Generate 5 scenario questions for assessing the skill: '{$skillName}'.
Return strictly in this JSON format:
{
  \"skill\": \"{$skillName}\",
  \"category\": \"{$categoryName}\",
  \"questions\": [
    {
      \"index\": 1,
      \"scenario\": \"Scenario description testing real-world behavior... What would you do?\",
      \"evaluation_focus\": \"Core competency tested in this scenario\"
    },
    {
      \"index\": 2,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\"
    },
    {
      \"index\": 3,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\"
    },
    {
      \"index\": 4,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\"
    },
    {
      \"index\": 5,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\"
    }
  ]
}";

        if ($this->isConfigured()) {
            try {
                $response = $this->callGeminiApi($systemInstruction, $userPrompt);
                $parsed = $this->extractAndValidateJson($response);

                if (isset($parsed['questions']) && is_array($parsed['questions']) && count($parsed['questions']) >= 5) {
                    $cleaned = [];
                    foreach (array_slice($parsed['questions'], 0, 5) as $i => $q) {
                        $cleaned[] = [
                            'index' => $i + 1,
                            'scenario' => trim((string)($q['scenario'] ?? '')),
                            'evaluation_focus' => trim((string)($q['evaluation_focus'] ?? 'Problem Solving'))
                        ];
                    }
                    return [
                        'skill'     => $skillName,
                        'category'  => $categoryName,
                        'questions' => $cleaned,
                        'source'    => 'gemini_api'
                    ];
                }
            } catch (Exception $e) {
                error_log("Gemini Question Generation Warning: " . $e->getMessage() . ". Using curated domain scenarios.");
            }
        }

        // Curated domain scenario generator (reliable fallback if API is not yet configured or offline)
        return $this->getCuratedDomainScenarios($skillName, $categoryName);
    }

    /**
     * Evaluates student's answers using Gemini API
     * 
     * @param string $skillName Skill name
     * @param string $categoryName Category name
     * @param array $qaList Array of ['question' => string, 'answer' => string, 'focus' => string]
     * @return array Structured evaluation result
     */
    public function evaluateStudentAnswers(string $skillName, string $categoryName, array $qaList): array
    {
        $criteria = $this->getSkillEvaluationCriteria($skillName, $categoryName);
        $criteriaList = implode(', ', $criteria);

        $qaFormatted = "";
        foreach ($qaList as $idx => $qa) {
            $num = $idx + 1;
            $scenario = $qa['question'] ?? 'Scenario ' . $num;
            $answer = !empty(trim((string)($qa['answer'] ?? ''))) ? trim((string)$qa['answer']) : '[No answer submitted]';
            $focus = $qa['focus'] ?? 'Practical application';
            $qaFormatted .= "\n--- QUESTION {$num} (Focus: {$focus}) ---\nScenario: {$scenario}\nStudent's Answer: {$answer}\n";
        }

        $systemInstruction = "You are an expert vocational assessor for CareerAct.
Evaluate the student's answers for demonstrating the skill: '{$skillName}' ({$categoryName}).
Specific skill criteria to inspect: {$criteriaList}.

EVALUATION RUBRIC (Each Question 0-100, broken into 4 pillars of max 25 pts each):
1. Relevance & Practicality (0-25): Does the student answer the scenario directly with actionable, realistic steps?
2. Critical Thinking & Analysis (0-25): Does the student grasp the underlying causes, trade-offs, and root problems?
3. Strategy, Communication & Initiative (0-25): Does the student exhibit proactive leadership, diplomacy, collaboration, and appropriate communication?
4. Problem Resolution & Impact (0-25): How effective and sustainable is the proposed resolution?

SCORING RULES:
- Do NOT reward answers simply for length or buzzwords.
- Empty or evasive answers get 0-10 points.
- Superficial answers get 30-50 points.
- Well-reasoned, balanced, practical solutions get 70-95 points.
- Calculate overall_score as the exact average of all 5 question scores (0 to 100).
- Assign demonstration_level strictly based on overall_score:
  * 80.0 to 100.0: 'Strongly Demonstrated'
  * 50.0 to 79.9: 'Partially Demonstrated'
  * 0.0 to 49.9: 'Not Yet Demonstrated'
- Output strictly a valid JSON object. No markdown text outside the JSON.";

        $userPrompt = "Evaluate these 5 scenario responses for '{$skillName}':
{$qaFormatted}

Return strictly in this JSON format:
{
  \"skill\": \"{$skillName}\",
  \"overall_score\": 74.5,
  \"demonstration_level\": \"Partially Demonstrated\",
  \"ai_feedback\": \"2-3 sentence overarching summary evaluating demonstrated abilities and key gaps.\",
  \"strengths\": [
    \"Specific demonstrated strength 1\",
    \"Specific demonstrated strength 2\",
    \"Specific demonstrated strength 3\"
  ],
  \"improvement_areas\": [
    \"Specific weakness or area needing development 1\",
    \"Specific weakness or area needing development 2\"
  ],
  \"recommended_actions\": [
    \"Concrete action or project recommended 1\",
    \"Concrete action or project recommended 2\"
  ],
  \"rubric_summary\": {
    \"practical_approach\": 19,
    \"critical_thinking\": 18,
    \"strategy_communication\": 18,
    \"impact_resolution\": 19.5
  },
  \"question_evaluations\": [
    {
      \"question_index\": 1,
      \"score\": 75,
      \"feedback\": \"Constructive feedback for question 1\",
      \"rubrics\": {
        \"practical_approach\": 19,
        \"critical_thinking\": 19,
        \"strategy_communication\": 18,
        \"impact_resolution\": 19
      }
    },
    {
      \"question_index\": 2,
      \"score\": 70,
      \"feedback\": \"Constructive feedback for question 2\",
      \"rubrics\": {
        \"practical_approach\": 18,
        \"critical_thinking\": 18,
        \"strategy_communication\": 17,
        \"impact_resolution\": 17
      }
    },
    {
      \"question_index\": 3,
      \"score\": 80,
      \"feedback\": \"Constructive feedback for question 3\",
      \"rubrics\": {
        \"practical_approach\": 20,
        \"critical_thinking\": 20,
        \"strategy_communication\": 20,
        \"impact_resolution\": 20
      }
    },
    {
      \"question_index\": 4,
      \"score\": 72,
      \"feedback\": \"Constructive feedback for question 4\",
      \"rubrics\": {
        \"practical_approach\": 18,
        \"critical_thinking\": 18,
        \"strategy_communication\": 18,
        \"impact_resolution\": 18
      }
    },
    {
      \"question_index\": 5,
      \"score\": 76,
      \"feedback\": \"Constructive feedback for question 5\",
      \"rubrics\": {
        \"practical_approach\": 19,
        \"critical_thinking\": 19,
        \"strategy_communication\": 19,
        \"impact_resolution\": 19
      }
    }
  ]
}";

        if ($this->isConfigured()) {
            try {
                $response = $this->callGeminiApi($systemInstruction, $userPrompt);
                $parsed = $this->extractAndValidateJson($response);

                if (isset($parsed['overall_score']) && isset($parsed['question_evaluations'])) {
                    return $this->sanitizeEvaluationResult($parsed, $skillName);
                }
            } catch (Exception $e) {
                error_log("Gemini Answer Evaluation Warning: " . $e->getMessage() . ". Using rule-based evaluation.");
            }
        }

        // Rule-based heuristic evaluation fallback
        return $this->evaluateAnswersHeuristic($skillName, $categoryName, $qaList, $criteria);
    }

    /**
     * Executes HTTP POST call to Gemini API with retry logic
     */
    private function callGeminiApi(string $systemInstruction, string $userPrompt): string
    {
        $url = $this->apiUrl . '?key=' . urlencode($this->apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'generationConfig' => [
                'temperature'       => 0.4,
                'topK'              => 40,
                'topP'              => 0.95,
                'responseMimeType'  => 'application/json'
            ]
        ];

        $jsonPayload = json_encode($payload);
        $attempts = 0;
        $lastError = '';

        while ($attempts <= $this->maxRetries) {
            $attempts++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $rawResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $lastError = "cURL Network Error: " . $curlError;
                usleep(500000); // 500ms jitter before retry
                continue;
            }

            if ($httpCode === 200 && !empty($rawResponse)) {
                $resData = json_decode($rawResponse, true);
                $content = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (!empty($content)) {
                    return $content;
                }
            }

            if ($httpCode === 429 || $httpCode >= 500) {
                $lastError = "Gemini API HTTP {$httpCode}: Server busy or rate limited";
                sleep(1); // Wait 1s on rate limit
                continue;
            }

            $lastError = "Gemini API HTTP {$httpCode}: " . substr((string)$rawResponse, 0, 200);
            break;
        }

        throw new RuntimeException($lastError ?: 'Unknown Gemini API communication error');
    }

    /**
     * Safely parse JSON from raw Gemini response
     */
    private function extractAndValidateJson(string $raw): array
    {
        $raw = trim($raw);
        // Strip markdown code fences if present
        if (str_starts_with($raw, '```json')) {
            $raw = substr($raw, 7);
        } elseif (str_starts_with($raw, '```')) {
            $raw = substr($raw, 3);
        }
        if (str_ends_with($raw, '```')) {
            $raw = substr($raw, 0, -3);
        }
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback regex match for enclosed JSON block
        if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
            $extracted = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
                return $extracted;
            }
        }

        throw new RuntimeException('Failed to parse structured JSON from Gemini response');
    }

    /**
     * Sanitizes and bounds evaluation result
     */
    private function sanitizeEvaluationResult(array $data, string $skillName): array
    {
        $overallScore = max(0.0, min(100.0, (float)($data['overall_score'] ?? 0.0)));
        
        $level = 'Not Yet Demonstrated';
        if ($overallScore >= $this->config['thresholds']['strongly_demonstrated']) {
            $level = 'Strongly Demonstrated';
        } elseif ($overallScore >= $this->config['thresholds']['partially_demonstrated']) {
            $level = 'Partially Demonstrated';
        }

        $qEvals = [];
        foreach (($data['question_evaluations'] ?? []) as $i => $qe) {
            $score = max(0.0, min(100.0, (float)($qe['score'] ?? 50.0)));
            $qEvals[] = [
                'question_index' => $i + 1,
                'score'          => $score,
                'feedback'       => trim((string)($qe['feedback'] ?? 'Completed answer.')),
                'rubrics'        => $qe['rubrics'] ?? [
                    'practical_approach' => round($score * 0.25, 1),
                    'critical_thinking'  => round($score * 0.25, 1),
                    'strategy_communication' => round($score * 0.25, 1),
                    'impact_resolution'  => round($score * 0.25, 1)
                ]
            ];
        }

        return [
            'skill'                => $skillName,
            'overall_score'        => round($overallScore, 1),
            'demonstration_level'  => $level,
            'ai_feedback'          => trim((string)($data['ai_feedback'] ?? "Student demonstrated reasoning in {$skillName}.")),
            'strengths'            => (array)($data['strengths'] ?? ["Demonstrated willingness to tackle {$skillName} scenarios."]),
            'improvement_areas'    => (array)($data['improvement_areas'] ?? ["Deepen practical application and edge-case resolution."]),
            'recommended_actions'  => (array)($data['recommended_actions'] ?? ["Participate in targeted extracurricular projects and competitions."]),
            'rubric_summary'       => $data['rubric_summary'] ?? [
                'practical_approach' => round($overallScore * 0.25, 1),
                'critical_thinking'  => round($overallScore * 0.25, 1),
                'strategy_communication' => round($overallScore * 0.25, 1),
                'impact_resolution'  => round($overallScore * 0.25, 1)
            ],
            'question_evaluations' => $qEvals,
            'evaluation_source'    => 'gemini_api'
        ];
    }

    /**
     * Returns tailored evaluation criteria per skill
     */
    private function getSkillEvaluationCriteria(string $skillName, string $categoryName): array
    {
        $sn = strtolower($skillName);

        if (str_contains($sn, 'leadership')) {
            return ['Decision Making', 'Team Management', 'Initiative', 'Conflict Resolution', 'Responsibility'];
        }
        if (str_contains($sn, 'communication')) {
            return ['Clarity', 'Listening', 'Explanation', 'Confidence', 'Appropriate Communication Strategy'];
        }
        if (str_contains($sn, 'teamwork') || str_contains($sn, 'collaboration')) {
            return ['Collaboration', 'Cooperation', 'Conflict Handling', 'Contribution', 'Supporting Team Members'];
        }
        if (str_contains($sn, 'problem') || str_contains($sn, 'critical thinking')) {
            return ['Problem Identification', 'Analytical Thinking', 'Solution Quality', 'Logical Reasoning', 'Practical Implementation'];
        }
        if (str_contains($sn, 'time management') || str_contains($sn, 'organization')) {
            return ['Prioritization', 'Scheduling', 'Contingency Planning', 'Focus', 'Deadline Discipline'];
        }
        if (str_contains($sn, 'programming') || str_contains($sn, 'python') || str_contains($sn, 'java') || str_contains($sn, 'developer') || str_contains($sn, 'web') || str_contains($sn, 'database')) {
            return ['Technical Correctness', 'Systemic Thinking', 'Edge-Case Handling', 'Best Practices', 'Debugging Strategy'];
        }

        return ['Practical Approach', 'Depth of Understanding', 'Professional Strategy', 'Impact & Resolution'];
    }

    /**
     * Curated domain scenarios for standard library skills (High-quality offline / benchmark fallback)
     */
    private function getCuratedDomainScenarios(string $skillName, string $categoryName): array
    {
        $sn = strtolower($skillName);
        $scenarios = [];

        if (str_contains($sn, 'leadership')) {
            $scenarios = [
                [
                    'index' => 1,
                    'scenario' => 'You are leading a project team of five students. Two members strongly disagree on the core architecture and have stopped talking, while the deadline is in 24 hours. What specific steps would you take to resolve the conflict and deliver on time?',
                    'evaluation_focus' => 'Conflict Resolution & Deadline Management'
                ],
                [
                    'index' => 2,
                    'scenario' => 'You are appointed director for a major college symposium. Two weeks before the event, the key sponsorship team drops out, threatening the budget. How do you rally your committee and reorganize resources to save the event?',
                    'evaluation_focus' => 'Crisis Leadership & Resource Reallocation'
                ],
                [
                    'index' => 3,
                    'scenario' => 'Your team members disagree with your proposed strategy and prefer a riskier alternative. As team leader, how do you evaluate their points, facilitate consensus, and make the final binding decision?',
                    'evaluation_focus' => 'Consensus Building & Decisiveness'
                ],
                [
                    'index' => 4,
                    'scenario' => 'One of your team members is consistently missing milestone check-ins, claiming personal stress, which is overburdening other peers. How do you handle this situation with empathy while maintaining accountability?',
                    'evaluation_focus' => 'Accountability & Empathetic Management'
                ],
                [
                    'index' => 5,
                    'scenario' => 'A junior member of your committee is visibly unconfident and hesitant to speak during client or faculty presentations. How would you coach, motivate, and empower them to take ownership?',
                    'evaluation_focus' => 'Mentorship, Coaching & Empowerment'
                ]
            ];
        } elseif (str_contains($sn, 'communication') || str_contains($sn, 'presentation') || str_contains($sn, 'public speaking')) {
            $scenarios = [
                [
                    'index' => 1,
                    'scenario' => 'You need to explain a complex technical system and its architecture to non-technical stakeholders who have zero technical background. How do you structure your explanation and verify their comprehension?',
                    'evaluation_focus' => 'Clarity & Audience Adaptation'
                ],
                [
                    'index' => 2,
                    'scenario' => 'During a live presentation to industry mentors, a panelist aggressively challenges your team\'s core methodology. How do you compose yourself, address their criticism professionally, and keep the audience engaged?',
                    'evaluation_focus' => 'Poise Under Pressure & Diplomatic Defense'
                ],
                [
                    'index' => 3,
                    'scenario' => 'An essential team communication was misunderstood, causing duplicate effort and wasted hours. What communication protocol would you implement to prevent misinterpretation in remote or hybrid teams?',
                    'evaluation_focus' => 'Message Verification & Protocol Design'
                ],
                [
                    'index' => 4,
                    'scenario' => 'You need to deliver critical constructive feedback to a peer whose work quality has dropped significantly without discouraging them. What exact conversational approach do you use?',
                    'evaluation_focus' => 'Constructive Feedback Delivery'
                ],
                [
                    'index' => 5,
                    'scenario' => 'Your cross-departmental student initiative requires buy-in from three reluctant department heads. How do you craft and deliver a persuasive pitch tailored to their individual priorities?',
                    'evaluation_focus' => 'Persuasive Communication & Stakeholder Alignment'
                ]
            ];
        } elseif (str_contains($sn, 'problem') || str_contains($sn, 'critical thinking') || str_contains($sn, 'analytical')) {
            $scenarios = [
                [
                    'index' => 1,
                    'scenario' => 'Your application experiences an intermittent crash in production that only happens under specific user concurrency, but cannot be reproduced in your local development environment. What structured troubleshooting methodology do you follow?',
                    'evaluation_focus' => 'Systematic Root-Cause Analysis'
                ],
                [
                    'index' => 2,
                    'scenario' => 'You are faced with two competing options for solving an organizational bottleneck: one is quick and cheap but introduces technical debt; the other is robust but requires three weeks of rework. How do you critically analyze and justify your choice?',
                    'evaluation_focus' => 'Trade-off Analysis & Strategic Judgment'
                ],
                [
                    'index' => 3,
                    'scenario' => 'Data from a student survey shows a 40% decline in extracurricular engagement, but the open text comments contradict each other. How do you analyze this data objectively to pinpoint the true underlying problem?',
                    'evaluation_focus' => 'Analytical Deduction & Signal Extraction'
                ],
                [
                    'index' => 4,
                    'scenario' => 'A critical automated process fails during a live hackathon showcase with 10 minutes left on the clock. You have no immediate documentation. What step-by-step diagnostic sequence do you follow?',
                    'evaluation_focus' => 'High-Stakes Emergency Triage'
                ],
                [
                    'index' => 5,
                    'scenario' => 'After implementing what appeared to be the correct solution to a logistical challenge, an unexpected secondary failure emerges in another department. How do you trace cascading impacts and redesign the system?',
                    'evaluation_focus' => 'Second-Order Thinking & Systemic Correction'
                ]
            ];
        } elseif (str_contains($sn, 'programming') || str_contains($sn, 'python') || str_contains($sn, 'java') || str_contains($sn, 'web') || str_contains($sn, 'database')) {
            $scenarios = [
                [
                    'index' => 1,
                    'scenario' => 'A web service you deployed is slowing down dramatically over time, eventually throwing OutOfMemory / 500 errors. How would you isolate whether this is a database lock, a memory leak in application code, or an unindexed query?',
                    'evaluation_focus' => 'Performance Profiling & Bottleneck Isolation'
                ],
                [
                    'index' => 2,
                    'scenario' => 'You need to refactor a legacy monolithic function that is 800 lines long, tightly coupled, and has zero automated unit tests. What step-by-step approach do you take to safely refactor it without introducing regressions?',
                    'evaluation_focus' => 'Safe Refactoring & Testing Strategy'
                ],
                [
                    'index' => 3,
                    'scenario' => 'Your team\'s database schema is experiencing race conditions where two simultaneous user transactions produce inconsistent inventory balances. What concurrency control techniques (e.g., locks, isolation levels, atomic operations) would you implement and why?',
                    'evaluation_focus' => 'Concurrency Control & Transaction Integrity'
                ],
                [
                    'index' => 4,
                    'scenario' => 'A pull request from a peer works functionally but introduces severe security vulnerabilities (e.g., SQL injection risk, missing CSRF token, and unescaped HTML). How do you document and explain these risks in your code review, and propose safe patterns?',
                    'evaluation_focus' => 'Secure Coding Standards & Rigorous Code Review'
                ],
                [
                    'index' => 5,
                    'scenario' => 'You are designing the data model and API structure for an event registration platform that must handle sudden traffic spikes (5,000 requests per second). What caching, queuing, or pagination patterns would you adopt?',
                    'evaluation_focus' => 'Scalable System Architecture & API Design'
                ]
            ];
        } else {
            // General professional competencies
            $scenarios = [
                [
                    'index' => 1,
                    'scenario' => "In your experience applying {$skillName}, describe how you would handle an unexpected breakdown in project deliverables when key resources become unavailable.",
                    'evaluation_focus' => 'Resource Adaptability & Resilience'
                ],
                [
                    'index' => 2,
                    'scenario' => "You are tasked with executing a high-visibility initiative requiring {$skillName}. Two senior stakeholders have conflicting expectations. What exact steps do you take to align them?",
                    'evaluation_focus' => 'Stakeholder Alignment'
                ],
                [
                    'index' => 3,
                    'scenario' => "Explain a situation where a standard solution in {$skillName} proved ineffective. What innovative approach did you or would you implement to overcome the limitation?",
                    'evaluation_focus' => 'Creative Problem Resolution'
                ],
                [
                    'index' => 4,
                    'scenario' => "When quality standards are threatened by an aggressive deadline, how do you leverage {$skillName} to protect the core output while remaining within constraints?",
                    'evaluation_focus' => 'Quality Control Under Pressure'
                ],
                [
                    'index' => 5,
                    'scenario' => "How do you systematically evaluate whether your application of {$skillName} succeeded, and what metrics or feedback mechanisms do you use to improve future iterations?",
                    'evaluation_focus' => 'Continuous Improvement & Metrics'
                ]
            ];
        }

        return [
            'skill'     => $skillName,
            'category'  => $categoryName,
            'questions' => $scenarios,
            'source'    => 'curated_benchmark'
        ];
    }

    /**
     * Rule-based heuristic evaluation when API is in local offline / fallback mode
     */
    private function evaluateAnswersHeuristic(string $skillName, string $categoryName, array $qaList, array $criteria): array
    {
        $questionEvals = [];
        $totalScore = 0.0;

        foreach ($qaList as $i => $item) {
            $ans = trim((string)($item['answer'] ?? ''));
            $len = strlen($ans);
            $wordCount = str_word_count($ans);

            // Rigorous heuristic checks: length, structured reasoning keywords, actionable verbs
            $relevance = 10;
            $critical = 10;
            $strategy = 10;
            $impact = 10;

            if ($len < 20 || $wordCount < 5) {
                // Evasive / empty
                $relevance = 3; $critical = 2; $strategy = 2; $impact = 3;
            } elseif ($wordCount < 25) {
                $relevance = 12; $critical = 11; $strategy = 10; $impact = 11;
            } else {
                // Look for reasoning and structural keywords
                $keywords = ['first', 'step', 'analyze', 'because', 'ensure', 'communicate', 'resolve', 'monitor', 'implement', 'collaborate', 'prioritize', 'evaluate', 'prevent', 'plan', 'team', 'root'];
                $matched = 0;
                $lowAns = strtolower($ans);
                foreach ($keywords as $kw) {
                    if (str_contains($lowAns, $kw)) {
                        $matched++;
                    }
                }

                $bonus = min(12, $matched * 1.5);
                $relevance = min(25, 14 + $bonus * 0.3);
                $critical = min(25, 14 + $bonus * 0.3);
                $strategy = min(25, 13 + $bonus * 0.2);
                $impact = min(25, 14 + $bonus * 0.2);
            }

            $qScore = round($relevance + $critical + $strategy + $impact, 1);
            $totalScore += $qScore;

            $questionEvals[] = [
                'question_index' => $i + 1,
                'score'          => $qScore,
                'feedback'       => $qScore >= 75 
                    ? "Well-structured response displaying clear practical steps and consideration of outcomes."
                    : ($qScore >= 50 
                        ? "Adequate response, but would benefit from more concrete actionable steps and contingency planning."
                        : "Response is brief or lacking specific execution details. Flesh out specific actions and rationale."),
                'rubrics'        => [
                    'practical_approach' => round($relevance, 1),
                    'critical_thinking'  => round($critical, 1),
                    'strategy_communication' => round($strategy, 1),
                    'impact_resolution'  => round($impact, 1)
                ]
            ];
        }

        $overallScore = round($totalScore / max(1, count($qaList)), 1);
        $level = 'Not Yet Demonstrated';
        if ($overallScore >= 80.0) {
            $level = 'Strongly Demonstrated';
        } elseif ($overallScore >= 50.0) {
            $level = 'Partially Demonstrated';
        }

        return [
            'skill'               => $skillName,
            'overall_score'       => $overallScore,
            'demonstration_level' => $level,
            'ai_feedback'         => "The student demonstrates foundational reasoning in {$skillName}. Performance highlights sound situational awareness, with opportunities to deepen structured resolution and stakeholder alignment.",
            'strengths'           => [
                "Identified realistic starting actions for {$skillName} dilemmas.",
                "Maintains focus on practical project and team outcomes.",
                "Demonstrated logical reasoning under scenario constraints."
            ],
            'improvement_areas'   => [
                "Develop deeper conflict resolution and stakeholder communication techniques.",
                "Incorporate formal contingency planning before executing emergency decisions."
            ],
            'recommended_actions' => [
                "Participate in team-based competitions and hackathons to apply skills under pressure.",
                "Take on coordinator or lead roles in student clubs or campus technical symposiums."
            ],
            'rubric_summary'      => [
                'practical_approach'     => round($overallScore * 0.26, 1),
                'critical_thinking'      => round($overallScore * 0.25, 1),
                'strategy_communication' => round($overallScore * 0.24, 1),
                'impact_resolution'      => round($overallScore * 0.25, 1)
            ],
            'question_evaluations'=> $questionEvals,
            'evaluation_source'   => 'heuristic_benchmark'
        ];
    }
}

