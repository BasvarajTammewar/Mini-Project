<?php
/**
 * CareerAct — Career Exploration & Recommendation Service
 * 
 * Manages career domain discovery, full-text & skill search, personalized matching,
 * competency comparison, and extracurricular pathway suggestions.
 */

declare(strict_types=1);

class CareerService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieves all active career fields (domains) with career counts
     */
    public function getAllCareerFields(): array
    {
        $sql = "
            SELECT 
                cf.id AS field_id,
                cf.code,
                cf.name AS field_name,
                cf.description,
                cf.sort_order,
                COUNT(c.id) AS career_count
            FROM career_fields cf
            LEFT JOIN career_subfields csf ON cf.id = csf.field_id AND csf.is_active = 1
            LEFT JOIN careers c ON csf.id = c.subfield_id AND c.is_active = 1
            WHERE cf.is_active = 1
            GROUP BY cf.id, cf.code, cf.name, cf.description, cf.sort_order
            ORDER BY cf.sort_order ASC, cf.name ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Searches and filters careers with personalized match scoring
     * 
     * @param array $filters ['q' => search query, 'field_id' => domain ID, 'level' => typical level, 'sort' => sort mode]
     * @param int $userId Authenticated student ID
     * @return array Array of career card records with match metrics
     */
    public function searchAndFilterCareers(array $filters, int $userId): array
    {
        $search = trim((string)($filters['q'] ?? ''));
        $fieldId = (int)($filters['field_id'] ?? 0);
        $level = trim((string)($filters['level'] ?? ''));
        $sort = trim((string)($filters['sort'] ?? 'match'));

        // 1. Retrieve student profile parameters for personalized matching
        $studentSkills = $this->getStudentSkillsMap($userId);
        $studentInterestFieldIds = $this->getStudentInterestFieldIds($userId);
        $studentGoalId = $this->getStudentPrimaryGoalId($userId);

        // 2. Base Query
        $sql = "
            SELECT 
                c.id AS career_id,
                c.title AS career_title,
                c.description,
                c.responsibilities,
                c.education_path,
                c.typical_level,
                c.employment_type,
                csf.id AS subfield_id,
                csf.name AS subfield_name,
                cf.id AS field_id,
                cf.name AS field_name,
                cf.code AS field_code
            FROM careers c
            JOIN career_subfields csf ON c.subfield_id = csf.id
            JOIN career_fields cf ON csf.field_id = cf.id
            WHERE c.is_active = 1
        ";

        $params = [];

        // Domain filter
        if ($fieldId > 0) {
            $sql .= " AND cf.id = :field_id";
            $params[':field_id'] = $fieldId;
        }

        // Typical level filter
        if (!empty($level) && in_array($level, ['entry', 'mid', 'senior'])) {
            $sql .= " AND (c.typical_level = :typical_level OR c.typical_level = 'any')";
            $params[':typical_level'] = $level;
        }

        // Keyword & Skill Search
        if (!empty($search)) {
            $searchTerm = '%' . $search . '%';
            $sql .= " AND (
                c.title LIKE :q1
                OR c.description LIKE :q2
                OR cf.name LIKE :q3
                OR csf.name LIKE :q4
                OR EXISTS (
                    SELECT 1 FROM career_alt_titles cat 
                    WHERE cat.career_id = c.id AND cat.title LIKE :q5
                )
                OR EXISTS (
                    SELECT 1 FROM career_skills cs 
                    JOIN skills s ON cs.skill_id = s.id
                    WHERE cs.career_id = c.id AND s.name LIKE :q6
                )
            )";
            $params[':q1'] = $searchTerm;
            $params[':q2'] = $searchTerm;
            $params[':q3'] = $searchTerm;
            $params[':q4'] = $searchTerm;
            $params[':q5'] = $searchTerm;
            $params[':q6'] = $searchTerm;
        }

        $sql .= " ORDER BY c.title ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $careers = $stmt->fetchAll();

        // 3. Batch load required skills for these careers
        $careerIds = array_column($careers, 'career_id');
        $careerSkillsMap = $this->getBatchCareerSkills($careerIds);

        // 4. Calculate personalized matching score for each career
        $results = [];
        foreach ($careers as $career) {
            $cId = (int)$career['career_id'];
            $reqSkills = $careerSkillsMap[$cId] ?? [];
            
            $matchData = $this->calculateCareerMatchScore(
                $career,
                $reqSkills,
                $studentSkills,
                $studentInterestFieldIds,
                $studentGoalId
            );

            $career['is_primary_goal'] = ($cId === $studentGoalId);
            $career['match_pct'] = $matchData['score'];
            $career['match_breakdown'] = $matchData['breakdown'];
            $career['match_level_label'] = $matchData['label'];
            $career['required_skills'] = array_slice($reqSkills, 0, 5);
            $career['total_required_skills_count'] = count($reqSkills);

            $results[] = $career;
        }

        // 5. Sorting
        if ($sort === 'match') {
            usort($results, function($a, $b) {
                // Primary goal first
                if ($a['is_primary_goal'] !== $b['is_primary_goal']) {
                    return $b['is_primary_goal'] ? 1 : -1;
                }
                // Then match score descending
                if ($b['match_pct'] !== $a['match_pct']) {
                    return $b['match_pct'] <=> $a['match_pct'];
                }
                return strcmp($a['career_title'], $b['career_title']);
            });
        } elseif ($sort === 'title') {
            usort($results, fn($a, $b) => strcmp($a['career_title'], $b['career_title']));
        } elseif ($sort === 'skills') {
            usort($results, fn($a, $b) => $b['total_required_skills_count'] <=> $a['total_required_skills_count']);
        }

        return $results;
    }

    /**
     * Retrieves full career details, skill comparisons, growth paths, and extracurricular activities
     * 
     * @param int $careerId Career ID
     * @param int $userId Authenticated student ID
     * @return array|null Detailed career payload or null if not found
     */
    public function getCareerDetails(int $careerId, int $userId): ?array
    {
        // 1. Fetch career record
        $stmt = $this->db->prepare("
            SELECT 
                c.*,
                csf.name AS subfield_name,
                cf.id AS field_id,
                cf.name AS field_name,
                cf.code AS field_code,
                cf.description AS field_description
            FROM careers c
            JOIN career_subfields csf ON c.subfield_id = csf.id
            JOIN career_fields cf ON csf.field_id = cf.id
            WHERE c.id = :id AND c.is_active = 1
        ");
        $stmt->execute([':id' => $careerId]);
        $career = $stmt->fetch();
        if (!$career) {
            return null;
        }

        // 2. Fetch alternative job titles
        $stmtAlt = $this->db->prepare("SELECT title FROM career_alt_titles WHERE career_id = :id");
        $stmtAlt->execute([':id' => $careerId]);
        $career['alt_titles'] = $stmtAlt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Fetch required skills with categories
        $stmtSkills = $this->db->prepare("
            SELECT 
                cs.skill_id,
                s.name AS skill_name,
                s.description AS skill_description,
                COALESCE(sc.name, 'General Competencies') AS category_name,
                cs.required_level,
                cs.importance
            FROM career_skills cs
            JOIN skills s ON cs.skill_id = s.id
            LEFT JOIN skill_categories sc ON s.category_id = sc.id
            WHERE cs.career_id = :career_id
            ORDER BY cs.importance DESC, cs.required_level DESC, s.name ASC
        ");
        $stmtSkills->execute([':career_id' => $careerId]);
        $requiredSkills = $stmtSkills->fetchAll();

        // 4. Fetch student's current skills
        $studentSkills = $this->getStudentSkillsMap($userId);

        // 5. Build detailed Skill Comparison (Possessed vs Gaps vs Unassessed)
        $comparison = $this->buildSkillComparison($requiredSkills, $studentSkills);
        $career['skill_comparison'] = $comparison;

        // 6. Fetch related careers from career_relations and subfield
        $career['related_careers'] = $this->getRelatedCareers($careerId, (int)$career['subfield_id']);

        // 7. Fetch career growth & progression ladder
        $career['growth_progression'] = $this->getCareerGrowthProgression($career);

        // 8. Fetch related extracurricular activities
        $career['extracurricular_activities'] = $this->getRelatedActivitiesForCareer($careerId, $userId);

        // 9. Check if student has selected this as their primary career goal
        $studentGoalId = $this->getStudentPrimaryGoalId($userId);
        $career['is_primary_goal'] = ($careerId === $studentGoalId);

        // 10. Overall compatibility score
        $studentInterestFieldIds = $this->getStudentInterestFieldIds($userId);
        $matchData = $this->calculateCareerMatchScore(
            $career,
            $requiredSkills,
            $studentSkills,
            $studentInterestFieldIds,
            $studentGoalId
        );
        $career['match_score'] = $matchData['score'];
        $career['match_label'] = $matchData['label'];
        $career['match_breakdown'] = $matchData['breakdown'];

        return $career;
    }

    /**
     * Sets a career as the student's primary career goal in student_career_goals
     */
    public function setPrimaryCareerGoal(int $userId, int $careerId): bool
    {
        // Verify career exists and is active
        $stmtCheck = $this->db->prepare("SELECT id FROM careers WHERE id = :id AND is_active = 1");
        $stmtCheck->execute([':id' => $careerId]);
        if (!$stmtCheck->fetch()) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            // Demote any existing primary goals for this user
            $stmtDemote = $this->db->prepare("
                UPDATE student_career_goals 
                SET is_primary = 0, primary_marker = NULL
                WHERE user_id = :user_id
            ");
            $stmtDemote->execute([':user_id' => $userId]);

            // Upsert the chosen career goal as primary
            $stmtUpsert = $this->db->prepare("
                INSERT INTO student_career_goals (user_id, career_id, is_primary, created_at, primary_marker)
                VALUES (:user_id, :career_id, 1, NOW(), :user_id2)
                ON DUPLICATE KEY UPDATE
                    is_primary = 1,
                    primary_marker = :user_id3
            ");
            $stmtUpsert->execute([
                ':user_id'  => $userId,
                ':career_id'=> $careerId,
                ':user_id2' => $userId,
                ':user_id3' => $userId
            ]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Failed to set primary career goal: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Builds skill comparison between career requirements and student's current profile
     */
    private function buildSkillComparison(array $requiredSkills, array $studentSkills): array
    {
        $possessed = [];
        $gaps = [];
        $unassessed = [];

        $totalReqPoints = 0;
        $earnedPoints = 0;

        foreach ($requiredSkills as $req) {
            $sId = (int)$req['skill_id'];
            $reqLevel = (int)$req['required_level'];
            $importance = (int)$req['importance'];
            $weight = max(1, $importance);
            $totalReqPoints += ($weight * $reqLevel);

            $hasSkill = isset($studentSkills[$sId]);
            $currentLevel = $hasSkill ? (int)$studentSkills[$sId]['level'] : 0;
            $source = $hasSkill ? (string)$studentSkills[$sId]['source'] : 'none';

            $item = [
                'skill_id'        => $sId,
                'skill_name'      => $req['skill_name'],
                'category_name'   => $req['category_name'],
                'required_level'  => $reqLevel,
                'current_level'   => $currentLevel,
                'importance'      => $importance,
                'source'          => $source,
                'is_met'          => ($currentLevel >= $reqLevel),
                'gap'             => max(0, $reqLevel - $currentLevel)
            ];

            if ($currentLevel >= $reqLevel) {
                $possessed[] = $item;
                $earnedPoints += ($weight * $reqLevel);
            } elseif ($currentLevel > 0) {
                // Partially acquired but below required level
                $gaps[] = $item;
                $earnedPoints += ($weight * $currentLevel);
            } else {
                // Not yet added or assessed
                $gaps[] = $item;
                $unassessed[] = $item;
            }
        }

        $readinessPct = ($totalReqPoints > 0) 
            ? (int)round(($earnedPoints / $totalReqPoints) * 100) 
            : 0;

        return [
            'readiness_pct'   => min(100, $readinessPct),
            'possessed_skills'=> $possessed,
            'gap_skills'      => $gaps,
            'unassessed_skills'=> $unassessed,
            'total_required'  => count($requiredSkills),
            'possessed_count' => count($possessed),
            'gap_count'       => count($gaps)
        ];
    }

    /**
     * Calculates personalized match score (0-100) based on skills, interests, and goals
     */
    private function calculateCareerMatchScore(
        array $career,
        array $reqSkills,
        array $studentSkills,
        array $studentInterestFieldIds,
        int $studentGoalId
    ): array {
        $cId = (int)($career['career_id'] ?? $career['id']);
        $fieldId = (int)$career['field_id'];

        // 1. Goal alignment (bonus if already selected)
        $isPrimaryGoal = ($cId === $studentGoalId);

        // 2. Interest domain alignment (25% weight)
        $hasDomainInterest = in_array($fieldId, $studentInterestFieldIds, true);
        $domainScore = $hasDomainInterest ? 25.0 : 5.0;

        // 3. Skill readiness alignment (65% weight)
        $totalSkillWeight = 0;
        $earnedSkillWeight = 0;

        foreach ($reqSkills as $req) {
            $sId = (int)$req['skill_id'];
            $reqLevel = (int)$req['required_level'];
            $weight = (int)($req['importance'] ?? 3);

            $totalSkillWeight += ($weight * $reqLevel);

            if (isset($studentSkills[$sId])) {
                $studLevel = (int)$studentSkills[$sId]['level'];
                $earnedSkillWeight += ($weight * min($reqLevel, $studLevel));
            }
        }

        $skillRatio = ($totalSkillWeight > 0) ? ($earnedSkillWeight / $totalSkillWeight) : 0.4;
        $skillScore = $skillRatio * 65.0;

        // 4. Base exploration baseline (10% weight)
        $baseScore = 10.0;

        $totalScore = $domainScore + $skillScore + $baseScore;
        if ($isPrimaryGoal) {
            $totalScore = max($totalScore, 85.0); // Boost active primary goal
        }

        $totalScore = round(min(98.0, max(15.0, $totalScore)), 0);

        $label = 'Moderate Match';
        if ($totalScore >= 80) {
            $label = 'High Match';
        } elseif ($totalScore < 45) {
            $label = 'Emerging Path';
        }

        return [
            'score' => (int)$totalScore,
            'label' => $label,
            'breakdown' => [
                'skills_score'   => round($skillScore, 1),
                'interest_score' => round($domainScore, 1),
                'domain_match'   => $hasDomainInterest
            ]
        ];
    }

    /**
     * Retrieves related careers based on database relations and domain
     */
    private function getRelatedCareers(int $careerId, int $subfieldId): array
    {
        // 1. Explicit relationships from career_relations table
        $stmtRel = $this->db->prepare("
            SELECT 
                c.id AS career_id,
                c.title AS career_title,
                c.description,
                c.typical_level,
                cf.name AS field_name,
                cr.relation_type
            FROM career_relations cr
            JOIN careers c ON cr.related_career_id = c.id
            JOIN career_subfields csf ON c.subfield_id = csf.id
            JOIN career_fields cf ON csf.field_id = cf.id
            WHERE cr.career_id = :career_id AND c.is_active = 1
            LIMIT 4
        ");
        $stmtRel->execute([':career_id' => $careerId]);
        $related = $stmtRel->fetchAll();

        // 2. If fewer than 3, supplement with peers in the same subfield
        if (count($related) < 3) {
            $excludeIds = array_merge([$careerId], array_column($related, 'career_id'));
            $placeholders = implode(',', array_map('intval', $excludeIds));

            $stmtPeers = $this->db->prepare("
                SELECT 
                    c.id AS career_id,
                    c.title AS career_title,
                    c.description,
                    c.typical_level,
                    cf.name AS field_name,
                    'related' AS relation_type
                FROM careers c
                JOIN career_subfields csf ON c.subfield_id = csf.id
                JOIN career_fields cf ON csf.field_id = cf.id
                WHERE c.subfield_id = :subfield_id AND c.id NOT IN ({$placeholders}) AND c.is_active = 1
                LIMIT 3
            ");
            $stmtPeers->execute([':subfield_id' => $subfieldId]);
            $peers = $stmtPeers->fetchAll();
            $related = array_merge($related, $peers);
        }

        return $related;
    }

    /**
     * Builds career growth and progression ladder
     */
    private function getCareerGrowthProgression(array $career): array
    {
        $typicalLevel = $career['typical_level'] ?? 'any';
        
        // Progression roles from career_relations
        $stmtProg = $this->db->prepare("
            SELECT c.id, c.title, c.typical_level
            FROM career_relations cr
            JOIN careers c ON cr.related_career_id = c.id
            WHERE cr.career_id = :career_id AND cr.relation_type = 'progression'
        ");
        $stmtProg->execute([':career_id' => (int)$career['id']]);
        $progressionRoles = $stmtProg->fetchAll();

        return [
            'typical_level'     => ucfirst($typicalLevel),
            'entry_stage'       => 'Associate / Junior ' . $career['title'],
            'mid_stage'         => $career['title'] . ' (Standard Core Practitioner)',
            'senior_stage'      => 'Senior / Principal ' . $career['title'],
            'leadership_stage'  => 'Lead Specialist / Domain Director',
            'progression_roles' => $progressionRoles
        ];
    }

    /**
     * Retrieves real extracurricular activities linked to this career
     */
    private function getRelatedActivitiesForCareer(int $careerId, int $userId): array
    {
        // 1. Direct career activities from career_activities table
        $stmt = $this->db->prepare("
            SELECT 
                a.id AS activity_id,
                a.title AS activity_title,
                a.description,
                a.delivery_mode,
                a.difficulty,
                a.typical_hours,
                COALESCE(ac.name, 'Campus Initiative') AS category_name,
                ca.relevance,
                CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END AS is_enrolled
            FROM career_activities ca
            JOIN activities a ON ca.activity_id = a.id
            LEFT JOIN activity_categories ac ON a.category_id = ac.id
            LEFT JOIN enrollments e ON a.id = e.activity_id AND e.user_id = :user_id
            WHERE ca.career_id = :career_id AND a.is_active = 1
            ORDER BY ca.relevance DESC, a.difficulty ASC
            LIMIT 4
        ");
        $stmt->execute([':career_id' => $careerId, ':user_id' => $userId]);
        $activities = $stmt->fetchAll();

        // 2. If fewer than 3, find activities linked to the career's required skills
        if (count($activities) < 3) {
            $existingIds = array_column($activities, 'activity_id');
            $placeholders = empty($existingIds) ? '0' : implode(',', array_map('intval', $existingIds));

            $stmtSkillActs = $this->db->prepare("
                SELECT DISTINCT
                    a.id AS activity_id,
                    a.title AS activity_title,
                    a.description,
                    a.delivery_mode,
                    a.difficulty,
                    a.typical_hours,
                    COALESCE(ac.name, 'Skill Workshop') AS category_name,
                    3 AS relevance,
                    CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END AS is_enrolled
                FROM career_skills cs
                JOIN activity_skills ask ON cs.skill_id = ask.skill_id
                JOIN activities a ON ask.activity_id = a.id
                LEFT JOIN activity_categories ac ON a.category_id = ac.id
                LEFT JOIN enrollments e ON a.id = e.activity_id AND e.user_id = :user_id
                WHERE cs.career_id = :career_id AND a.id NOT IN ({$placeholders}) AND a.is_active = 1
                LIMIT 3
            ");
            $stmtSkillActs->execute([':career_id' => $careerId, ':user_id' => $userId]);
            $moreActs = $stmtSkillActs->fetchAll();
            $activities = array_merge($activities, $moreActs);
        }

        return $activities;
    }

    /**
     * Helper: batch fetch required skills for a list of careers
     */
    private function getBatchCareerSkills(array $careerIds): array
    {
        if (empty($careerIds)) {
            return [];
        }

        $placeholders = implode(',', array_map('intval', $careerIds));
        $sql = "
            SELECT 
                cs.career_id,
                cs.skill_id,
                s.name AS skill_name,
                cs.required_level,
                cs.importance
            FROM career_skills cs
            JOIN skills s ON cs.skill_id = s.id
            WHERE cs.career_id IN ({$placeholders})
            ORDER BY cs.importance DESC, cs.required_level DESC
        ";
        $stmt = $this->db->query($sql);
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[(int)$row['career_id']][] = $row;
        }
        return $map;
    }

    /**
     * Helper: student skills map
     */
    private function getStudentSkillsMap(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT skill_id, level, source FROM student_skills WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $map = [];
        while ($r = $stmt->fetch()) {
            $map[(int)$r['skill_id']] = $r;
        }
        return $map;
    }

    /**
     * Helper: student interest field IDs
     */
    private function getStudentInterestFieldIds(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT field_id FROM student_interest_fields WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Helper: student primary goal ID
     */
    private function getStudentPrimaryGoalId(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT career_id FROM student_career_goals 
            WHERE user_id = :uid AND is_primary = 1 
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}
