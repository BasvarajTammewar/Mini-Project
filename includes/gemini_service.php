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
        $this->apiUrl = (string)($this->config['api_url'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
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
     * @param array $context Additional context (e.g., student target career goal, attempt_number)
     * @param array $excludeQuestions List of previously asked questions to strictly exclude
     * @return array Array with 'skill' and 'questions' (5 questions)
     */
    public function generateScenarioQuestions(string $skillName, string $categoryName = 'General', array $context = [], array $excludeQuestions = []): array
    {
        $careerContext = !empty($context['career_title']) ? "The student is targeting a career in '{$context['career_title']}'." : "";
        $attemptNum = (int)($context['attempt_number'] ?? 1);

        // Merge exclusions from context if passed
        if (!empty($context['exclude_questions']) && is_array($context['exclude_questions'])) {
            $excludeQuestions = array_merge($excludeQuestions, $context['exclude_questions']);
        }
        $excludeQuestions = array_values(array_unique(array_filter($excludeQuestions)));

        $exclusionInstruction = "";
        if (!empty($excludeQuestions)) {
            $exclusionList = "";
            $exCount = 1;
            foreach (array_slice($excludeQuestions, 0, 15) as $exQ) {
                $trimmed = trim(strip_tags((string)$exQ));
                if ($trimmed !== '') {
                    $exclusionList .= "{$exCount}. \"{$trimmed}\"\n";
                    $exCount++;
                }
            }
            if ($exclusionList !== '') {
                $exclusionInstruction = "\nSTRICT NON-REPETITION REQUIREMENT (CRITICAL):\n"
                    . "The student has already completed previous assessment attempts and answered these scenarios:\n"
                    . $exclusionList
                    . "YOU MUST NOT repeat, closely rephrase, or reuse the premise of ANY of the above previous scenarios.\n"
                    . "Generate 5 ENTIRELY FRESH, DIFFERENT situational dilemmas from different workplace, campus, or technical contexts.\n";
            }
        }

        $systemInstruction = "You are a senior vocational skills evaluator and scenario designer for CareerAct.
Generate exactly 5 realistic, situation-based assessment questions to test a student's demonstrated ability in '{$skillName}' ({$categoryName}).
{$careerContext}
{$exclusionInstruction}
CRITICAL RULES:
1. Each question MUST present a concrete, challenging situation/scenario rather than asking for textbook definitions or abstract theory.
2. For Soft/Professional Skills (e.g. Leadership, Communication, Conflict Resolution, Teamwork):
   - Place the student in realistic university, club, event, internship, or project dilemmas with conflicting priorities, uncooperative team members, tight deadlines, or ethical decisions.
3. For Technical Skills (e.g. Programming, Database, Python, Web Development, Debugging):
   - Present practical engineering dilemmas, system crashes, debugging puzzles, database deadlocks, performance bottlenecks, or architectural trade-offs.
4. Each scenario must ask: 'What specific steps would you take, and why?'
5. Respond ONLY with a valid JSON object matching the requested schema. No markdown formatting outside the JSON, no backticks, no introductory text.";

        $userPrompt = "Generate 5 fresh, unique scenario questions for assessing the skill: '{$skillName}'.
Attempt Number: {$attemptNum}.
Return strictly in this JSON format:
{
  \"skill\": \"{$skillName}\",
  \"category\": \"{$categoryName}\",
  \"questions\": [
    {
      \"index\": 1,
      \"scenario\": \"Scenario description testing real-world behavior... What would you do?\",
      \"evaluation_focus\": \"Core competency tested in this scenario\",
      \"scenario_category\": \"Workplace Dilemma\",
      \"difficulty_level\": \"intermediate\"
    },
    {
      \"index\": 2,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\",
      \"scenario_category\": \"Team Collaboration\",
      \"difficulty_level\": \"intermediate\"
    },
    {
      \"index\": 3,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\",
      \"scenario_category\": \"Emergency Triage\",
      \"difficulty_level\": \"advanced\"
    },
    {
      \"index\": 4,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\",
      \"scenario_category\": \"Stakeholder Conflict\",
      \"difficulty_level\": \"intermediate\"
    },
    {
      \"index\": 5,
      \"scenario\": \"...\",
      \"evaluation_focus\": \"...\",
      \"scenario_category\": \"Long-term Strategy\",
      \"difficulty_level\": \"advanced\"
    }
  ]
}";

        if ($this->isConfigured()) {
            for ($retry = 0; $retry < 2; $retry++) {
                try {
                    $response = $this->callGeminiApi($systemInstruction, $userPrompt, 5, 1);
                    $parsed = $this->extractAndValidateJson($response);

                    if (isset($parsed['questions']) && is_array($parsed['questions']) && count($parsed['questions']) >= 5) {
                        $cleaned = [];
                        $hasDuplicate = false;

                        foreach (array_slice($parsed['questions'], 0, 5) as $i => $q) {
                            $scenText = trim((string)($q['scenario'] ?? ''));
                            if (!empty($excludeQuestions)) {
                                foreach ($excludeQuestions as $ex) {
                                    if (stripos($scenText, substr($ex, 0, 35)) !== false) {
                                        $hasDuplicate = true;
                                        break;
                                    }
                                }
                            }
                            $cleaned[] = [
                                'index'             => $i + 1,
                                'scenario'          => $scenText,
                                'evaluation_focus'  => trim((string)($q['evaluation_focus'] ?? 'Problem Solving')),
                                'scenario_category' => trim((string)($q['scenario_category'] ?? 'Applied Scenario')),
                                'difficulty_level'  => trim((string)($q['difficulty_level'] ?? 'intermediate'))
                            ];
                        }

                        if (!$hasDuplicate || $retry === 1) {
                            return [
                                'skill'     => $skillName,
                                'category'  => $categoryName,
                                'questions' => $cleaned,
                                'source'    => 'gemini_api'
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log("Gemini Question Generation Warning: " . $e->getMessage() . ". Retrying / fallback.");
                }
            }
        }

        // Curated multi-attempt domain scenario generator (reliable fallback non-repeating per attempt)
        return $this->getCuratedDomainScenarios($skillName, $categoryName, $attemptNum, $excludeQuestions);
    }

    /**
     * Generates a single situational question for conversational assessment flow.
     */
    public function generateSingleScenarioQuestion(string $skillName, string $categoryName = 'General', array $context = [], array $excludeQuestions = []): array
    {
        $batch = $this->generateScenarioQuestions($skillName, $categoryName, $context, $excludeQuestions);
        $questions = $batch['questions'] ?? [];
        $qIndex = (int)($context['question_index'] ?? 1);
        $selected = $questions[($qIndex - 1) % count($questions)] ?? [
            'index'             => $qIndex,
            'scenario'          => "You face an unexpected challenge requiring {$skillName} under tight deadlines. What specific steps would you take, and why?",
            'evaluation_focus'  => 'Applied Problem Solving',
            'scenario_category' => 'Situational Challenge',
            'difficulty_level'  => 'intermediate'
        ];
        $selected['index'] = $qIndex;
        return [
            'skill'    => $skillName,
            'category' => $categoryName,
            'question' => $selected,
            'source'   => $batch['source'] ?? 'curated'
        ];
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
                $response = $this->callGeminiApi($systemInstruction, $userPrompt, 3, 0);
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
     * Generates an evidence-based skill gap analysis for an assessed skill using Gemini AI
     * 
     * @param string $skillName Skill name assessed in Module 6
     * @param string $categoryName Category name
     * @param float $score Verified assessment score (0-100)
     * @param string $gapStatus Backend calculated gap classification ('proficient', 'moderate_gap', 'critical_gap')
     * @param array $assessmentEvidence List of scenario questions, answers, and evaluations
     * @param string|null $careerTitle Target career goal (context only)
     * @return array Validated structured gap analysis
     */
    public function analyzeSkillGap(
        string $skillName,
        string $categoryName,
        float $score,
        string $gapStatus,
        array $assessmentEvidence = [],
        ?string $careerTitle = null
    ): array {
        $careerContext = !empty($careerTitle) ? "The student is targeting a career in '{$careerTitle}'." : "";

        $evidenceFormatted = "";
        foreach ($assessmentEvidence as $i => $ev) {
            $num = $i + 1;
            $scen = $ev['scenario'] ?? 'Scenario ' . $num;
            $ans = !empty(trim((string)($ev['answer'] ?? ''))) ? trim((string)$ev['answer']) : '[No answer submitted]';
            $qScore = isset($ev['score']) ? (float)$ev['score'] : null;
            $evidenceFormatted .= "\n[Scenario {$num}]\nDilemma: {$scen}\nStudent's Response: {$ans}\n" . ($qScore !== null ? "Scenario Score: {$qScore}/100\n" : "");
        }

        $systemInstruction = "You are a senior vocational skills diagnostician and career advisor for CareerAct.
Perform an AI skill-gap analysis for the student's explicitly assessed skill: '{$skillName}' ({$categoryName}).
{$careerContext}

VERIFIED ASSESSMENT DATA (DO NOT CHANGE):
- Overall Verified Score: {$score}/100
- Computed Gap Status: '{$gapStatus}' (proficient = score >= 80, moderate_gap = 50-79, critical_gap = < 50)

CRITICAL RULES:
1. Analyze ONLY the requested skill: '{$skillName}'. You MUST NOT introduce or assess any unrequested skill.
2. The score ({$score}/100) and gap status ('{$gapStatus}') are predetermined by backend rules; do NOT alter them.
3. Base all feedback strictly on the student's actual responses in the provided scenario evidence.
4. If score is >= 80 (proficient), confirm their high proficiency, identify advanced strengths, and recommend mastery-level challenges rather than inventing artificial deficits.
5. If score is 50-79 (moderate_gap), explain specific areas where practical execution or root-cause troubleshooting can be strengthened.
6. If score is < 50 (critical_gap), provide structured, foundational recovery guidance with step-by-step practice actions.
7. Return strictly a valid JSON object matching the requested schema. No markdown formatting outside the JSON, no backticks, no introductory text.";

        $userPrompt = "Generate the skill-gap analysis for '{$skillName}'.
Assessment Evidence:
{$evidenceFormatted}

Return strictly in this JSON format:
{
  \"skill_name\": \"{$skillName}\",
  \"gap_status\": \"{$gapStatus}\",
  \"score\": {$score},
  \"summary_text\": \"Executive analytical summary of student's demonstrated competence vs improvement areas...\",
  \"strengths\": [
    \"Demonstrated strength 1...\",
    \"Demonstrated strength 2...\"
  ],
  \"improvement_areas\": [
    \"Specific developmental area 1...\",
    \"Specific developmental area 2...\"
  ],
  \"evidence_explanation\": \"Clear explanation linking directly to their responses in the scenario dilemmas...\",
  \"career_context_note\": \"Explanation of how strengthening this specific skill accelerates advancement in {$careerTitle}...\",
  \"practice_actions\": [
    {
      \"title\": \"Actionable practice initiative\",
      \"description\": \"Specific hands-on project, hackathon, or exercise\",
      \"activity_category\": \"Projects | Hackathons | Workshops | Volunteering\"
    },
    {
      \"title\": \"...\",
      \"description\": \"...\",
      \"activity_category\": \"...\"
    }
  ]
}";

        if ($this->isConfigured()) {
            try {
                $response = $this->callGeminiApi($systemInstruction, $userPrompt, 3, 0);
                $parsed = $this->extractAndValidateJson($response);

                if (isset($parsed['summary_text']) && !empty($parsed['strengths']) && is_array($parsed['strengths'])) {
                    return $this->sanitizeSkillGapAnalysis($parsed, $skillName, $gapStatus, $score, $careerTitle);
                }
            } catch (Exception $e) {
                error_log("Gemini Skill-Gap Analysis Warning: " . $e->getMessage() . ". Using curated domain analysis.");
            }
        }

        // Curated domain gap analysis fallback
        return $this->getCuratedSkillGapAnalysis($skillName, $categoryName, $score, $gapStatus, $careerTitle);
    }

    /**
     * Sanitizes and enforces strict schema constraints on Gemini's skill gap response
     */
    private function sanitizeSkillGapAnalysis(array $raw, string $expectedSkill, string $expectedGapStatus, float $expectedScore, ?string $careerTitle): array
    {
        $strengths = [];
        if (!empty($raw['strengths']) && is_array($raw['strengths'])) {
            foreach ($raw['strengths'] as $s) {
                $trimmed = trim((string)$s);
                if ($trimmed !== '') {
                    $strengths[] = $trimmed;
                }
            }
        }
        if (empty($strengths)) {
            $strengths = ["Demonstrated practical initiative when confronting workplace dilemmas."];
        }

        $improvements = [];
        if (!empty($raw['improvement_areas']) && is_array($raw['improvement_areas'])) {
            foreach ($raw['improvement_areas'] as $im) {
                $trimmed = trim((string)$im);
                if ($trimmed !== '') {
                    $improvements[] = $trimmed;
                }
            }
        }
        if (empty($improvements)) {
            $improvements = ($expectedScore >= 80)
                ? ["Continue advancing towards complex, multi-stakeholder real-world challenges."]
                : ["Strengthen systematic root-cause troubleshooting and proactive stakeholder communication."];
        }

        $practiceActions = [];
        if (!empty($raw['practice_actions']) && is_array($raw['practice_actions'])) {
            foreach (array_slice($raw['practice_actions'], 0, 4) as $pa) {
                if (is_array($pa) && !empty($pa['title'])) {
                    $practiceActions[] = [
                        'title'             => trim((string)$pa['title']),
                        'description'       => trim((string)($pa['description'] ?? 'Hands-on practice initiative.')),
                        'activity_category' => trim((string)($pa['activity_category'] ?? 'Workshops'))
                    ];
                }
            }
        }
        if (empty($practiceActions)) {
            $practiceActions = [
                [
                    'title'             => "Targeted {$expectedSkill} Collaborative Workshop",
                    'description'       => "Participate in hands-on practical sprints to sharpen applied decision-making in {$expectedSkill}.",
                    'activity_category' => "Workshops"
                ]
            ];
        }

        return [
            'skill_name'           => $expectedSkill,
            'gap_status'           => $expectedGapStatus,
            'score'                => $expectedScore,
            'summary_text'         => trim((string)($raw['summary_text'] ?? "Analytical gap report for {$expectedSkill}.")),
            'strengths'            => array_values($strengths),
            'improvement_areas'    => array_values($improvements),
            'evidence_explanation' => trim((string)($raw['evidence_explanation'] ?? "Based on your verified scenario responses.")),
            'career_context_note'  => trim((string)($raw['career_context_note'] ?? ($careerTitle ? "Vital for target role as {$careerTitle}." : ""))),
            'practice_actions'     => $practiceActions,
            'source'               => 'gemini_api'
        ];
    }

    /**
     * Deterministic, rubric-aligned diagnostic gap analysis fallback
     */
    public function getCuratedSkillGapAnalysis(string $skillName, string $categoryName, float $score, string $gapStatus, ?string $careerTitle): array
    {
        $careerNote = $careerTitle 
            ? "Solidifying {$skillName} directly bridges core competencies expected in {$careerTitle} roles."
            : "Enhancing {$skillName} supports practical career readiness across professional tracks.";

        if ($gapStatus === 'proficient') {
            $summary = "You demonstrated strong mastery in {$skillName} ({$score}/100). Your scenario responses exhibited structured decision-making, proactive initiative, and sound long-term trade-off considerations.";
            $strengths = [
                "Clearly identified root causes before committing to hasty solutions.",
                "Articulated actionable, realistic steps that consider team and project constraints."
            ];
            $improvements = [
                "Continue seeking advanced, high-stakes dilemmas and cross-functional leadership opportunities."
            ];
            $actions = [
                [
                    'title'             => "Advanced Peer Mentorship & Code Sprints",
                    'description'       => "Lead peer review sessions or technical project sprints to challenge your mastery in {$skillName}.",
                    'activity_category' => "Projects"
                ],
                [
                    'title'             => "Competitive Hackathons & Innovation Challenges",
                    'description'       => "Apply high-level problem solving under tight time-boxed constraints.",
                    'activity_category' => "Hackathons"
                ]
            ];
        } elseif ($gapStatus === 'moderate_gap') {
            $summary = "You demonstrated foundational competence in {$skillName} ({$score}/100), but showed moderate gaps when handling complex trade-offs, escalations, or preventive documentation.";
            $strengths = [
                "Understands fundamental requirements and responds with positive intent to workplace dilemmas.",
                "Proposes initial tactical steps to address immediate obstacles."
            ];
            $improvements = [
                "Develop deeper root-cause investigation before implementing surface-level fixes.",
                "Incorporate structured stakeholder communication and post-incident documentation into your standard response."
            ];
            $actions = [
                [
                    'title'             => "Hands-on Practical Workshop in {$skillName}",
                    'description'       => "Participate in guided scenario simulations with peer feedback.",
                    'activity_category' => "Workshops"
                ],
                [
                    'title'             => "Applied Extracurricular Project Sprint",
                    'description'       => "Take responsibility for delivering an end-to-end milestone requiring active {$skillName}.",
                    'activity_category' => "Projects"
                ]
            ];
        } else {
            $summary = "Your assessment indicated a critical developmental gap in {$skillName} ({$score}/100). Responses tended to be brief or lacked actionable, stepwise resolution strategies.";
            $strengths = [
                "Recognized the dilemma and attempted to provide an answer."
            ];
            $improvements = [
                "Must practice formulating multi-step resolution workflows with clear rationale.",
                "Focus on understanding preventive measures and collaborative communication in team settings."
            ];
            $actions = [
                [
                    'title'             => "Foundational Skill-Building Bootcamp",
                    'description'       => "Build core fundamentals through structured learning and mentor-guided exercises.",
                    'activity_category' => "Workshops"
                ],
                [
                    'title'             => "Campus Club or Volunteering Participation",
                    'description'       => "Gain low-risk, real-world team experience applying {$skillName} collaboratively.",
                    'activity_category' => "Volunteering"
                ]
            ];
        }

        return [
            'skill_name'           => $skillName,
            'gap_status'           => $gapStatus,
            'score'                => $score,
            'summary_text'         => $summary,
            'strengths'            => $strengths,
            'improvement_areas'    => $improvements,
            'evidence_explanation' => "Calculated from your verified assessment session scores and scenario answers in {$skillName}.",
            'career_context_note'  => $careerNote,
            'practice_actions'     => $actions,
            'source'               => 'curated_diagnostic'
        ];
    }

    /**
     * Recommends and explains future activity categories using Gemini AI based on validated inputs
     * 
     * @param string $careerTitle Target career goal
     * @param array $skillGaps Validated skill gaps from Module 7
     * @param array $allowedCategories List of existing categories in activity_categories
     * @param string $preferredCity Student's preferred city
     * @return array Array of validated category recommendation items
     */
    public function rankAndExplainActivityRecommendations(
        string $careerTitle,
        array $skillGaps,
        array $allowedCategories,
        string $preferredCity = 'Pune'
    ): array {
        // Build list of valid category codes
        $categoryCatalogLines = [];
        $validCodes = [];
        foreach ($allowedCategories as $cat) {
            $code = strtoupper((string)$cat['code']);
            $validCodes[] = $code;
            $categoryCatalogLines[] = "- Code: {$code} | Name: {$cat['name']} | Purpose: {$cat['description']}";
        }
        $categoryCatalogText = implode("\n", $categoryCatalogLines);

        // Format gaps
        $gapsFormatted = "";
        if (!empty($skillGaps)) {
            foreach ($skillGaps as $g) {
                $impr = !empty($g['improvement_areas']) ? implode('; ', array_slice($g['improvement_areas'], 0, 2)) : 'Needs practical experience';
                $gapsFormatted .= "- Skill: {$g['skill_name']} | Status: {$g['gap_status']} | Score: {$g['score']}/100 | Needs: {$impr}\n";
            }
        } else {
            $gapsFormatted = "(No completed skill assessments yet. Base recommendations strictly on target career goal.)";
        }

        $systemInstruction = "You are a senior vocational pathway advisor for CareerAct.
Recommend 3 to 5 future extracurricular activity categories for a student targeting a career as '{$careerTitle}' in or near '{$preferredCity}'.

ALLOWED ACTIVITY CATEGORIES (You MUST ONLY choose from this catalog):
{$categoryCatalogText}

CRITICAL RULES:
1. Recommend ONLY categories from the allowed catalog. DO NOT invent new categories or codes.
2. DO NOT invent real events, dates, organizers, venues, deadlines, or registration URLs.
3. If the student has assessed skill gaps, prioritize categories that directly help bridge those gaps.
4. If no assessments exist, recommend categories that build foundational portfolio evidence for {$careerTitle}.
5. Return strictly a valid JSON array of objects. No markdown formatting outside the JSON, no backticks, no preamble.";

        $userPrompt = "Target Career: {$careerTitle}
Preferred City: {$preferredCity}
Assessed Skill Gaps:
{$gapsFormatted}

Return strictly a JSON array formatted as:
[
  {
    \"category_code\": \"HACKATHON\",
    \"relevance_label\": \"Skill-Gap Bridge\",
    \"target_skill\": \"Skill name addressed or 'Career Foundation'\",
    \"why_recommended\": \"Direct explanation of why this activity category benefits the student...\",
    \"skills_developed\": \"Comma-separated skills gained\",
    \"practice_gain\": \"Concrete learning or portfolio outcome...\",
    \"suggested_next_step\": \"Explore hackathons in {$preferredCity} or online to begin practicing.\"
  }
]";

        if ($this->isConfigured()) {
            try {
                $response = $this->callGeminiApi($systemInstruction, $userPrompt, 3, 0);
                $parsed = $this->extractAndValidateJson($response);

                if (is_array($parsed)) {
                    // Could be directly an array of items or wrapped in ['recommendations' => ...]
                    $items = isset($parsed['recommendations']) && is_array($parsed['recommendations']) 
                        ? $parsed['recommendations'] 
                        : (isset($parsed[0]) ? $parsed : []);

                    if (!empty($items)) {
                        $cleaned = [];
                        foreach ($items as $item) {
                            $code = strtoupper(trim((string)($item['category_code'] ?? '')));
                            if (in_array($code, $validCodes, true)) {
                                $cleaned[] = [
                                    'category_code'       => $code,
                                    'relevance_label'     => trim((string)($item['relevance_label'] ?? 'Career Milestone')),
                                    'target_skill'        => trim((string)($item['target_skill'] ?? $careerTitle)),
                                    'why_recommended'     => trim((string)($item['why_recommended'] ?? "Recommended for {$careerTitle} development.")),
                                    'skills_developed'    => trim((string)($item['skills_developed'] ?? "Practical skills")),
                                    'practice_gain'       => trim((string)($item['practice_gain'] ?? "Hands-on experience.")),
                                    'suggested_next_step' => trim((string)($item['suggested_next_step'] ?? "Explore opportunities in {$preferredCity} or online."))
                                ];
                            }
                        }
                        if (count($cleaned) >= 2) {
                            return $cleaned;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Gemini Activity Recommendations Warning: " . $e->getMessage() . ". Using curated recommendations.");
            }
        }

        // Deterministic fallback based on career goal and gaps
        return $this->getCuratedActivityRecommendationsFallback($careerTitle, $skillGaps, $allowedCategories, $preferredCity);
    }

    /**
     * Deterministic rule-based activity category recommendations fallback
     */
    public function getCuratedActivityRecommendationsFallback(
        string $careerTitle,
        array $skillGaps,
        array $allowedCategories,
        string $preferredCity = 'Pune'
    ): array {
        $categoryMap = [];
        foreach ($allowedCategories as $ac) {
            $categoryMap[strtoupper((string)$ac['code'])] = $ac;
        }

        $recommendations = [];

        // Check if there are technical / problem solving gaps
        $hasProblemSolvingGap = false;
        $hasLeadershipGap = false;
        $gapSkillNames = [];

        foreach ($skillGaps as $g) {
            $sName = strtolower((string)$g['skill_name']);
            $gapSkillNames[] = $g['skill_name'];
            if (strpos($sName, 'problem') !== false || strpos($sName, 'program') !== false || strpos($sName, 'data') !== false) {
                $hasProblemSolvingGap = true;
            }
            if (strpos($sName, 'leader') !== false || strpos($sName, 'team') !== false || strpos($sName, 'comm') !== false) {
                $hasLeadershipGap = true;
            }
        }

        $isTechCareer = (bool)preg_match('/(software|developer|engineer|data|cyber|cloud|analyst|ai|web)/i', $careerTitle);

        // 1. Hackathons / Competitions
        if ($isTechCareer && isset($categoryMap['HACKATHON'])) {
            $why = $hasProblemSolvingGap
                ? "Directly bridges your assessed skill gap in Problem Solving by immersing you in rapid, end-to-end prototyping sprints."
                : "Builds high-impact project artifacts and collaborative development experience essential for {$careerTitle}.";
            $recommendations[] = [
                'category_code'       => 'HACKATHON',
                'relevance_label'     => $hasProblemSolvingGap ? 'Skill-Gap Bridge' : 'Core Pathway',
                'target_skill'        => $hasProblemSolvingGap ? 'Problem Solving & Development' : $careerTitle,
                'why_recommended'     => $why,
                'skills_developed'    => 'Rapid Problem Solving, Systems Architecture, Agile Teamwork',
                'practice_gain'       => 'Hands-on experience delivering a working software prototype under realistic deadlines.',
                'suggested_next_step' => "Explore upcoming Hackathons in {$preferredCity} or virtual hackathons."
            ];
        } elseif (isset($categoryMap['COMPETITION'])) {
            $recommendations[] = [
                'category_code'       => 'COMPETITION',
                'relevance_label'     => 'Skill-Gap Bridge',
                'target_skill'        => !empty($gapSkillNames) ? implode(', ', array_slice($gapSkillNames, 0, 2)) : $careerTitle,
                'why_recommended'     => "Tests applied problem-solving against benchmark standards and industry-vetted prompts.",
                'skills_developed'    => 'Critical Thinking, Quantitative Analysis, Time Management',
                'practice_gain'       => 'Evaluated performance feedback against peer benchmarks.',
                'suggested_next_step' => "Explore Competitions in {$preferredCity} or online."
            ];
        }

        // 2. Projects
        if (isset($categoryMap['PROJECT'])) {
            $recommendations[] = [
                'category_code'       => 'PROJECT',
                'relevance_label'     => 'Portfolio Builder',
                'target_skill'        => $careerTitle,
                'why_recommended'     => "Translates theoretical coursework into production-quality portfolio evidence that demonstrates real capability to employers.",
                'skills_developed'    => 'Full-Lifecycle Engineering, Version Control, Documentation',
                'practice_gain'       => 'Deployable, reviewable codebase showcasing practical competencies.',
                'suggested_next_step' => "Explore Project initiatives and open-source sprints."
            ];
        }

        // 3. Workshops
        if (isset($categoryMap['WORKSHOP'])) {
            $whyWork = !empty($gapSkillNames)
                ? "Offers structured, instructor-guided hands-on practice directly targeting " . implode(' & ', array_slice($gapSkillNames, 0, 2)) . "."
                : "Provides targeted technical deep-dives into industry tools and workflows required for {$careerTitle}.";
            $recommendations[] = [
                'category_code'       => 'WORKSHOP',
                'relevance_label'     => !empty($gapSkillNames) ? 'Skill-Gap Bridge' : 'Technical Deep-Dive',
                'target_skill'        => !empty($gapSkillNames) ? implode(', ', array_slice($gapSkillNames, 0, 2)) : 'Core Fundamentals',
                'why_recommended'     => $whyWork,
                'skills_developed'    => 'Modern Toolchains, Best Practices, Practical Execution',
                'practice_gain'       => 'Guided step-by-step implementation of industry practices.',
                'suggested_next_step' => "Explore technical Workshops available in {$preferredCity} or online."
            ];
        }

        // 4. Leadership or Volunteering (if leadership gap or broad development)
        if ($hasLeadershipGap && isset($categoryMap['LEADERSHIP'])) {
            $recommendations[] = [
                'category_code'       => 'LEADERSHIP',
                'relevance_label'     => 'Skill-Gap Bridge',
                'target_skill'        => 'Leadership & Team Collaboration',
                'why_recommended'     => "Directly strengthens your assessed gap in team leadership, milestone tracking, and stakeholder conflict resolution.",
                'skills_developed'    => 'Team Leadership, Decision-Making, Conflict Resolution, Delegation',
                'practice_gain'       => 'Real-world accountability guiding peers toward concrete collective goals.',
                'suggested_next_step' => "Explore Leadership opportunities in campus societies or technical communities."
            ];
        } elseif (isset($categoryMap['INTERNSHIP'])) {
            $recommendations[] = [
                'category_code'       => 'INTERNSHIP',
                'relevance_label'     => 'Industry Immersion',
                'target_skill'        => $careerTitle,
                'why_recommended'     => "Provides formal workplace immersion to apply verified skills under professional mentorship.",
                'skills_developed'    => 'Professional Workplace Communication, Production Code Review, Delivery Accountability',
                'practice_gain'       => 'Real workplace tenure and verified professional reference.',
                'suggested_next_step' => "Explore verified student internships in {$preferredCity} or virtual."
            ];
        }

        return array_slice($recommendations, 0, 4);
    }

    /**
     * Executes HTTP POST call to Gemini API with retry logic
     */
    public function callGeminiApi(string $systemInstruction, string $userPrompt, ?int $timeoutOverride = null, int|float|null $maxRetriesOverride = null): string
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
        $timeout = $timeoutOverride ?? $this->timeout;
        $maxRetries = ($maxRetriesOverride !== null) ? (int)$maxRetriesOverride : $this->maxRetries;

        while ($attempts <= $maxRetries) {
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
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Bypass Windows IPv6 hangs
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
                CURLOPT_SSL_VERIFYPEER => false // Local dev compatibility
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
    public function extractAndValidateJson(string $raw): array
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
    /**
     * Fallback curated domain scenario generator with multiple distinct scenario pools per skill.
     * Selects different question sets for each attempt and verifies non-repetition against excludeQuestions.
     */
    private function getCuratedDomainScenarios(string $skillName, string $categoryName, int $attemptNumber = 1, array $excludeQuestions = []): array
    {
        $sn = strtolower($skillName);
        $poolIndex = max(0, ($attemptNumber - 1) % 4); // 0, 1, 2, or 3

        $allPools = [];

        if (str_contains($sn, 'leadership')) {
            $allPools = [
                // Pool 0 (Attempt 1)
                [
                    ['index' => 1, 'scenario' => 'You are leading a project team of five students. Two members strongly disagree on the core architecture and have stopped talking, while the deadline is in 24 hours. What specific steps would you take to resolve the conflict and deliver on time?', 'evaluation_focus' => 'Conflict Resolution & Deadline Management', 'scenario_category' => 'Team Conflict', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'You are appointed director for a major college symposium. Two weeks before the event, the key sponsorship team drops out, threatening the budget. How do you rally your committee and reorganize resources to save the event?', 'evaluation_focus' => 'Crisis Leadership & Resource Reallocation', 'scenario_category' => 'Resource Emergency', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'Your team members disagree with your proposed strategy and prefer a riskier alternative. As team leader, how do you evaluate their points, facilitate consensus, and make the final binding decision?', 'evaluation_focus' => 'Consensus Building & Decisiveness', 'scenario_category' => 'Strategic Alignment', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'One of your team members is consistently missing milestone check-ins, claiming personal stress, which is overburdening other peers. How do you handle this situation with empathy while maintaining accountability?', 'evaluation_focus' => 'Accountability & Empathetic Management', 'scenario_category' => 'Personnel Management', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'A junior member of your committee is visibly unconfident and hesitant to speak during client or faculty presentations. How would you coach, motivate, and empower them to take ownership?', 'evaluation_focus' => 'Mentorship, Coaching & Empowerment', 'scenario_category' => 'Talent Development', 'difficulty_level' => 'advanced']
                ],
                // Pool 1 (Attempt 2)
                [
                    ['index' => 1, 'scenario' => 'Midway through an inter-college tech competition, a key team member takes sole credit for a group module in front of judges. How do you handle this immediate situation professionally and rebuild team trust?', 'evaluation_focus' => 'Integrity & Team Trust Restoration', 'scenario_category' => 'Ethical Conduct', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'Your campus innovation cell faces a 50% unexpected budget cut right before purchasing materials. How do you prioritize project deliverables and guide your team through scope reduction without destroying morale?', 'evaluation_focus' => 'Budget Management & Morale Resilience', 'scenario_category' => 'Resource Constraints', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'A toxic high-performer delivers excellent individual technical results but constantly demoralizes other teammates in public chat channels. How do you confront and address this behavior as team lead?', 'evaluation_focus' => 'Culture Leadership & Firm Boundary Setting', 'scenario_category' => 'Team Dynamics', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'Two student clubs you lead must merge their annual hackathons under a single joint banner, but both committees are fiercely territorial. What negotiation framework do you employ to unify them?', 'evaluation_focus' => 'Cross-Organizational Negotiation', 'scenario_category' => 'Stakeholder Diplomacy', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'Your final project demo is tomorrow morning, and 3 critical integration bugs just appeared. The team is exhausted and panicking. How do you step up, organize emergency shifts, and maintain composure?', 'evaluation_focus' => 'Crisis Composure & Emergency Delegation', 'scenario_category' => 'High-Pressure Execution', 'difficulty_level' => 'advanced']
                ],
                // Pool 2 (Attempt 3)
                [
                    ['index' => 1, 'scenario' => 'A faculty advisor insists your team adopt an outdated technical framework that you know will fail modern requirements. How do you respectfully challenge senior authority while presenting viable alternatives?', 'evaluation_focus' => 'Managing Upwards & Diplomatic Advocacy', 'scenario_category' => 'Authority Negotiation', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'One of your closest personal friends on the project team is failing to deliver their assigned tasks. How do you separate personal friendship from leadership responsibilities to ensure project success?', 'evaluation_focus' => 'Impartial Accountability & Professional Boundaries', 'scenario_category' => 'Conflict of Interest', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'Your team wins a prestigious grant, but disagreements erupt over how to allocate the recognition, publication authorship, and cash prize. How do you design an equitable resolution model?', 'evaluation_focus' => 'Fairness & Equity Governance', 'scenario_category' => 'Reward Allocation', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'A sudden change in industry competition rules invalidates 3 weeks of your team\'s work. What communication and motivation strategy do you use to pivot the team toward a new solution rapidly?', 'evaluation_focus' => 'Agile Pivoting & Motivation in Setbacks', 'scenario_category' => 'Change Management', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'You are delegating responsibilities to a diverse team of engineering, design, and business students with very different working styles. How do you establish common working agreements and accountability rituals?', 'evaluation_focus' => 'Interdisciplinary Governance & Cross-functional Leadership', 'scenario_category' => 'Team Structuring', 'difficulty_level' => 'intermediate']
                ],
                // Pool 3 (Attempt 4)
                [
                    ['index' => 1, 'scenario' => 'A major sponsor offers your campus club funding on the condition that your team endorses an unvetted product. What ethical evaluation process do you lead with your board before responding?', 'evaluation_focus' => 'Ethical Governance & Brand Integrity', 'scenario_category' => 'Ethics in Leadership', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'During a live presentation, a teammate freezes due to intense stage fright. As team leader, how do you step in seamlessly without embarrassing them or compromising the presentation?', 'evaluation_focus' => 'In-the-moment Support & Presentation Control', 'scenario_category' => 'Crisis Support', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'Your committee must recruit and onboard 20 student volunteers within 5 days for a national conference. How do you organize role descriptions, delegation trees, and training?', 'evaluation_focus' => 'Rapid Onboarding & Organizational Scaling', 'scenario_category' => 'Operational Scaling', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'Your project team is suffering from chronic scope creep caused by changing demands from multiple stakeholders. How do you enforce scope boundaries and re-align milestone delivery?', 'evaluation_focus' => 'Scope Governance & Stakeholder Alignment', 'scenario_category' => 'Project Governance', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'As outgoing president of a student society, how do you conduct a systematic knowledge transfer and succession planning process to ensure the incoming leadership thrives?', 'evaluation_focus' => 'Succession Planning & Sustainable Knowledge Transfer', 'scenario_category' => 'Organizational Continuity', 'difficulty_level' => 'advanced']
                ]
            ];
        } elseif (str_contains($sn, 'communication') || str_contains($sn, 'presentation') || str_contains($sn, 'public speaking')) {
            $allPools = [
                // Pool 0
                [
                    ['index' => 1, 'scenario' => 'You need to explain a complex technical system and its architecture to non-technical stakeholders who have zero technical background. How do you structure your explanation and verify their comprehension?', 'evaluation_focus' => 'Clarity & Audience Adaptation', 'scenario_category' => 'Audience Adaptation', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'During a live presentation to industry mentors, a panelist aggressively challenges your team\'s core methodology. How do you compose yourself, address their criticism professionally, and keep the audience engaged?', 'evaluation_focus' => 'Poise Under Pressure & Diplomatic Defense', 'scenario_category' => 'Public Composure', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'An essential team communication was misunderstood, causing duplicate effort and wasted hours. What communication protocol would you implement to prevent misinterpretation in remote or hybrid teams?', 'evaluation_focus' => 'Message Verification & Protocol Design', 'scenario_category' => 'Protocol Design', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'You need to deliver critical constructive feedback to a peer whose work quality has dropped significantly without discouraging them. What exact conversational approach do you use?', 'evaluation_focus' => 'Constructive Feedback Delivery', 'scenario_category' => 'Feedback Delivery', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'Your cross-departmental student initiative requires buy-in from three reluctant department heads. How do you craft and deliver a persuasive pitch tailored to their individual priorities?', 'evaluation_focus' => 'Persuasive Communication & Stakeholder Alignment', 'scenario_category' => 'Persuasive Pitching', 'difficulty_level' => 'advanced']
                ],
                // Pool 1
                [
                    ['index' => 1, 'scenario' => 'A bug in your student portal accidentally exposed mock data to beta users. How do you draft an immediate transparent incident communication for affected users and department faculty?', 'evaluation_focus' => 'Crisis Communication & Transparency', 'scenario_category' => 'Incident Communication', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'You have only 3 minutes to pitch your startup or project prototype to a panel of venture capitalists. How do you structure your hook, problem-solution narrative, and call to action?', 'evaluation_focus' => 'Concise Narrative & Executive Pitching', 'scenario_category' => 'Executive Presentation', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'A remote team member from another time zone regularly misinterprets chat messages as rude or dismissive. How do you address tone, emotional intelligence, and cross-cultural communication norms?', 'evaluation_focus' => 'Tone Management & Cross-Cultural EQ', 'scenario_category' => 'Digital EQ', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'During a team meeting, two outspoken members dominate 90% of the discussion, while quieter teammates say nothing. What facilitation techniques do you use to balance voices and draw out diverse ideas?', 'evaluation_focus' => 'Inclusive Meeting Facilitation', 'scenario_category' => 'Facilitation', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'You must deliver bad news to a client or supervisor: a promised deadline cannot be met due to an unexpected blocker. What is your exact communication strategy to maintain credibility?', 'evaluation_focus' => 'Proactive Bad News Delivery & Solution Framing', 'scenario_category' => 'Client Management', 'difficulty_level' => 'advanced']
                ],
                // Pool 2 & 3 fallbacks
                [
                    ['index' => 1, 'scenario' => 'You are presenting technical findings to a hostile committee with contradictory expectations. How do you anchor your presentation in data while de-escalating hostility?', 'evaluation_focus' => 'Data-driven Argumentation & De-escalation', 'scenario_category' => 'Data Communication', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'You are writing comprehensive onboarding documentation for new team members on complex project guidelines. How do you structure the guide to ensure self-sufficiency and low cognitive load?', 'evaluation_focus' => 'Technical Writing & Information Architecture', 'scenario_category' => 'Documentation', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'How do you conduct an empathetic, active-listening interview with end-users who are deeply frustrated by current software usability issues?', 'evaluation_focus' => 'Active Listening & User Empathy', 'scenario_category' => 'User Research', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'In a high-stakes debate over project trade-offs, how do you synthesize opposing arguments without alienating either faction?', 'evaluation_focus' => 'Synthesis & Consensus Framing', 'scenario_category' => 'Mediation', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'You must represent your college in an inter-university panel discussion. What preparation and rhetorical techniques do you use to project confidence and articulate points clearly?', 'evaluation_focus' => 'Public Rhetoric & Professional Advocacy', 'scenario_category' => 'Public Representation', 'difficulty_level' => 'advanced']
                ],
                [
                    ['index' => 1, 'scenario' => 'How would you explain the business and career impact of your extracurricular achievements to an executive hiring manager in under 60 seconds?', 'evaluation_focus' => 'Elevator Pitch & Impact Translation', 'scenario_category' => 'Career Articulation', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'Your team has completed a semester-long project. What format, tone, and feedback mechanism do you design for the post-mortem retrospective to ensure psychological safety?', 'evaluation_focus' => 'Retrospective Facilitation & Psychological Safety', 'scenario_category' => 'Team Retrospective', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'An email you sent was perceived as accusatory by an external partner. What exact reply do you draft to de-escalate without capitulating on key project facts?', 'evaluation_focus' => 'Written De-escalation & Diplomatic Precision', 'scenario_category' => 'Written Diplomacy', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'How do you structure visual slides for a 20-minute presentation so that data enhances comprehension rather than cluttering cognitive bandwidth?', 'evaluation_focus' => 'Visual Communication & Slide Design', 'scenario_category' => 'Visual Storytelling', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'You are negotiating deliverables with a cross-functional department that is notorious for unresponsive communication. What multi-channel engagement cadence do you implement?', 'evaluation_focus' => 'Persistent Engagement & Multi-channel Outreach', 'scenario_category' => 'Stakeholder Outreach', 'difficulty_level' => 'intermediate']
                ]
            ];
        } elseif (str_contains($sn, 'problem') || str_contains($sn, 'critical thinking') || str_contains($sn, 'analytical')) {
            $allPools = [
                // Pool 0
                [
                    ['index' => 1, 'scenario' => 'Your application experiences an intermittent crash in production that only happens under specific user concurrency, but cannot be reproduced in your local development environment. What structured troubleshooting methodology do you follow?', 'evaluation_focus' => 'Systematic Root-Cause Analysis', 'scenario_category' => 'Root Cause Analysis', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'You are faced with two competing options for solving an organizational bottleneck: one is quick and cheap but introduces technical debt; the other is robust but requires three weeks of rework. How do you critically analyze and justify your choice?', 'evaluation_focus' => 'Trade-off Analysis & Strategic Judgment', 'scenario_category' => 'Strategic Trade-offs', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'Data from a student survey shows a 40% decline in extracurricular engagement, but the open text comments contradict each other. How do you analyze this data objectively to pinpoint the true underlying problem?', 'evaluation_focus' => 'Analytical Deduction & Signal Extraction', 'scenario_category' => 'Data Analysis', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'A critical automated process fails during a live hackathon showcase with 10 minutes left on the clock. You have no immediate documentation. What step-by-step diagnostic sequence do you follow?', 'evaluation_focus' => 'High-Stakes Emergency Triage', 'scenario_category' => 'Emergency Diagnostics', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'After implementing what appeared to be the correct solution to a logistical challenge, an unexpected secondary failure emerges in another department. How do you trace cascading impacts and redesign the system?', 'evaluation_focus' => 'Second-Order Thinking & Systemic Correction', 'scenario_category' => 'Systemic Correction', 'difficulty_level' => 'advanced']
                ],
                // Pool 1
                [
                    ['index' => 1, 'scenario' => 'Your campus event booking system allows two different organizers to reserve the same auditorium simultaneously during peak registration hours. How do you trace the underlying race condition and design a permanent algorithmic fix?', 'evaluation_focus' => 'Concurrency Logic & Algorithmic Correction', 'scenario_category' => 'Algorithmic Diagnostics', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'An automated recommendation algorithm is consistently recommending only high-budget workshops to students, ignoring verified free alternatives. How do you audit the scoring weights and eliminate the bias?', 'evaluation_focus' => 'Algorithm Auditing & Fairness Analysis', 'scenario_category' => 'Algorithmic Fairness', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'A dataset of student project evaluations contains 25% missing records and severe grade clustering at 85%. What statistical and analytical methods do you apply to normalize the evaluation scores fairly?', 'evaluation_focus' => 'Data Normalization & Quantitative Reasoning', 'scenario_category' => 'Quantitative Triage', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'You discover that an assumption your entire team has relied upon for six weeks is factually incorrect, and two major features are based on it. What rapid impact analysis and corrective plan do you formulate?', 'evaluation_focus' => 'Assumption Invalidation & Impact Analysis', 'scenario_category' => 'Strategic Recovery', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'You are optimizing an operations workflow with three bottlenecks: manual approvals, server response latency, and asynchronous document parsing. How do you systematically model which bottleneck to resolve first for maximum throughput?', 'evaluation_focus' => 'Theory of Constraints & Throughput Modeling', 'scenario_category' => 'Systems Optimization', 'difficulty_level' => 'intermediate']
                ],
                // Pool 2 & 3
                [
                    ['index' => 1, 'scenario' => 'Your application\'s database queries are consuming 100% CPU during morning peak hours. Outline your exact sequence for capturing slow query logs, inspecting query plans, and remediating bottlenecks.', 'evaluation_focus' => 'Query Profiling & Resource Diagnostics', 'scenario_category' => 'Performance Diagnostics', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'Two senior team members present fundamentally conflicting solutions to a critical infrastructure problem, both backed by selective data. How do you conduct an impartial, empirical experiment to decide?', 'evaluation_focus' => 'Empirical Hypothesis Testing', 'scenario_category' => 'Empirical Decision Making', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'A user reports that their saved profile data randomly reverts to older versions. Describe your end-to-end debugging hypothesis testing across client cache, session management, and database transactions.', 'evaluation_focus' => 'Multi-tier Debugging & State Tracing', 'scenario_category' => 'State Architecture', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'How would you prioritize 12 competing bug fixes when your team only has capacity to address 3 before the end of the sprint?', 'evaluation_focus' => 'Severity vs Effort Matrix Prioritization', 'scenario_category' => 'Defect Prioritization', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'Describe a structured post-incident review (PIR) methodology you would lead to ensure that a major software outage results in permanent systemic safeguards rather than finger-pointing.', 'evaluation_focus' => 'Blameless Post-Mortem & Preventative Safeguards', 'scenario_category' => 'Post-Incident Governance', 'difficulty_level' => 'advanced']
                ],
                [
                    ['index' => 1, 'scenario' => 'You need to diagnose a silent data corruption error where student grades are stored with truncated decimal precision. What data validation and migration plan do you execute?', 'evaluation_focus' => 'Data Integrity & Safe Patch Migration', 'scenario_category' => 'Data Integrity', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'A third-party authentication API fails intermittently every Friday afternoon with HTTP 504 Gateway Timeouts. How do you design resilient circuit breakers, fallbacks, and retry budgets?', 'evaluation_focus' => 'Fault Tolerance & Circuit Breaking', 'scenario_category' => 'Resilience Design', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'Your student organization wants to predict hackathon attendance using historical signups, exam schedules, and weather data. How do you evaluate whether predictive modeling is viable and what risks exist?', 'evaluation_focus' => 'Predictive Modeling Feasibility & Risk Analysis', 'scenario_category' => 'Predictive Risk', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'When faced with an ambiguous problem statement from a faculty client, what structured questioning framework do you use to extract verifiable requirements?', 'evaluation_focus' => 'Requirements Elicitation & Ambiguity Reduction', 'scenario_category' => 'Requirements Analysis', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'How do you verify whether a newly deployed software patch actually resolved the user complaints without introducing subtle regressions in adjacent modules?', 'evaluation_focus' => 'Regression Verification & Telemetry Validation', 'scenario_category' => 'Verification Testing', 'difficulty_level' => 'intermediate']
                ]
            ];
        } elseif (str_contains($sn, 'programming') || str_contains($sn, 'python') || str_contains($sn, 'java') || str_contains($sn, 'web') || str_contains($sn, 'database')) {
            $allPools = [
                // Pool 0
                [
                    ['index' => 1, 'scenario' => 'A web service you deployed is slowing down dramatically over time, eventually throwing OutOfMemory / 500 errors. How would you isolate whether this is a database lock, a memory leak in application code, or an unindexed query?', 'evaluation_focus' => 'Performance Profiling & Bottleneck Isolation', 'scenario_category' => 'Memory Profiling', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'You need to refactor a legacy monolithic function that is 800 lines long, tightly coupled, and has zero automated unit tests. What step-by-step approach do you take to safely refactor it without introducing regressions?', 'evaluation_focus' => 'Safe Refactoring & Testing Strategy', 'scenario_category' => 'Refactoring Strategy', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'Your team\'s database schema is experiencing race conditions where two simultaneous user transactions produce inconsistent inventory balances. What concurrency control techniques (e.g., locks, isolation levels, atomic operations) would you implement and why?', 'evaluation_focus' => 'Concurrency Control & Transaction Integrity', 'scenario_category' => 'Transaction Concurrency', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'A pull request from a peer works functionally but introduces severe security vulnerabilities (e.g., SQL injection risk, missing CSRF token, and unescaped HTML). How do you document and explain these risks in your code review, and propose safe patterns?', 'evaluation_focus' => 'Secure Coding Standards & Rigorous Code Review', 'scenario_category' => 'Security Code Review', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => 'You are designing the data model and API structure for an event registration platform that must handle sudden traffic spikes (5,000 requests per second). What caching, queuing, or pagination patterns would you adopt?', 'evaluation_focus' => 'Scalable System Architecture & API Design', 'scenario_category' => 'High-throughput Architecture', 'difficulty_level' => 'advanced']
                ],
                // Pool 1
                [
                    ['index' => 1, 'scenario' => 'An ORM query on your dashboard is causing an N+1 query problem, firing 400 SQL queries for a single page load. How do you identify, benchmark, and rewrite this into efficient eager-loaded or batch queries?', 'evaluation_focus' => 'ORM Optimization & Query Efficiency', 'scenario_category' => 'Query Optimization', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'You must alter a production database table containing 2 million rows to add a unique index without locking writes or causing downtime. What migration strategy and tools do you use?', 'evaluation_focus' => 'Zero-Downtime Database Migrations', 'scenario_category' => 'Schema Evolution', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'A background worker queue has accumulated 50,000 stuck jobs because of poison pill messages that fail on unhandled exceptions. How do you design dead-letter queues, idempotent workers, and replay policies?', 'evaluation_focus' => 'Idempotent Message Processing & Dead-Letter Queues', 'scenario_category' => 'Asynchronous Systems', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'Your frontend single-page application is leaking memory whenever users navigate between dashboard tabs, crashing mobile browsers. What browser profiling tools and DOM cleanup practices do you apply?', 'evaluation_focus' => 'Frontend Memory Leakage & Event Teardown', 'scenario_category' => 'Client-side Performance', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'You are implementing an external webhook consumer. What security checks (HMAC signature verification, replay attack prevention with nonces, and idempotent deduplication) do you establish?', 'evaluation_focus' => 'Webhook Security & Cryptographic Verification', 'scenario_category' => 'API Security', 'difficulty_level' => 'advanced']
                ],
                // Pool 2 & 3
                [
                    ['index' => 1, 'scenario' => 'A critical REST API endpoint returns inconsistent JSON error schemas across different failure modes. How do you standardize API contracts and error responses across a development team?', 'evaluation_focus' => 'API Contract Standardization & Error Modeling', 'scenario_category' => 'API Design', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => 'Describe your strategy for writing meaningful unit and integration tests for a service that heavily interacts with external third-party payments and email APIs.', 'evaluation_focus' => 'Test Doubles, Mocking & Boundary Testing', 'scenario_category' => 'Automated Testing', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => 'A team member pushed database credentials into a public Git repository. Describe your immediate containment, secret rotation, git history purging, and preventative pre-commit guard workflow.', 'evaluation_focus' => 'Secret Management & Security Incident Triage', 'scenario_category' => 'DevSecOps', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => 'Your application needs to support full-text search across 500,000 student project titles and descriptions. What architectural choices (indexes, search engines, query tokenization) do you weigh?', 'evaluation_focus' => 'Search Architecture & Inverted Indexing', 'scenario_category' => 'Data Search', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'Two microservices communicate synchronously via HTTP, causing cascading timeouts when the downstream service is degraded. How do you redesign this with asynchronous events and backpressure?', 'evaluation_focus' => 'Event-Driven Architecture & Backpressure Handling', 'scenario_category' => 'Distributed Systems', 'difficulty_level' => 'advanced']
                ],
                [
                    ['index' => 1, 'scenario' => 'You are tasked with securing user file uploads (PDF resumes and certificate images) against remote code execution, MIME spoofing, and directory traversal. What multi-layer validation pipeline do you build?', 'evaluation_focus' => 'Secure File Upload Pipelines', 'scenario_category' => 'Application Security', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => 'A microservice is throwing intermittent connection pool timeout exceptions under burst loads. How do you tune connection pool limits, timeouts, and health checks?', 'evaluation_focus' => 'Connection Pooling & Resource Limits', 'scenario_category' => 'Infrastructure Tuning', 'difficulty_level' => 'advanced'],
                    ['index' => 3, 'scenario' => 'How do you design an audit logging architecture that captures every sensitive profile change in an immutable, tamper-evident log table?', 'evaluation_focus' => 'Audit Logging & Data Lineage', 'scenario_category' => 'Compliance Architecture', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => 'Explain the architectural differences between optimistic locking (version columns) and pessimistic locking (SELECT FOR UPDATE) and when you would choose each in enterprise applications.', 'evaluation_focus' => 'Optimistic vs Pessimistic Concurrency', 'scenario_category' => 'Concurrency Models', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => 'Your CI/CD pipeline takes 45 minutes to run test suites, bottlenecking deployments. How would you profile test runtimes, parallelize test suites, and introduce test caching?', 'evaluation_focus' => 'CI/CD Optimization & Test Parallelization', 'scenario_category' => 'Build Optimization', 'difficulty_level' => 'intermediate']
                ]
            ];
        } else {
            // General skills fallback pools
            $allPools = [
                // Pool 0
                [
                    ['index' => 1, 'scenario' => "In applying {$skillName}, you face an unexpected breakdown in project deliverables when critical resources become unavailable. What specific steps do you take to stabilize deliverables?", 'evaluation_focus' => 'Resource Adaptability & Resilience', 'scenario_category' => 'Resource Crisis', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => "You are executing an initiative requiring {$skillName}. Two senior stakeholders have conflicting expectations. What exact steps do you take to align them?", 'evaluation_focus' => 'Stakeholder Alignment', 'scenario_category' => 'Stakeholder Management', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => "Explain a situation where a standard solution in {$skillName} proved ineffective. What innovative approach would you implement to overcome the limitation?", 'evaluation_focus' => 'Creative Problem Resolution', 'scenario_category' => 'Innovation', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => "When quality standards are threatened by an aggressive deadline, how do you leverage {$skillName} to protect core outputs within constraints?", 'evaluation_focus' => 'Quality Control Under Pressure', 'scenario_category' => 'Quality Governance', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => "How do you systematically evaluate whether your application of {$skillName} succeeded, and what metrics do you use to improve future iterations?", 'evaluation_focus' => 'Continuous Improvement & Metrics', 'scenario_category' => 'Outcome Measurement', 'difficulty_level' => 'advanced']
                ],
                // Pool 1
                [
                    ['index' => 1, 'scenario' => "You discover an unexpected compliance or procedural error in an ongoing initiative requiring {$skillName}. How do you contain the impact and remediate the issue?", 'evaluation_focus' => 'Compliance & Procedural Integrity', 'scenario_category' => 'Risk Remediation', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => "A project partner is resisting your proposed methodologies in {$skillName}. What collaborative framework do you use to demonstrate value and achieve alignment?", 'evaluation_focus' => 'Collaborative Influence & Value Demonstration', 'scenario_category' => 'Influence', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => "How do you tailor your execution of {$skillName} when working with a remote, asynchronous team across different academic departments?", 'evaluation_focus' => 'Asynchronous Execution & Coordination', 'scenario_category' => 'Asynchronous Coordination', 'difficulty_level' => 'intermediate'],
                    ['index' => 4, 'scenario' => "What risk mitigation checklist do you execute before rolling out a high-visibility deliverable dependent on {$skillName}?", 'evaluation_focus' => 'Proactive Risk Assessment & Mitigation', 'scenario_category' => 'Risk Mitigation', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => "Describe how you would mentor a junior student to develop applied proficiency in {$skillName} through structured experiential milestones.", 'evaluation_focus' => 'Knowledge Transfer & Developmental Mentorship', 'scenario_category' => 'Mentorship', 'difficulty_level' => 'advanced']
                ],
                // Pool 2 & 3
                [
                    ['index' => 1, 'scenario' => "You must deliver a complex project milestone in {$skillName} with only 48 hours notice. How do you triage scope and organize execution?", 'evaluation_focus' => 'Rapid Triage & Scope Pruning', 'scenario_category' => 'Rapid Triage', 'difficulty_level' => 'advanced'],
                    ['index' => 2, 'scenario' => "Explain how you maintain objective standards when assessing peer contributions in projects involving {$skillName}.", 'evaluation_focus' => 'Objective Evaluation & Fair Assessment', 'scenario_category' => 'Peer Assessment', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => "How do you handle unexpected client or supervisor scope changes that threaten the core methodology of {$skillName}?", 'evaluation_focus' => 'Scope Control & Methodological Defense', 'scenario_category' => 'Scope Negotiation', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => "What documentation standards do you establish to ensure that project workflows in {$skillName} remain maintainable by others?", 'evaluation_focus' => 'Documentation Architecture & Sustainability', 'scenario_category' => 'Documentation', 'difficulty_level' => 'intermediate'],
                    ['index' => 5, 'scenario' => "How do you incorporate critical user feedback to iteratively improve the quality of deliverables produced through {$skillName}?", 'evaluation_focus' => 'Iterative Refinement & User Feedback Loop', 'scenario_category' => 'Iterative Refinement', 'difficulty_level' => 'intermediate']
                ],
                [
                    ['index' => 1, 'scenario' => "You are tasked with presenting the business and career relevance of {$skillName} to junior students. What real-world case study would you craft?", 'evaluation_focus' => 'Real-world Conceptual Framing', 'scenario_category' => 'Conceptual Framing', 'difficulty_level' => 'intermediate'],
                    ['index' => 2, 'scenario' => "How do you maintain focus and output quality when applying {$skillName} across multiple competing priority initiatives simultaneously?", 'evaluation_focus' => 'Context Switching & Focus Preservation', 'scenario_category' => 'Attention Management', 'difficulty_level' => 'intermediate'],
                    ['index' => 3, 'scenario' => "Describe a situation where ethical boundaries must be balanced against high-pressure project targets in {$skillName}.", 'evaluation_focus' => 'Ethical Balance & Principled Decision Making', 'scenario_category' => 'Applied Ethics', 'difficulty_level' => 'advanced'],
                    ['index' => 4, 'scenario' => "What systematic root-cause methodology do you use when a project deliverable in {$skillName} fails to meet expected benchmarks?", 'evaluation_focus' => 'Systematic Root-Cause Investigation', 'scenario_category' => 'Benchmark Analysis', 'difficulty_level' => 'advanced'],
                    ['index' => 5, 'scenario' => "How do you celebrate and institutionalize project successes in {$skillName} to build team morale and shared competencies?", 'evaluation_focus' => 'Institutional Learning & Morale Building', 'scenario_category' => 'Culture Building', 'difficulty_level' => 'intermediate']
                ]
            ];
        }

        // Pick the pool matching this attempt
        $chosenPool = $allPools[$poolIndex] ?? ($allPools[0] ?? []);

        // Filter and verify against previous questions to guarantee non-repetition
        $finalScenarios = [];
        $usedTexts = [];

        // Collect all previous question texts into normalized comparison map
        $normalizedExclusions = [];
        foreach ($excludeQuestions as $ex) {
            $normalizedExclusions[] = strtolower(trim(preg_replace('/[^\w\s]/u', '', (string)$ex)));
        }

        foreach ($chosenPool as $item) {
            $itemNorm = strtolower(trim(preg_replace('/[^\w\s]/u', '', $item['scenario'])));
            $isDuplicate = false;
            foreach ($normalizedExclusions as $exNorm) {
                if ($itemNorm === $exNorm || (strlen($itemNorm) > 25 && str_contains($exNorm, substr($itemNorm, 0, 30)))) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate && !isset($usedTexts[$itemNorm])) {
                $finalScenarios[] = $item;
                $usedTexts[$itemNorm] = true;
            }
        }

        // If any scenario was duplicate and filtered out, fill from other pools
        if (count($finalScenarios) < 5) {
            foreach ($allPools as $pIdx => $otherPool) {
                if ($pIdx === $poolIndex) continue;
                foreach ($otherPool as $item) {
                    $itemNorm = strtolower(trim(preg_replace('/[^\w\s]/u', '', $item['scenario'])));
                    $isDuplicate = false;
                    foreach ($normalizedExclusions as $exNorm) {
                        if ($itemNorm === $exNorm || (strlen($itemNorm) > 25 && str_contains($exNorm, substr($itemNorm, 0, 30)))) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if (!$isDuplicate && !isset($usedTexts[$itemNorm])) {
                        $item['index'] = count($finalScenarios) + 1;
                        $finalScenarios[] = $item;
                        $usedTexts[$itemNorm] = true;
                        if (count($finalScenarios) >= 5) break 2;
                    }
                }
            }
        }

        // Ensure exactly 5 questions with correct sequential indexes
        $scenarios = [];
        foreach (array_slice($finalScenarios, 0, 5) as $i => $sc) {
            $sc['index'] = $i + 1;
            $scenarios[] = $sc;
        }

        // Final safety guarantee
        if (count($scenarios) < 5) {
            $scenarios = $allPools[0];
        }

        return [
            'skill'     => $skillName,
            'category'  => $categoryName,
            'questions' => $scenarios,
            'source'    => 'curated_benchmark_attempt_' . $attemptNumber
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

