<?php
/**
 * CareerAct — Master Idempotent Seeder for Biotechnology Domain
 * 
 * Safely seeds:
 * 1. Career Field: BIOTECH
 * 2. 7 Career Subfields
 * 3. 4 New Skill Categories + maps existing
 * 4. 26 Biotechnology Education Pathways
 * 5. 99 Biotechnology Skills + biotech_skill_details
 * 6. 84 Biotechnology Careers + biotech_career_details + career_skills
 * 7. 53 Extracurricular Activities + biotech_activity_details + activity_skills
 * 8. career_activities junction mapping
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDbConnection();
    echo "=======================================================\n";
    echo "   CAREERACT BIOTECHNOLOGY DOMAIN MASTER SEEDER\n";
    echo "=======================================================\n";

    // 1. Career Field
    $stmt = $pdo->prepare("SELECT id FROM career_fields WHERE code = 'BIOTECH' OR name LIKE '%Biotechnology%' LIMIT 1");
    $stmt->execute();
    $fieldId = $stmt->fetchColumn();

    if (!$fieldId) {
        $insertField = $pdo->prepare("INSERT INTO career_fields (code, name, description, sort_order, is_active) VALUES ('BIOTECH', 'Biotechnology & Life Sciences', 'Careers spanning biological research, biomanufacturing, clinical diagnostics, genetic engineering, agricultural biotechnology, and computational life sciences.', 10, 1)");
        $insertField->execute();
        $fieldId = (int)$pdo->lastInsertId();
        echo "Created Career Field: 'Biotechnology & Life Sciences' (ID: $fieldId)\n";
    } else {
        $fieldId = (int)$fieldId;
        echo "Found Existing Career Field: 'Biotechnology & Life Sciences' (ID: $fieldId)\n";
    }

    // 2. Career Subfields (7 Specialisations)
    $subfieldsList = [
        'Research and Development (R&D)',
        'Biopharmaceutical and Pharmaceutical Biotechnology',
        'Healthcare and Clinical Diagnostics',
        'Agricultural, Plant and Crop Biotechnology',
        'Industrial, Food and Environmental Biotechnology',
        'Bioinformatics, Computational Biology and Genomics',
        'Regulatory Affairs, Quality Assurance and Biotech Business'
    ];

    $subfieldMap = []; // name => id
    $checkSub = $pdo->prepare("SELECT id FROM career_subfields WHERE field_id = ? AND name = ? LIMIT 1");
    $insertSub = $pdo->prepare("INSERT INTO career_subfields (field_id, name, is_active) VALUES (?, ?, 1)");

    foreach ($subfieldsList as $sfName) {
        $checkSub->execute([$fieldId, $sfName]);
        $sfId = $checkSub->fetchColumn();
        if (!$sfId) {
            $insertSub->execute([$fieldId, $sfName]);
            $sfId = (int)$pdo->lastInsertId();
            echo "  + Subfield added: '$sfName' (ID: $sfId)\n";
        }
        $subfieldMap[$sfName] = (int)$sfId;
    }

    // 3. Skill Categories
    $categoriesList = [
        'Core biological sciences',
        'Laboratory and experimental skills',
        'Genetic engineering and biotechnology',
        'Biotechnology industrial & regulatory'
    ];

    $checkCat = $pdo->prepare("SELECT id FROM skill_categories WHERE name = ? LIMIT 1");
    $insertCat = $pdo->prepare("INSERT INTO skill_categories (name, is_active) VALUES (?, 1)");

    foreach ($categoriesList as $catName) {
        $checkCat->execute([$catName]);
        if (!$checkCat->fetchColumn()) {
            $insertCat->execute([$catName]);
            echo "  + Skill Category added: '$catName'\n";
        }
    }

    // Cache all skill categories
    $catMap = $pdo->query("SELECT LOWER(name) as lname, id FROM skill_categories")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 4. Seed Education Pathways (26 Degrees)
    $pathwaysData = require __DIR__ . '/biotech_data/education_pathways_data.php';
    echo "\nSeeding Biotechnology Education Pathways (" . count($pathwaysData) . " degrees)...\n";

    $checkPathway = $pdo->prepare("SELECT id FROM biotechnology_education_pathways WHERE degree_name = ? LIMIT 1");
    $insertPathway = $pdo->prepare("
        INSERT INTO biotechnology_education_pathways (
            degree_level, degree_name, specialisation, duration_years, eligibility,
            core_subjects, lab_training, specialisation_areas, career_opportunities,
            higher_study_options, dissertation_or_proposal, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $updatePathway = $pdo->prepare("
        UPDATE biotechnology_education_pathways SET
            degree_level = ?, specialisation = ?, duration_years = ?, eligibility = ?,
            core_subjects = ?, lab_training = ?, specialisation_areas = ?, career_opportunities = ?,
            higher_study_options = ?, dissertation_or_proposal = ?, is_active = 1
        WHERE id = ?
    ");

    $pathwayCount = 0;
    foreach ($pathwaysData as $p) {
        $checkPathway->execute([$p['degree_name']]);
        $existingPId = $checkPathway->fetchColumn();
        if ($existingPId) {
            $updatePathway->execute([
                $p['degree_level'], $p['specialisation'], $p['duration_years'], $p['eligibility'],
                $p['core_subjects'], $p['lab_training'], $p['specialisation_areas'], $p['career_opportunities'],
                $p['higher_study_options'], $p['dissertation_or_proposal'], $existingPId
            ]);
        } else {
            $insertPathway->execute([
                $p['degree_level'], $p['degree_name'], $p['specialisation'], $p['duration_years'], $p['eligibility'],
                $p['core_subjects'], $p['lab_training'], $p['specialisation_areas'], $p['career_opportunities'],
                $p['higher_study_options'], $p['dissertation_or_proposal']
            ]);
        }
        $pathwayCount++;
    }
    echo "  -> Processed $pathwayCount education pathways successfully.\n";

    // 5. Seed Skills & biotech_skill_details (99 Skills)
    $skillsData = require __DIR__ . '/biotech_data/skills_data.php';
    echo "\nSeeding Biotechnology Skills (" . count($skillsData) . " skills)...\n";

    $checkSkill = $pdo->prepare("SELECT id FROM skills WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $insertSkill = $pdo->prepare("INSERT INTO skills (category_id, name, description, skill_type, source_id, is_active) VALUES (?, ?, ?, ?, 1, 1)");
    $upsertDetail = $pdo->prepare("
        INSERT INTO biotech_skill_details (
            skill_id, specialisation, proficiency_beginner, proficiency_intermediate,
            proficiency_advanced, practical_examples, relevant_degrees, career_roles,
            extracurricular_activities, resume_evidence, improvement_activities
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            specialisation = VALUES(specialisation),
            proficiency_beginner = VALUES(proficiency_beginner),
            proficiency_intermediate = VALUES(proficiency_intermediate),
            proficiency_advanced = VALUES(proficiency_advanced),
            practical_examples = VALUES(practical_examples),
            relevant_degrees = VALUES(relevant_degrees),
            career_roles = VALUES(career_roles),
            extracurricular_activities = VALUES(extracurricular_activities),
            resume_evidence = VALUES(resume_evidence),
            improvement_activities = VALUES(improvement_activities)
    ");

    $skillIdMap = []; // lower_name => id
    $skillCount = 0;
    foreach ($skillsData as $s) {
        $checkSkill->execute([$s['name']]);
        $sId = $checkSkill->fetchColumn();

        if (!$sId) {
            $catId = $catMap[strtolower($s['category_name'])] ?? 1;
            $insertSkill->execute([
                $catId,
                $s['name'],
                $s['description'],
                $s['skill_type'] ?? 'technical'
            ]);
            $sId = (int)$pdo->lastInsertId();
        } else {
            $sId = (int)$sId;
        }

        $skillIdMap[strtolower($s['name'])] = $sId;

        // Upsert biotech_skill_details
        $upsertDetail->execute([
            $sId,
            $s['specialisation'] ?? 'Biotechnology Core',
            $s['proficiency_beginner'] ?? '',
            $s['proficiency_intermediate'] ?? '',
            $s['proficiency_advanced'] ?? '',
            $s['practical_examples'] ?? '',
            $s['relevant_degrees'] ?? '',
            $s['career_roles'] ?? '',
            $s['extracurricular_activities'] ?? '',
            $s['resume_evidence'] ?? '',
            $s['improvement_activities'] ?? ''
        ]);
        $skillCount++;
    }
    echo "  -> Processed $skillCount skills & detail records successfully.\n";

    // Also cache any existing generic skills
    $allSkills = $pdo->query("SELECT LOWER(name) as lname, id FROM skills")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($allSkills as $k => $v) {
        $skillIdMap[$k] = (int)$v;
    }

    // 6. Seed Careers & biotech_career_details (84 Careers)
    $careersData = require __DIR__ . '/biotech_data/careers_data.php';
    echo "\nSeeding Biotechnology Careers (" . count($careersData) . " careers)...\n";

    $checkCareer = $pdo->prepare("SELECT id FROM careers WHERE LOWER(title) = LOWER(?) LIMIT 1");
    $insertCareer = $pdo->prepare("
        INSERT INTO careers (
            subfield_id, title, description, responsibilities, education_path,
            typical_level, employment_type, source_id, verification_status, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'verified', 1)
    ");
    $updateCareer = $pdo->prepare("
        UPDATE careers SET
            subfield_id = ?, description = ?, responsibilities = ?, education_path = ?,
            typical_level = ?, employment_type = ?, is_active = 1
        WHERE id = ?
    ");
    $upsertCareerDetail = $pdo->prepare("
        INSERT INTO biotech_career_details (
            career_id, specialisation, min_education_level, preferred_education_level,
            role_orientation, practical_projects, career_progression, resume_evidence,
            higher_study_options
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            specialisation = VALUES(specialisation),
            min_education_level = VALUES(min_education_level),
            preferred_education_level = VALUES(preferred_education_level),
            role_orientation = VALUES(role_orientation),
            practical_projects = VALUES(practical_projects),
            career_progression = VALUES(career_progression),
            resume_evidence = VALUES(resume_evidence),
            higher_study_options = VALUES(higher_study_options)
    ");

    $insertCareerSkill = $pdo->prepare("
        INSERT IGNORE INTO career_skills (career_id, skill_id, required_level, importance, source_id)
        VALUES (?, ?, ?, ?, 1)
    ");

    $careerIdMap = []; // lower_title => id
    $careerCount = 0;
    foreach ($careersData as $c) {
        $subId = $subfieldMap[$c['subfield_name']] ?? $subfieldMap['Research and Development (R&D)'];

        $checkCareer->execute([$c['title']]);
        $cId = $checkCareer->fetchColumn();

        if ($cId) {
            $cId = (int)$cId;
            $updateCareer->execute([
                $subId,
                $c['description'],
                $c['responsibilities'],
                $c['education_path'],
                $c['typical_level'],
                $c['employment_type'],
                $cId
            ]);
        } else {
            $insertCareer->execute([
                $subId,
                $c['title'],
                $c['description'],
                $c['responsibilities'],
                $c['education_path'],
                $c['typical_level'],
                $c['employment_type']
            ]);
            $cId = (int)$pdo->lastInsertId();
        }

        $careerIdMap[strtolower($c['title'])] = $cId;

        // Upsert biotech_career_details
        $upsertCareerDetail->execute([
            $cId,
            $c['subfield_name'],
            $c['min_education_level'] ?? 'undergraduate',
            $c['preferred_education_level'] ?? 'postgraduate',
            $c['role_orientation'] ?? 'Laboratory-oriented',
            $c['practical_projects'] ?? '',
            $c['career_progression'] ?? '',
            $c['resume_evidence'] ?? '',
            $c['higher_study_options'] ?? ''
        ]);

        // Link required skills
        if (!empty($c['required_skills']) && is_array($c['required_skills'])) {
            foreach ($c['required_skills'] as $skillName => $level) {
                $skId = $skillIdMap[strtolower($skillName)] ?? null;
                if ($skId) {
                    $insertCareerSkill->execute([$cId, $skId, (int)$level, 5]);
                }
            }
        }

        $careerCount++;
    }
    echo "  -> Processed $careerCount careers & linked skills successfully.\n";

    // 7. Seed Activities & biotech_activity_details (53 Activities)
    $activitiesData = require __DIR__ . '/biotech_data/activities_data.php';
    echo "\nSeeding Biotechnology Extracurricular Activities (" . count($activitiesData) . " activities)...\n";

    $actCatMap = $pdo->query("SELECT code, id FROM activity_categories")->fetchAll(PDO::FETCH_KEY_PAIR);

    $checkActivity = $pdo->prepare("SELECT id FROM activities WHERE LOWER(title) = LOWER(?) LIMIT 1");
    $insertActivity = $pdo->prepare("
        INSERT INTO activities (
            category_id, title, description, delivery_mode, difficulty,
            typical_hours, eligibility, prerequisites, learning_outcomes,
            evidence_expected, source_id, verification_status, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'verified', 1)
    ");
    $updateActivity = $pdo->prepare("
        UPDATE activities SET
            category_id = ?, description = ?, delivery_mode = ?, difficulty = ?,
            typical_hours = ?, eligibility = ?, prerequisites = ?, learning_outcomes = ?,
            evidence_expected = ?, is_active = 1
        WHERE id = ?
    ");

    $upsertActivityDetail = $pdo->prepare("
        INSERT INTO biotech_activity_details (
            activity_id, specialisation, suitable_degree_levels, deliverables,
            resume_achievements, biosafety_guidelines
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            specialisation = VALUES(specialisation),
            suitable_degree_levels = VALUES(suitable_degree_levels),
            deliverables = VALUES(deliverables),
            resume_achievements = VALUES(resume_achievements),
            biosafety_guidelines = VALUES(biosafety_guidelines)
    ");

    $insertActSkill = $pdo->prepare("
        INSERT IGNORE INTO activity_skills (activity_id, skill_id, gain_level, source_id)
        VALUES (?, ?, ?, 1)
    ");

    $activityIdMap = []; // lower_title => id
    $activityListForMapping = []; // array of [id, specialisation, skills]
    $activityCount = 0;

    foreach ($activitiesData as $a) {
        $catId = $actCatMap[$a['category_code']] ?? ($actCatMap['PROJECT'] ?? 2);

        $checkActivity->execute([$a['title']]);
        $actId = $checkActivity->fetchColumn();

        $desc = "Extracurricular practical activity in {$a['specialisation']}. " . $a['learning_outcomes'];

        if ($actId) {
            $actId = (int)$actId;
            $updateActivity->execute([
                $catId,
                $desc,
                $a['delivery_mode'] ?? 'offline',
                $a['difficulty'] ?? 3,
                $a['typical_hours'] ?? 40,
                $a['eligibility'] ?? 'Open to life sciences students',
                $a['prerequisites'] ?? 'Basic biological sciences',
                $a['learning_outcomes'] ?? '',
                $a['evidence_expected'] ?? '',
                $actId
            ]);
        } else {
            $insertActivity->execute([
                $catId,
                $a['title'],
                $desc,
                $a['delivery_mode'] ?? 'offline',
                $a['difficulty'] ?? 3,
                $a['typical_hours'] ?? 40,
                $a['eligibility'] ?? 'Open to life sciences students',
                $a['prerequisites'] ?? 'Basic biological sciences',
                $a['learning_outcomes'] ?? '',
                $a['evidence_expected'] ?? ''
            ]);
            $actId = (int)$pdo->lastInsertId();
        }

        $activityIdMap[strtolower($a['title'])] = $actId;
        $activityListForMapping[] = [
            'id' => $actId,
            'specialisation' => $a['specialisation'],
            'skills' => array_keys($a['skills_gained'] ?? [])
        ];

        // Upsert biotech_activity_details
        $upsertActivityDetail->execute([
            $actId,
            $a['specialisation'] ?? 'Biotechnology',
            $a['suitable_degree_levels'] ?? 'B.Sc., M.Sc., Ph.D.',
            $a['deliverables'] ?? '',
            $a['resume_achievements'] ?? '',
            $a['biosafety_guidelines'] ?? ''
        ]);

        // Link skills gained
        if (!empty($a['skills_gained']) && is_array($a['skills_gained'])) {
            foreach ($a['skills_gained'] as $skillName => $gain) {
                $skId = $skillIdMap[strtolower($skillName)] ?? null;
                if ($skId) {
                    $insertActSkill->execute([$actId, $skId, (int)$gain]);
                }
            }
        }

        $activityCount++;
    }
    echo "  -> Processed $activityCount activities & linked skills successfully.\n";

    // 8. Link career_activities junction
    echo "\nLinking Career-Activity Junctions (career_activities)...\n";
    $insertCareerAct = $pdo->prepare("
        INSERT IGNORE INTO career_activities (career_id, activity_id, relevance, basis, source_id)
        VALUES (?, ?, ?, 'curated_career', 1)
    ");

    $linkedCount = 0;
    foreach ($careersData as $c) {
        $cId = $careerIdMap[strtolower($c['title'])] ?? null;
        if (!$cId) continue;

        $cSpec = $c['subfield_name'];
        $cSkills = array_keys($c['required_skills'] ?? []);

        foreach ($activityListForMapping as $actItem) {
            $isSameSpec = (strcasecmp($actItem['specialisation'], $cSpec) === 0);
            $skillOverlap = count(array_intersect(array_map('strtolower', $cSkills), array_map('strtolower', $actItem['skills'])));

            if ($isSameSpec || $skillOverlap >= 2) {
                $relevance = $isSameSpec ? 5 : ($skillOverlap >= 2 ? 4 : 3);
                $insertCareerAct->execute([$cId, $actItem['id'], $relevance]);
                $linkedCount++;
            }
        }
    }
    echo "  -> Created/Verified $linkedCount career-activity linkages.\n";

    echo "\n=======================================================\n";
    echo "   BIOTECHNOLOGY DOMAIN SEEDING COMPLETED SUCCESSFULLY!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "\nFATAL SEEDING ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
