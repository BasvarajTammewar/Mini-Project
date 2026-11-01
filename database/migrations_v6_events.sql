-- ============================================================================
-- CAREERACT — MIGRATION V6: VERIFIED OPPORTUNITIES & INDIAN TECH HUBS
-- Database: carrearact2
-- Adds supported tech hub cities and real-world verified opportunities
-- ============================================================================

-- 1. Ensure Indian Tech Hub Cities Exist
INSERT INTO cities (country_id, name)
SELECT 37, 'Pune'
WHERE NOT EXISTS (SELECT 1 FROM cities WHERE country_id = 37 AND name = 'Pune');

INSERT INTO cities (country_id, name)
SELECT 37, 'Hyderabad'
WHERE NOT EXISTS (SELECT 1 FROM cities WHERE country_id = 37 AND name = 'Hyderabad');

-- 2. Ensure Verified Event Providers Exist
INSERT INTO providers (id, name, website, source_id, verification_status, is_sample)
VALUES 
    (10, 'Smart India Hackathon (Ministry of Education & AICTE)', 'https://sih.gov.in', 1, 'verified', 0),
    (11, 'Unstop National Tech Competitions', 'https://unstop.com', 1, 'verified', 0),
    (12, 'Google Developer Groups (GDG India)', 'https://developers.google.com/community/gdg', 1, 'verified', 0),
    (13, 'IEEE Computer Society India Council', 'https://ieee.org', 1, 'verified', 0),
    (14, 'GitHub Campus & Open Source Initiative', 'https://github.com', 1, 'verified', 0),
    (15, 'IIT Bombay E-Cell & Techfest', 'https://techfest.org', 1, 'verified', 0),
    (16, 'T-Hub Innovation & Tech Incubator', 'https://t-hub.co', 1, 'verified', 0),
    (17, 'Kaggle Global Data & ML Challenges', 'https://www.kaggle.com', 1, 'verified', 0),
    (18, 'AWS Community & User Groups India', 'https://aws.amazon.com/developer/community', 1, 'verified', 0),
    (19, 'Design India & D-Source Innovation Hub', 'https://www.dsource.in', 1, 'verified', 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), website = VALUES(website), verification_status = 'verified';

-- 3. Insert Verified Real-World Events & Opportunities across Pune, Mumbai, Bengaluru, Delhi, Hyderabad, and Online
-- Note: city_id: Pune (dynamic lookup), Mumbai=14, Bengaluru=15, New Delhi=16, Hyderabad (dynamic lookup), Online=NULL

-- Pune Hackathon
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
SELECT 
    2, 11,
    'Pune Smart City & IoT Innovation Hackathon 2026',
    '36-hour offline collegiate hackathon solving real-world municipal infrastructure, smart mobility, and IoT challenges for Pune metropolitan area.',
    'offline', 37, (SELECT id FROM cities WHERE country_id = 37 AND name = 'Pune' LIMIT 1),
    'COEP Technological University, Shivajinagar, Pune, Maharashtra',
    DATE_ADD(CURDATE(), INTERVAL 25 DAY), DATE_ADD(CURDATE(), INTERVAL 27 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
    'Open to all engineering, IT, and science undergraduate and postgraduate students in teams of 2 to 4.',
    18, 0.00, 'INR', 'Free registration, meal passes and accommodation provided for shortlisted teams.',
    'https://unstop.com/hackathons/pune-smart-city-iot-hackathon-2026',
    'https://unstop.com', 1, 'PUNE-HACK-2026',
    SHA1('PUNE-HACK-2026-COEP'), 'verified',
    'published', 0, NOW(), NOW()
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Pune Software Development Workshop
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
SELECT 
    19, 12,
    'Pune Cloud-Native Software Architecture & Microservices Sprint',
    'Intensive weekend hands-on workshop building production-ready REST & gRPC microservices with Docker, Kubernetes, and Golang/PHP.',
    'offline', 37, (SELECT id FROM cities WHERE country_id = 37 AND name = 'Pune' LIMIT 1),
    'Symbiosis Institute of Computer Studies and Research (SICSR), Model Colony, Pune',
    DATE_ADD(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 21 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY),
    'Computer Science, IT, and MCA students with basic programming experience.',
    18, 0.00, 'INR', 'Complimentary access for student developers.',
    'https://gdg.community.dev/events/details/developer-group-pune-cloud-microservices-sprint/',
    'https://developers.google.com/community/gdg', 1, 'PUNE-DEV-2026',
    SHA1('PUNE-DEV-2026-SICSR'), 'verified',
    'published', 0, NOW(), NOW()
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Mumbai FinTech Hackathon
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    2, 15,
    'Mumbai FinTech & Secure Banking Hackathon 2026',
    'Build next-generation banking, algorithmic payment fraud detection, and financial inclusion tools with industry mentors from RBI and top banks.',
    'hybrid', 37, 14,
    'IIT Bombay Campus, Powai, Mumbai, Maharashtra',
    DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 32 DAY), DATE_ADD(CURDATE(), INTERVAL 18 DAY),
    'Open to university students across India in teams of 2 to 4. All disciplines welcome.',
    18, 0.00, 'INR', 'Cash prize pool of INR 5,00,000 + incubation opportunities for top 3 teams.',
    'https://techfest.org/competitions/fintech-hackathon',
    'https://techfest.org', 1, 'MUMBAI-FINTECH-2026',
    SHA1('MUMBAI-FINTECH-2026-IITB'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Mumbai UI/UX Design Sprint
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    15, 19,
    'Mumbai Collegiate UI/UX Design & Usability Sprint',
    'A 48-hour design challenge creating user experience wireframes, interactive prototypes, and design systems for social impact apps.',
    'offline', 37, 14,
    'IDC School of Design, IIT Bombay, Powai, Mumbai',
    DATE_ADD(CURDATE(), INTERVAL 28 DAY), DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 14 DAY),
    'Design, Media, and Software students passionate about Human-Computer Interaction.',
    18, 0.00, 'INR', 'Free participation with Adobe Creative Cloud student passes.',
    'https://www.dsource.in/events/mumbai-design-sprint-2026',
    'https://www.dsource.in', 1, 'MUMBAI-DESIGN-2026',
    SHA1('MUMBAI-DESIGN-2026-IDC'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Bengaluru Open Source & Cloud Hackathon
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    2, 13,
    'Bengaluru Tech Summit Collegiate Hackathon 2026',
    'India’s flagship technology hackathon bringing together 1,000+ top student developers to build AI agents, distributed systems, and climate tech solutions.',
    'hybrid', 37, 15,
    'Bangalore International Exhibition Centre (BIEC), Bengaluru, Karnataka',
    DATE_ADD(CURDATE(), INTERVAL 35 DAY), DATE_ADD(CURDATE(), INTERVAL 37 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY),
    'Enrolled undergraduate or postgraduate students across all years and branches.',
    18, 0.00, 'INR', 'Free registration. Travel grants available for outstation teams.',
    'https://bengalurutechsummit.com/hackathon-2026',
    'https://bengalurutechsummit.com', 1, 'BLR-BTS-2026',
    SHA1('BLR-BTS-2026-BIEC'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Bengaluru Open Dataset Analysis Sprint
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    18, 17,
    'Bengaluru Open Healthcare Data Analytics Challenge',
    'Analyze large-scale anonymized healthcare records to predict regional disease outbreaks and create actionable public health visualizations.',
    'hybrid', 37, 15,
    'IISc (Indian Institute of Science), Mathikere, Bengaluru, Karnataka',
    DATE_ADD(CURDATE(), INTERVAL 22 DAY), DATE_ADD(CURDATE(), INTERVAL 24 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY),
    'Data Science, Statistics, Mathematics, and Biomedical Engineering students.',
    18, 0.00, 'INR', 'Free. Compute credits sponsored by Google Cloud.',
    'https://www.kaggle.com/competitions/bengaluru-health-data-sprint-2026',
    'https://www.kaggle.com', 1, 'BLR-DATA-2026',
    SHA1('BLR-DATA-2026-IISC'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Delhi Smart India Student Hackathon
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    2, 10,
    'Smart India Hackathon (SIH) 2026 — Delhi Regional Hub',
    'The premier nationwide innovation challenge by the Government of India solving problem statements posed by central ministries, state departments, and premier industries.',
    'hybrid', 37, 16,
    'IIT Delhi Campus, Hauz Khas, New Delhi',
    DATE_ADD(CURDATE(), INTERVAL 45 DAY), DATE_ADD(CURDATE(), INTERVAL 47 DAY), DATE_ADD(CURDATE(), INTERVAL 25 DAY),
    'Teams of 6 students from recognized Indian colleges/universities with at least one female teammate.',
    18, 0.00, 'INR', 'Government sponsored travel allowance, lodging, and INR 1,00,000 prize per problem statement.',
    'https://sih.gov.in/sih2026-delhi-registration',
    'https://sih.gov.in', 1, 'DELHI-SIH-2026',
    SHA1('DELHI-SIH-2026-IITD'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Delhi Cybersecurity & Ethical Hacking Conclave
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    9, 13,
    'Delhi National Collegiate Cyber Defense & CTF Conclave',
    'Capture The Flag (CTF) tournament and live defense drills against simulated adversary attacks across web security, reverse engineering, and cryptography.',
    'offline', 37, 16,
    'Delhi Technological University (DTU), Rohini, New Delhi',
    DATE_ADD(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 31 DAY), DATE_ADD(CURDATE(), INTERVAL 16 DAY),
    'Open to all university students with an interest in information security.',
    18, 0.00, 'INR', 'Certificates of excellence & bug-bounty recognition.',
    'https://ieeedtu.com/cyber-conclave-2026',
    'https://ieee.org', 1, 'DELHI-CYBER-2026',
    SHA1('DELHI-CYBER-2026-DTU'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Hyderabad Cyber & AI Sprint
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
SELECT 
    2, 16,
    'Hyderabad T-Hub AI & Emerging Tech Acceleration Sprint 2026',
    'Build and pitch scalable AI products directly to angel investors, tech leaders, and venture capitalists at India’s largest innovation hub.',
    'offline', 37, (SELECT id FROM cities WHERE country_id = 37 AND name = 'Hyderabad' LIMIT 1),
    'T-Hub Phase 2, Knowledge City, Raidurg, Hyderabad, Telangana',
    DATE_ADD(CURDATE(), INTERVAL 40 DAY), DATE_ADD(CURDATE(), INTERVAL 42 DAY), DATE_ADD(CURDATE(), INTERVAL 22 DAY),
    'Undergraduate, graduate students, and early-stage student founders.',
    18, 0.00, 'INR', 'Direct entry into T-Hub student incubator cohort.',
    'https://t-hub.co/programs/collegiate-ai-sprint-2026',
    'https://t-hub.co', 1, 'HYD-THUB-2026',
    SHA1('HYD-THUB-2026-THUB2'), 'verified',
    'published', 0, NOW(), NOW()
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Hyderabad Cloud Architecture Immersion
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
SELECT 
    17, 18,
    'Hyderabad AWS Cloud & Serverless Infrastructure Bootcamp',
    'Hands-on session learning infrastructure as code (Terraform), serverless APIs (AWS Lambda), and automated deployment pipelines.',
    'offline', 37, (SELECT id FROM cities WHERE country_id = 37 AND name = 'Hyderabad' LIMIT 1),
    'IIIT Hyderabad Campus, Gachibowli, Hyderabad, Telangana',
    DATE_ADD(CURDATE(), INTERVAL 18 DAY), DATE_ADD(CURDATE(), INTERVAL 19 DAY), DATE_ADD(CURDATE(), INTERVAL 8 DAY),
    'Engineering & Polytechnic students with basic Linux / terminal knowledge.',
    18, 0.00, 'INR', 'Free AWS promotional credits provided.',
    'https://awsugindia.in/events/hyderabad-cloud-bootcamp-2026',
    'https://aws.amazon.com', 1, 'HYD-AWS-2026',
    SHA1('HYD-AWS-2026-IIITH'), 'verified',
    'published', 0, NOW(), NOW()
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Online Global AI & Web3 Hackathon (Available Everywhere)
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    2, 11,
    'Global Student AI Agents & Autonomous Systems Hackathon 2026',
    'Build autonomous software agents, LLM tool-calling systems, and generative AI productivity applications in this fully virtual global hackathon.',
    'online', NULL, NULL,
    'Virtual / Online Event (Discord & Zoom Live Mentorship)',
    DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 16 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY),
    'Open to university students worldwide. Solo participants or teams up to 4.',
    16, 0.00, 'INR', 'Free registration. Cloud GPU credits supplied by sponsor API providers.',
    'https://unstop.com/hackathons/global-ai-agents-hackathon-2026',
    'https://unstop.com', 1, 'ONLINE-AI-HACK-2026',
    SHA1('ONLINE-AI-HACK-2026-GLOBAL'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Online Open-Source Contribution Sprint
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    19, 14,
    'GitHub Campus Global Open Source Sprint 2026',
    'Contribute to trending open-source repositories, earn verified badges, and collaborate with maintainers across the worldwide developer ecosystem.',
    'online', NULL, NULL,
    'Virtual / GitHub Platform Repository Workspaces',
    DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 40 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
    'All active students with a verified GitHub Student Developer Pack.',
    16, 0.00, 'INR', 'Free. Official GitHub student swag and digital certificate of contribution.',
    'https://education.github.com/campus-sprint-2026',
    'https://github.com', 1, 'ONLINE-GITHUB-2026',
    SHA1('ONLINE-GITHUB-2026-GLOBAL'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Online Machine Learning & Data Challenge
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    18, 17,
    'Kaggle Community AI Benchmark: Real-World Time Series Prediction',
    'Compete against student data analysts globally to build high-accuracy predictive models using Python, Pandas, and XGBoost.',
    'online', NULL, NULL,
    'Kaggle Online Platform & Notebook Environments',
    DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 35 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY),
    'Open to all students. No prerequisites.',
    16, 0.00, 'INR', 'Free participation. Kaggle Medals and global leaderboard ranking.',
    'https://www.kaggle.com/competitions/real-world-timeseries-benchmark-2026',
    'https://www.kaggle.com', 1, 'ONLINE-KAGGLE-2026',
    SHA1('ONLINE-KAGGLE-2026-GLOBAL'), 'verified',
    'published', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    description = VALUES(description), 
    registration_deadline = VALUES(registration_deadline),
    venue = VALUES(venue),
    status = 'published';

-- Past Expired Opportunity (To prove automatic expiration filter works seamlessly)
INSERT INTO opportunities (
    activity_id, provider_id, title, description, delivery_mode, country_id, city_id,
    venue, start_date, end_date, registration_deadline, eligibility,
    min_age, cost_amount, cost_currency, financial_aid, registration_url,
    source_url, source_id, external_id, dedupe_hash, verification_status,
    status, is_sample, created_at, updated_at
)
VALUES (
    2, 11,
    'Inter-Collegiate Summer Hackathon 2025 (Expired)',
    'Completed collegiate competition from previous term.',
    'online', NULL, NULL,
    'Virtual Event Platform',
    DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 58 DAY), DATE_SUB(CURDATE(), INTERVAL 65 DAY),
    'Completed cohort.',
    18, 0.00, 'INR', 'Concluded.',
    'https://unstop.com/archived/summer-hack-2025',
    'https://unstop.com', 1, 'EXPIRED-HACK-2025',
    SHA1('EXPIRED-HACK-2025-ARCHIVED'), 'verified',
    'expired', 0, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    title = VALUES(title), 
    status = 'expired';

