<?php
/**
 * CareerAct — Module 8: AI-Powered Personalized Activity Recommendations Service
 * 
 * Recommends future extracurricular activity categories based on:
 * 1. Student's target career goal (student_career_goals -> careers)
 * 2. Validated skill gaps from Module 7 (ai_skill_gap_analyses)
 * 3. Latest completed assessment results from Module 6 (ai_skill_assessments)
 * 4. Preferred city and delivery format (student_profiles)
 * 
 * Strict Whitelist: Recommends ONLY valid activity categories from activity_categories.
 * Strict Separation: Never modifies or pollutes student_existing_activities or student_skills.
 * Persistence: Saves recommendations per student in student_personalized_recommendations.
 * Synchronization: Syncs with dashboard and legacy recommendation runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/gemini_service.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/event_discovery_service.php';

class RecommendationService
{
    private PDO $db;
    private GeminiService $gemini;

    public function __construct(PDO $db, ?GeminiService $gemini = null)
    {
        $this->db = $db;
        $this->gemini = $gemini ?? new GeminiService();
    }

    /**
     * Retrieves the student's target career goal
     */
    public function getStudentCareerGoal(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT scg.career_id, c.title AS career_title, c.description, cs.name AS subfield_name, cf.name AS field_name
            FROM student_career_goals scg
            JOIN careers c ON scg.career_id = c.id
            LEFT JOIN career_subfields cs ON c.subfield_id = cs.id
            LEFT JOIN career_fields cf ON cs.field_id = cf.id
            WHERE scg.user_id = :user_id
            ORDER BY scg.is_primary DESC, scg.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retrieves student's preferred city from profile
     */
    public function getStudentPreferredCity(int $userId): string
    {
        $stmt = $this->db->prepare("
            SELECT city FROM student_profiles WHERE user_id = :user_id LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $city = (string)$stmt->fetchColumn();
        return trim($city) !== '' ? trim($city) : 'Pune';
    }

    /**
     * Retrieves validated skill gaps strictly from Module 7 (ai_skill_gap_analyses)
     * Does NOT infer or fabricate any unassessed skills.
     */
    public function getStudentSkillGaps(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT ga.*, s.name AS skill_name, COALESCE(sc.name, 'General') AS category_name
            FROM ai_skill_gap_analyses ga
            JOIN skills s ON ga.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE ga.user_id = :user_id
            ORDER BY ga.id DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $gaps = [];
        $seenSkills = [];
        foreach ($rows as $r) {
            $sId = (int)$r['skill_id'];
            if (isset($seenSkills[$sId])) {
                continue;
            }
            $seenSkills[$sId] = true;

            $improvements = json_decode((string)$r['improvements_json'], true) ?: [];
            $practiceActions = json_decode((string)$r['practice_actions_json'], true) ?: [];

            $gaps[] = [
                'gap_analysis_id'    => (int)$r['id'],
                'assessment_id'      => (int)$r['assessment_id'],
                'skill_id'           => $sId,
                'skill_name'         => (string)$r['skill_name'],
                'category_name'      => (string)$r['category_name'],
                'gap_status'         => (string)$r['gap_status'],
                'score'              => (float)$r['overall_score'],
                'improvement_areas'  => $improvements,
                'practice_actions'   => $practiceActions,
                'created_at'         => $r['created_at']
            ];
        }

        return $gaps;
    }

    /**
     * Retrieves the latest completed assessment for a student
     */
    public function getLatestCompletedAssessment(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.id AS assessment_id,
                a.skill_id,
                s.name AS skill_name,
                a.overall_score,
                a.demonstration_level,
                a.completed_at
            FROM ai_skill_assessments a
            JOIN skills s ON a.skill_id = s.id
            WHERE a.user_id = :user_id AND a.status = 'completed'
            ORDER BY a.completed_at DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retrieves all active activity categories from the catalogue
     */
    public function getAllowedCategories(): array
    {
        $stmt = $this->db->query("
            SELECT id, code, name, description, is_active 
            FROM activity_categories 
            WHERE is_active = 1 
            ORDER BY id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculates the verified opportunity count for a category in the catalogue
     */
    public function getCategoryOpportunityCount(int $categoryId, string $city = ''): int
    {
        if ($city !== '') {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT o.id)
                FROM opportunities o
                JOIN activities a ON o.activity_id = a.id
                LEFT JOIN cities ci ON o.city_id = ci.id
                WHERE a.category_id = :category_id
                  AND o.status = 'published'
                  AND (o.delivery_mode = 'online' OR ci.name = :city OR ci.name IS NULL)
            ");
            $stmt->execute([':category_id' => $categoryId, ':city' => $city]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                return $count;
            }
        }

        // General published count across all locations
        $stmtAll = $this->db->prepare("
            SELECT COUNT(DISTINCT o.id)
            FROM opportunities o
            JOIN activities a ON o.activity_id = a.id
            WHERE a.category_id = :category_id AND o.status = 'published'
        ");
        $stmtAll->execute([':category_id' => $categoryId]);
        $oppCount = (int)$stmtAll->fetchColumn();

        if ($oppCount > 0) {
            return $oppCount;
        }

        // If no published opportunities yet, count available curated activity templates
        $stmtAct = $this->db->prepare("
            SELECT COUNT(*) FROM activities WHERE category_id = :category_id AND is_active = 1
        ");
        $stmtAct->execute([':category_id' => $categoryId]);
        return (int)$stmtAct->fetchColumn();
    }

    /**
     * Retrieves saved recommendations for a student from student_personalized_recommendations
     */
    public function getSavedRecommendations(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                spr.*,
                ac.name AS category_name,
                ac.description AS category_description
            FROM student_personalized_recommendations spr
            JOIN activity_categories ac ON spr.category_id = ac.id
            WHERE spr.user_id = :user_id
            ORDER BY spr.rank_priority ASC, spr.id ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['visual'] = getCategoryVisualMeta((string)$r['category_code']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Generates or retrieves personalized activity recommendations for a student
     */
    public function generatePersonalizedRecommendations(int $userId, bool $forceRefresh = false): array
    {
        // 1. Fetch student's target career goal
        $career = $this->getStudentCareerGoal($userId);
        if (!$career) {
            return [
                'status'          => 'no_career_goal',
                'recommendations' => [],
                'career'          => null,
                'basis'           => 'none',
                'message'         => 'No career goal selected. Please choose a target career goal first.'
            ];
        }

        $careerId = (int)$career['career_id'];
        $careerTitle = (string)$career['career_title'];

        // 2. Return cached recommendations if available and not forcing refresh
        if (!$forceRefresh) {
            $cached = $this->getSavedRecommendations($userId);
            if (!empty($cached)) {
                $first = $cached[0];
                return [
                    'status'          => 'success',
                    'recommendations' => $cached,
                    'career'          => $career,
                    'basis'           => $first['recommendation_basis'] ?? 'career_goal',
                    'source'          => 'cached'
                ];
            }
        }

        // 3. Inspect student's validated skill gaps and latest assessment
        $preferredCity = $this->getStudentPreferredCity($userId);
        $skillGaps = $this->getStudentSkillGaps($userId);
        $latestAssessment = $this->getLatestCompletedAssessment($userId);

        $assessmentId = $latestAssessment ? (int)$latestAssessment['assessment_id'] : null;
        $gapAnalysisId = (!empty($skillGaps) && isset($skillGaps[0]['gap_analysis_id'])) ? (int)$skillGaps[0]['gap_analysis_id'] : null;

        // Determine recommendation basis:
        // If student took Module 6 assessment & has gaps, basis is 'assessed_gap'
        // If student has no assessments, basis is 'career_goal'
        $hasGaps = !empty($skillGaps);
        $basis = $hasGaps ? 'assessed_gap' : 'career_goal';

        // 4. Retrieve allowed category catalog
        $allowedCategories = $this->getAllowedCategories();
        $codeToCategory = [];
        foreach ($allowedCategories as $ac) {
            $codeToCategory[strtoupper((string)$ac['code'])] = $ac;
        }

        // 5. Generate recommendations via GeminiService (with deterministic fallback)
        $rawRecs = $this->gemini->rankAndExplainActivityRecommendations(
            $careerTitle,
            $skillGaps,
            $allowedCategories,
            $preferredCity
        );

        // 6. Whitelist Validation & Mapping
        $validatedRecs = [];
        $rank = 1;
        $usedCodes = [];

        foreach ($rawRecs as $item) {
            $code = strtoupper(trim((string)($item['category_code'] ?? '')));
            if (!isset($codeToCategory[$code])) {
                continue; // Strictly reject categories not in catalogue
            }
            if (isset($usedCodes[$code])) {
                continue; // Avoid duplicate categories
            }
            $usedCodes[$code] = true;

            $catInfo = $codeToCategory[$code];
            $catId = (int)$catInfo['id'];
            $oppCount = $this->getCategoryOpportunityCount($catId, $preferredCity);

            // Match target gap skill if available
            $targetSkillName = trim((string)($item['target_skill'] ?? ''));
            $matchedGapSkillId = null;
            if ($hasGaps) {
                foreach ($skillGaps as $sg) {
                    if (strcasecmp($sg['skill_name'], $targetSkillName) === 0) {
                        $matchedGapSkillId = (int)$sg['skill_id'];
                        break;
                    }
                }
                // If not directly matched, associate with first gap if relevant
                if (!$matchedGapSkillId && !empty($skillGaps)) {
                    $matchedGapSkillId = (int)$skillGaps[0]['skill_id'];
                    if ($targetSkillName === '' || $targetSkillName === $careerTitle) {
                        $targetSkillName = $skillGaps[0]['skill_name'];
                    }
                }
            }

            $validatedRecs[] = [
                'user_id'                => $userId,
                'career_id'              => $careerId,
                'assessment_id'          => $assessmentId,
                'gap_analysis_id'        => $gapAnalysisId,
                'category_id'            => $catId,
                'category_code'          => $code,
                'category_name'          => (string)$catInfo['name'],
                'category_description'   => (string)$catInfo['description'],
                'rank_priority'          => $rank++,
                'relevance_label'        => trim((string)($item['relevance_label'] ?? ($hasGaps ? 'Skill-Gap Bridge' : 'Core Pathway'))),
                'target_gap_skill_id'    => $matchedGapSkillId,
                'target_gap_skill_name'  => $targetSkillName !== '' ? $targetSkillName : $careerTitle,
                'why_recommended'        => trim((string)($item['why_recommended'] ?? "Recommended for your {$careerTitle} progression.")),
                'skills_developed'       => trim((string)($item['skills_developed'] ?? 'Practical skills')),
                'practice_gain'          => trim((string)($item['practice_gain'] ?? 'Hands-on practical development.')),
                'suggested_next_step'    => trim((string)($item['suggested_next_step'] ?? "Explore opportunities in {$preferredCity} or online.")),
                'verified_opps_count'    => $oppCount,
                'recommendation_basis'   => $basis,
                'visual'                 => getCategoryVisualMeta($code)
            ];

            if (count($validatedRecs) >= 4) {
                break;
            }
        }

        // If Gemini returned fewer than 2 items, ensure fallback populated
        if (count($validatedRecs) < 2) {
            $fallbackRecs = $this->gemini->getCuratedActivityRecommendationsFallback(
                $careerTitle,
                $skillGaps,
                $allowedCategories,
                $preferredCity
            );
            foreach ($fallbackRecs as $fItem) {
                $fCode = strtoupper(trim((string)$fItem['category_code']));
                if (!isset($codeToCategory[$fCode]) || isset($usedCodes[$fCode])) {
                    continue;
                }
                $usedCodes[$fCode] = true;
                $fCatInfo = $codeToCategory[$fCode];
                $fCatId = (int)$fCatInfo['id'];
                $fOppCount = $this->getCategoryOpportunityCount($fCatId, $preferredCity);

                $validatedRecs[] = [
                    'user_id'                => $userId,
                    'career_id'              => $careerId,
                    'assessment_id'          => $assessmentId,
                    'gap_analysis_id'        => $gapAnalysisId,
                    'category_id'            => $fCatId,
                    'category_code'          => $fCode,
                    'category_name'          => (string)$fCatInfo['name'],
                    'category_description'   => (string)$fCatInfo['description'],
                    'rank_priority'          => $rank++,
                    'relevance_label'        => trim((string)$fItem['relevance_label']),
                    'target_gap_skill_id'    => !empty($skillGaps) ? (int)$skillGaps[0]['skill_id'] : null,
                    'target_gap_skill_name'  => trim((string)$fItem['target_skill']),
                    'why_recommended'        => trim((string)$fItem['why_recommended']),
                    'skills_developed'       => trim((string)$fItem['skills_developed']),
                    'practice_gain'          => trim((string)$fItem['practice_gain']),
                    'suggested_next_step'    => trim((string)$fItem['suggested_next_step']),
                    'verified_opps_count'    => $fOppCount,
                    'recommendation_basis'   => $basis,
                    'visual'                 => getCategoryVisualMeta($fCode)
                ];
            }
        }

        // 7. Persist to student_personalized_recommendations
        $this->db->beginTransaction();
        try {
            $delStmt = $this->db->prepare("DELETE FROM student_personalized_recommendations WHERE user_id = :user_id");
            $delStmt->execute([':user_id' => $userId]);

            $insStmt = $this->db->prepare("
                INSERT INTO student_personalized_recommendations (
                    user_id, career_id, assessment_id, gap_analysis_id,
                    category_id, category_code, rank_priority, relevance_label,
                    target_gap_skill_id, target_gap_skill_name, why_recommended,
                    skills_developed, practice_gain, suggested_next_step,
                    verified_opps_count, recommendation_basis, created_at, updated_at
                ) VALUES (
                    :user_id, :career_id, :assessment_id, :gap_analysis_id,
                    :category_id, :category_code, :rank_priority, :relevance_label,
                    :target_gap_skill_id, :target_gap_skill_name, :why_recommended,
                    :skills_developed, :practice_gain, :suggested_next_step,
                    :verified_opps_count, :recommendation_basis, NOW(), NOW()
                )
            ");

            foreach ($validatedRecs as $v) {
                $insStmt->execute([
                    ':user_id'               => $userId,
                    ':career_id'             => $careerId,
                    ':assessment_id'         => $v['assessment_id'],
                    ':gap_analysis_id'       => $v['gap_analysis_id'],
                    ':category_id'           => $v['category_id'],
                    ':category_code'         => $v['category_code'],
                    ':rank_priority'         => $v['rank_priority'],
                    ':relevance_label'       => $v['relevance_label'],
                    ':target_gap_skill_id'   => $v['target_gap_skill_id'],
                    ':target_gap_skill_name' => $v['target_gap_skill_name'],
                    ':why_recommended'       => $v['why_recommended'],
                    ':skills_developed'      => $v['skills_developed'],
                    ':practice_gain'         => $v['practice_gain'],
                    ':suggested_next_step'   => $v['suggested_next_step'],
                    ':verified_opps_count'   => $v['verified_opps_count'],
                    ':recommendation_basis'  => $v['recommendation_basis']
                ]);
            }

            // 8. Synchronize with legacy recommendation_runs and activity_recommendations for backwards compatibility
            $this->syncLegacyRecommendationTables($userId, $careerId, $validatedRecs);

            $this->db->commit();

            if (file_exists(__DIR__ . '/notification_service.php')) {
                require_once __DIR__ . '/notification_service.php';
                createNotification(
                    $this->db,
                    $userId,
                    'recommendations_ready',
                    'Activity Recommendations Ready',
                    "New career-aligned extracurricular activity recommendations are ready for you.",
                    'recommendations.php',
                    "recs_ready_c{$careerId}_u{$userId}_" . date('Ymd')
                );
            }
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error saving recommendations for user {$userId}: " . $e->getMessage());
            throw $e;
        }

        return [
            'status'          => 'success',
            'recommendations' => $validatedRecs,
            'career'          => $career,
            'basis'           => $basis,
            'source'          => 'generated'
        ];
    }

    /**
     * Synchronizes recommendations into legacy recommendation_runs & activity_recommendations
     * ensuring dashboard Section 12 and Stage 5 reflect real recommendations.
     */
    private function syncLegacyRecommendationTables(int $userId, int $careerId, array $recs): void
    {
        try {
            $insRun = $this->db->prepare("
                INSERT INTO recommendation_runs (
                    user_id, target_career_id, algorithm_version, trigger_event, params, created_at
                ) VALUES (
                    :user_id, :career_id, 'module8_gemini_v1', 'on_demand_assessment_gap', '{\"source\":\"module8\"}', NOW()
                )
            ");
            $insRun->execute([
                ':user_id'   => $userId,
                ':career_id' => $careerId
            ]);
            $runId = (int)$this->db->lastInsertId();

            if ($runId > 0 && !empty($recs)) {
                $insActRec = $this->db->prepare("
                    INSERT INTO activity_recommendations (
                        run_id, activity_id, primary_skill_id, prerequisites_met,
                        rank_no, path_step, total_score, gap_coverage, career_relevance, preference_fit, reason
                    ) VALUES (
                        :run_id, :activity_id, :primary_skill_id, 1,
                        :rank_no, :path_step, :total_score, :gap_coverage, :career_relevance, :preference_fit, :reason
                    ) ON DUPLICATE KEY UPDATE
                        total_score = VALUES(total_score),
                        reason = VALUES(reason)
                ");

                foreach ($recs as $r) {
                    // Pick a sample activity from that category if available
                    $stmtPickAct = $this->db->prepare("
                        SELECT id 
                        FROM activities 
                        WHERE category_id = :category_id AND is_active = 1 
                        ORDER BY id ASC LIMIT 1
                    ");
                    $stmtPickAct->execute([':category_id' => $r['category_id']]);
                    $sampleAct = $stmtPickAct->fetch(PDO::FETCH_ASSOC);

                    if ($sampleAct) {
                        $actId = (int)$sampleAct['id'];
                        $skillId = !empty($r['target_gap_skill_id']) ? (int)$r['target_gap_skill_id'] : null;
                        
                        $score = ($r['recommendation_basis'] === 'assessed_gap') ? 95.00 : 88.00;
                        $insActRec->execute([
                            ':run_id'           => $runId,
                            ':activity_id'      => $actId,
                            ':primary_skill_id' => $skillId,
                            ':rank_no'          => $r['rank_priority'],
                            ':path_step'        => $r['rank_priority'],
                            ':total_score'      => $score,
                            ':gap_coverage'     => ($r['recommendation_basis'] === 'assessed_gap') ? 90.00 : 70.00,
                            ':career_relevance' => 95.00,
                            ':preference_fit'   => 85.00,
                            ':reason'           => mb_substr($r['why_recommended'], 0, 850)
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            // Log warning but don't fail the primary Module 8 recommendation flow
            error_log("Legacy sync notice: " . $e->getMessage());
        }
    }

    /**
     * Diagnostics and personalization status helper for UI banners and prompts
     */
    public function getPersonalizationStatus(int $userId): array
    {
        $career = $this->getStudentCareerGoal($userId);
        $latestAssessment = $this->getLatestCompletedAssessment($userId);
        $skillGaps = $this->getStudentSkillGaps($userId);
        $savedRecs = $this->getSavedRecommendations($userId);

        $basis = 'none';
        if (!empty($savedRecs)) {
            $basis = $savedRecs[0]['recommendation_basis'] ?? 'career_goal';
        } elseif (!empty($skillGaps)) {
            $basis = 'assessed_gap';
        } elseif ($career) {
            $basis = 'career_goal';
        }

        return [
            'has_career_goal'       => ($career !== null),
            'career_goal'           => $career,
            'has_assessment'        => ($latestAssessment !== null),
            'latest_assessment'     => $latestAssessment,
            'has_gaps'              => (!empty($skillGaps)),
            'gap_count'             => count($skillGaps),
            'recommendations_count' => count($savedRecs),
            'recommendation_basis'  => $basis,
            'preferred_city'        => $this->getStudentPreferredCity($userId)
        ];
    }
}
