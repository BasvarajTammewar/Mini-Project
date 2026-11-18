<?php
/**
 * Master generator for database/biotech_data/activities_data.php
 * Generates all 53 Extracurricular Activities across 5 Categories
 */

declare(strict_types=1);

$activities = [];

function a(&$activities, $title, $catCode, $spec, $mode, $diff, $hours, $elig, $prereq, $outcomes, $evidence, $degrees, $deliv, $resume, $biosafety, $skills) {
    $activities[] = [
        'title' => $title,
        'category_code' => $catCode,
        'specialisation' => $spec,
        'delivery_mode' => $mode,
        'difficulty' => $diff,
        'typical_hours' => $hours,
        'eligibility' => $elig,
        'prerequisites' => $prereq,
        'learning_outcomes' => $outcomes,
        'evidence_expected' => $evidence,
        'suitable_degree_levels' => $degrees,
        'deliverables' => $deliv,
        'resume_achievements' => $resume,
        'biosafety_guidelines' => $biosafety,
        'skills_gained' => $skills
    ];
}

// -----------------------------------------------------------------------------
// CATEGORY 1: Research and Laboratory Activities (12 Activities)
// -----------------------------------------------------------------------------
a($activities,
    'Independent Undergraduate Research Project in Biotechnology',
    'RESEARCH', 'Research and Development (R&D)', 'offline', 4, 120,
    'Enrolled B.Sc. or B.Tech. Biotechnology/Life Sciences students with faculty endorsement.',
    'Basic laboratory training in biology and chemistry; institutional biosafety induction.',
    'Hypothesis formulation, experimental design, protocol execution, rigorous documentation, and scientific report writing.',
    'Bound project thesis signed by supervisor, lab notebook scans, and final defense presentation slides.',
    'B.Sc., M.Sc.',
    'Structured research thesis (30-50 pages), raw experimental dataset, and scientific presentation.',
    'Completed a 120-hour independent research project investigating microbial secondary metabolites; generated 3 novel antimicrobial screening profiles.',
    'Strictly perform all experiments within an authorized institutional laboratory under designated faculty supervision. All recombinant and microbial manipulations must be cleared by Institutional Biosafety Committee (IBSC). Wear required PPE (lab coat, nitrile gloves, eye protection) at all times; autoclave all biohazard waste before disposal.',
    ['Research methods' => 4, 'Molecular Biology' => 4, 'Aseptic Technique' => 4, 'Laboratory Safety and Biosafety' => 4, 'Academic writing' => 4]
);

a($activities,
    'Directed Summer Research Internship at National Research Institute',
    'RESEARCH', 'Research and Development (R&D)', 'offline', 4, 160,
    'Undergraduate or postgraduate students selected through national competitive internship programs (IASc-INSA-NASI, CSIR-SRTP, DBT).',
    'Completion of at least 2 semesters of degree coursework in biological sciences.',
    'Immersion in advanced institute workflows, specialized instrumentation training, and scientific collaboration.',
    'Official internship completion certificate, signed laboratory report, and mentor evaluation letter.',
    'B.Sc., M.Sc., Ph.D.',
    'Comprehensive technical internship report, poster presentation at institute symposium.',
    'Awarded prestigious 8-week summer research fellowship at premier national institute; characterized protein-ligand binding kinetics using FPLC and fluorescence spectroscopy.',
    'Comply strictly with host institute BSL-2 laboratory regulations and radiation/chemical safety guidelines. No unsupervised experimental procedures permitted. Waste segregation strictly enforced according to biomedical waste disposal rules.',
    ['Laboratory Practice' => 5, 'Protein Expression and Purification' => 4, 'Presentation skills' => 4, 'Teamwork' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Academic Journal Club & Scientific Paper Critique Group',
    'CLUB', 'Research and Development (R&D)', 'hybrid', 3, 40,
    'Open to all undergraduate, postgraduate, and doctoral life sciences students.',
    'Interest in current biotechnology research and basic understanding of scientific literature.',
    'Critical appraisal of experimental methods, statistical interpretation, identification of limitations, and oral scientific defense.',
    'Journal club schedule, presentation slide decks (PDF), and peer feedback rubrics.',
    'B.Sc., M.Sc., Ph.D.',
    'Bi-weekly paper critique presentation, written 1-page summary evaluating novelty and methodology.',
    'Led 6 journal club sessions critiquing high-impact papers in synthetic biology and CRISPR applications, enhancing critical literature appraisal skills.',
    'Discussion-based activity; no physical wet-lab experimentation. All shared datasets and literature must respect copyright and academic integrity norms.',
    ['Critical thinking' => 4, 'Academic writing' => 3, 'Presentation skills' => 4, 'Molecular Biology' => 3]
);

a($activities,
    'Specialized Hands-on Molecular Biology Workshop',
    'WORKSHOP', 'Research and Development (R&D)', 'offline', 3, 30,
    'Undergraduate and postgraduate students seeking practical competency in recombinant DNA techniques.',
    'Introductory molecular genetics coursework.',
    'Mastery of micropipetting, plasmid DNA isolation, restriction digestion, agarose gel electrophoresis, and PCR amplification.',
    'Hands-on workshop certificate, annotated gel electrophoresis photographs, and laboratory protocol manual.',
    'B.Sc., M.Sc.',
    'Detailed laboratory protocol notebook with step-by-step documentation and gel documentation images.',
    'Completed 30 hours of intensive molecular biology hands-on training; independently achieved 100% success rate in plasmid isolation and diagnostic PCR.',
    'All reagents must be prepared in chemical fume hoods or laminar flow benches. Ethidium bromide staining must be conducted in dedicated containment trays with nitrile gloves and UV-filtering face shields.',
    ['Molecular Biology' => 4, 'PCR (Polymerase Chain Reaction)' => 4, 'Gel Electrophoresis' => 4, 'Buffer and Reagent Preparation' => 4]
);

a($activities,
    'Microbial Strain Isolation & Characterization Campaign',
    'PROJECT', 'Research and Development (R&D)', 'offline', 3, 60,
    'Students with access to a microbiology laboratory and faculty mentor.',
    'Basic microbiology laboratory safety course.',
    'Soil/water environmental sampling, serial dilution, pure culture isolation, Gram staining, and biochemical profiling.',
    'Curated strain characterization dossier with microscopic photomicrographs and biochemical assay records.',
    'B.Sc., M.Sc.',
    'Characterization catalog of isolated microbial strains with carbon utilization profiles and antibiotic susceptibility assays.',
    'Isolated and characterized 12 distinct soil bacterial strains from agricultural rhizospheres; identified 2 isolates with strong phosphate-solubilizing traits.',
    'Only Biosafety Level 1 (BSL-1) environmental samples may be handled. Any isolate suspected of opportunistic pathogenicity must be handled in BSL-2 containment or safely autoclaved immediately. Mouth pipetting strictly prohibited.',
    ['Microbiology' => 4, 'Aseptic Technique' => 4, 'Microbial Culture' => 4, 'Staining Techniques' => 4]
);

a($activities,
    'Protein Expression and Purification Laboratory Sprint',
    'PROJECT', 'Biopharmaceutical and Pharmaceutical Biotechnology', 'offline', 4, 80,
    'Students in their 2nd or final year of B.Sc./M.Sc. Biotechnology or Biochemistry.',
    'Knowledge of prokaryotic gene expression and chromatography principles.',
    'IPTG induction optimization, bacterial cell lysis by sonication, affinity chromatography (Ni-NTA), and SDS-PAGE purity quantification.',
    'Laboratory batch purification report, Coomassie-stained SDS-PAGE scans, and Bradford assay standard curves.',
    'B.Sc., M.Sc., Ph.D.',
    'Recombinant protein purification technical dossier with yield calculations (mg/L culture) and specific activity data.',
    'Expressed and purified recombinant His-tagged GFP from E. coli BL21(DE3) with >92% purity on Ni-NTA agarose.',
    'Use hearing protection during sonication. Handle acrylamide monomer powder inside certified fume hoods due to neurotoxicity risk; dispose of polymerized gels in designated solid chemical waste bins.',
    ['Protein Expression and Purification' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'SDS-PAGE' => 4, 'Buffer and Reagent Preparation' => 4]
);

a($activities,
    'Plant Micropropagation & Hardening Practicum',
    'PROJECT', 'Agricultural, Plant and Crop Biotechnology', 'offline', 3, 50,
    'Undergraduate and postgraduate students in Botany, Horticulture, or Plant Biotechnology.',
    'Basic sterile technique fundamentals.',
    'Preparation of MS media, explant surface sterilization, aseptic inoculation in laminar hoods, subculturing, and nursery hardening.',
    'Photographic logbook of tissue culture progression and percentage survival records during hardening.',
    'B.Sc., M.Sc.',
    'Step-by-step micropropagation protocol guide and batch survival report across in vitro stages.',
    'Successfully micropropagated ornamental orchids from apical shoot tips with 85% survival during greenhouse acclimatization.',
    'Surface sterilization agents (mercuric chloride, sodium hypochlorite) must be handled strictly inside chemical hoods with double gloving and neutralising waste protocols. Autoclave all nutrient media at 121 C for 15-20 min.',
    ['Plant Tissue Culture' => 4, 'Aseptic Technique' => 4, 'Plant Biotechnology' => 3, 'Laboratory Practice' => 3]
);

a($activities,
    'Advanced PCR & Real-Time Quantitative PCR Masterclass',
    'WORKSHOP', 'Healthcare and Clinical Diagnostics', 'hybrid', 4, 40,
    'Students seeking specialized diagnostic laboratory competence.',
    'Foundational genetics and standard PCR knowledge.',
    'Primer design for SYBR Green / TaqMan assays, RNA isolation, cDNA synthesis, amplification efficiency calculation, and delta-delta Ct analysis.',
    'Real-time qPCR amplification plots, melt curve analyses, and standard curve regression reports.',
    'B.Sc., M.Sc., Ph.D.',
    'Validated qPCR assay validation portfolio with standard curves showing R2 > 0.99 and efficiency between 90-110%.',
    'Designed, optimized, and validated a real-time RT-qPCR assay for quantifying human GAPDH and ACTB housekeeping gene expression.',
    'Wear RNase-free certified gloves; maintain separate designated pre-PCR, template addition, and post-PCR amplification areas to eliminate aerosol amplicon contamination.',
    ['Quantitative PCR (RT-qPCR)' => 5, 'PCR (Polymerase Chain Reaction)' => 4, 'Data Analysis' => 4, 'Good Laboratory Practice (GLP)' => 4]
);

a($activities,
    'In Vitro Mammalian Cell Culture & Viability Training',
    'WORKSHOP', 'Biopharmaceutical and Pharmaceutical Biotechnology', 'offline', 4, 60,
    'M.Sc. or advanced B.Sc. students with prior microbiology aseptic technique experience.',
    'Successful completion of BSL-2 safety training.',
    'Operation of Class II Biosafety Cabinets, CO2 incubator maintenance, cryopreservation in liquid nitrogen, cell counting via hemocytometer, and MTT viability assays.',
    'Cell culture logbook, cell growth curve graphs, and cell viability MTT dose-response curves.',
    'M.Sc., Ph.D.',
    'Mammalian cell line handling and MTT cytotoxicity assay portfolio documenting IC50 calculations.',
    'Maintained continuous cultures of HEK293 and Vero cell lines; executed MTT assays screening natural plant extracts for anti-proliferative activity.',
    'Work strictly inside certified Class II Type A2 Biosafety Cabinets under BSL-2 practices. Decontaminate liquid media with 10% bleach before disposal. Wear cryogenic gloves and face shield when handling liquid nitrogen dewars.',
    ['Mammalian Cell Culture' => 5, 'Aseptic Technique' => 5, 'Laboratory Safety and Biosafety' => 5, 'Spectrophotometry and Colorimetric Assays' => 4]
);

a($activities,
    'Structural Bioinformatics & In Silico Docking Research Study',
    'RESEARCH', 'Bioinformatics, Computational Biology and Genomics', 'online', 3, 50,
    'Students with basic computational literacy and biochemistry interest.',
    'Foundational biochemistry understanding.',
    'Protein sequence retrieval from UniProt, AlphaFold structure prediction, binding pocket prediction, ligand preparation, and molecular docking.',
    'GitHub repository containing docking scripts, AutoDock Vina parameter logs, and 3D PyMOL visualization ray-traced figures.',
    'B.Sc., M.Sc., Ph.D.',
    'Full scientific paper manuscript detailing target structure, ligand binding poses, hydrogen bonding networks, and binding free energies.',
    'Conducted virtual screening of 100 phyto-compounds against SARS-CoV-2 main protease, identifying 3 high-affinity inhibitor candidates.',
    'Dry-lab computational activity; no hazardous biological or chemical materials used. Adhere strictly to open-source software licenses and academic citation standards.',
    ['Molecular Modeling and Docking' => 4, 'Bioinformatics' => 4, 'Biochemistry' => 3, 'Academic writing' => 4]
);

a($activities,
    'Environmental Water Quality & Bio-burden Monitoring Project',
    'PROJECT', 'Industrial, Food and Environmental Biotechnology', 'offline', 3, 50,
    'Students interested in environmental monitoring and microbial ecology.',
    'General chemistry and microbiology laboratory fundamentals.',
    'Water sampling protocols, determination of dissolved oxygen, BOD, COD, and membrane filtration testing for coliform bioburden.',
    'Water quality assessment report with geographical GPS coordinates and statistical water quality index (WQI) scores.',
    'B.Sc., M.Sc.',
    'Environmental water quality survey report evaluating 10 local freshwater bodies against CPCB drinking and bathing standards.',
    'Assessed microbiological and chemical parameters across 10 regional water bodies, mapping coliform contamination using GPS and membrane filtration.',
    'Wear protective boots and heavy-duty nitrile gloves during field sampling of potentially sewage-contaminated waters. Disinfect sample containers; autoclave all membrane filter pads post-incubation.',
    ['Microbiology' => 4, 'Laboratory Safety and Biosafety' => 4, 'Data Analysis' => 3, 'Problem solving' => 3]
);

a($activities,
    'Laboratory Biosafety & Chemical Management Apprenticeship',
    'WORKSHOP', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'offline', 3, 40,
    'Life sciences students aspiring to laboratory management, safety officer, or QA careers.',
    'Enrolled in life sciences degree program.',
    'Biosafety level classification (BSL 1-4), Safety Data Sheet (SDS) interpretation, chemical segregation, spill drill response, and hazardous waste manifest preparation.',
    'Laboratory safety audit checklist report, chemical compatibility matrix, and emergency response plan.',
    'B.Sc., M.Sc.',
    'Institutional laboratory safety audit report with risk ratings and corrective action recommendations.',
    'Conducted comprehensive safety and biosafety walkthrough across 4 department laboratories; updated chemical storage inventories and eye-wash station inspection logs.',
    'This activity audits and enforces biosafety protocols. During spill simulation drills, only non-hazardous water or simulated dye mixtures may be used. Never handle concentrated acids without certified fume hood and face shield.',
    ['Laboratory Safety and Biosafety' => 5, 'Biosafety and Regulatory Compliance' => 4, 'Good Laboratory Practice (GLP)' => 4, 'Project management' => 3]
);

// -----------------------------------------------------------------------------
// CATEGORY 2: Competitions and Innovation Challenges (11 Activities)
// -----------------------------------------------------------------------------
a($activities,
    'iGEM (International Genetically Engineered Machine) Competition',
    'COMPETITION', 'Research and Development (R&D)', 'hybrid', 5, 200,
    'Multidisciplinary student teams enrolled in higher education institutes.',
    'Comprehensive molecular biology, synthetic biology, and mathematical modeling background.',
    'Synthetic genetic circuit design, BioBrick assembly, human practices outreach, wiki documentation, and international team presentation.',
    'Official iGEM Team Wiki, registry part submissions with characterization data, and Jamboree presentation video.',
    'B.Sc., M.Sc., Ph.D.',
    'Comprehensive team wiki, novel BioBrick genetic parts registered in iGEM Registry, and final Grand Jamboree presentation.',
    'Represented university at international iGEM Giant Jamboree; designed and characterized a synthetic bacterial biosensor for heavy metal detection, earning a Silver Medal.',
    'Strict compliance with iGEM Safety and Security policies is mandatory. Only White List organisms and genetic parts are permitted without special approval. No release of engineered organisms into the environment; autoclave all cultures before disposal.',
    ['Molecular Biology' => 5, 'Gene Cloning and Recombinant DNA' => 5, 'Teamwork' => 5, 'Presentation skills' => 5, 'Bioethics' => 4]
);

a($activities,
    'National Bio-Hackathon / Life Sciences Data Sprint',
    'HACKATHON', 'Bioinformatics, Computational Biology and Genomics', 'hybrid', 4, 36,
    'Undergraduate and postgraduate students proficient in coding, data science, or bioinformatics.',
    'Working knowledge of Python/R and public biological databases.',
    'Rapid collaborative problem solving, biological data integration, algorithm prototyping, and sprint pitch presentation.',
    'Public GitHub project repository, working prototype or pipeline, and 3-minute pitch deck.',
    'B.Sc., M.Sc., Ph.D.',
    'Functional open-source bioinformatics software prototype or web application addressing a life sciences challenge.',
    'Won 2nd place at National Bio-Hackathon (36-hour sprint) by developing an automated drug repurposing pipeline for rare genetic disorders.',
    'Computational hackathon. Adhere to open-source software license guidelines, FAIR data stewardship principles, and ethical data usage standards.',
    ['Python for Bioinformatics' => 4, 'Bioinformatics' => 4, 'Teamwork' => 5, 'Problem solving' => 5, 'Presentation skills' => 4]
);

a($activities,
    'Smart India Hackathon (SIH) - Biotech / Healthcare Track',
    'HACKATHON', 'Healthcare and Clinical Diagnostics', 'hybrid', 4, 48,
    'Teams of 6 undergraduate/postgraduate college students officially nominated by their institute.',
    'Interdisciplinary team composition covering biology, hardware/software, and product design.',
    'Translating problem statements into deployable tech solutions, stakeholder validation, and live jury demonstration.',
    'SIH submission portal verification, hardware/software prototype documentation, and jury evaluation rubric.',
    'B.Sc., M.Sc.',
    'Validated proof-of-concept prototype addressing national healthcare/biotech problem statements.',
    'Finalist at Smart India Hackathon (Healthcare Track); created a smartphone-compatible rapid microalbuminuria detection strip reader.',
    'Prototypes must adhere to relevant device safety norms. Any biological fluids used in testing must be non-infectious synthetic substitutes.',
    ['Problem solving' => 5, 'Teamwork' => 5, 'Presentation skills' => 4, 'Project management' => 4]
);

a($activities,
    'National Science Day Scientific Poster Competition',
    'COMPETITION', 'Research and Development (R&D)', 'offline', 3, 25,
    'Enrolled undergraduate or postgraduate students.',
    'Execution of original laboratory project or scholarly literature synthesis.',
    'Visual information design, concise technical communication, and fielding viva questions from scientific judges.',
    'High-resolution PDF poster file, photograph of physical poster presentation, and certificate of participation/award.',
    'B.Sc., M.Sc., Ph.D.',
    'Standard A0 scientific poster summarizing methodology, key results with figures, and conclusive insights.',
    'Awarded Best Scientific Poster on National Science Day for presentation on microalgal lipid production for aviation biofuels.',
    'Poster display activity. Ensure proper attribution of data and avoid disclosing unpublished patentable inventions prior to filing.',
    ['Presentation skills' => 5, 'Academic writing' => 4, 'Critical thinking' => 3, 'Data Analysis' => 3]
);

a($activities,
    'Biotechnology Innovation & Prototype Challenge',
    'COMPETITION', 'Research and Development (R&D)', 'hybrid', 4, 80,
    'Students with innovative concepts in biotechnology, medical diagnostics, or bioprocessing.',
    'Idea proposal and preliminary feasibility validation.',
    'Technology readiness level (TRL) progression, prototype fabrication, patent landscape analysis, and business pitch.',
    'Prototype functional specification document, demonstration video, and business canvas.',
    'B.Sc., M.Sc., Ph.D.',
    'Working benchtop prototype or simulated product model demonstrating proof-of-principle efficacy.',
    'Secured BIRAC-BIG pre-incubation grant grant interest after winning University Biotechnology Innovation Challenge with a novel biodegradable seed coating.',
    'Bench-scale prototypes must be constructed in certified labs under faculty guidance. Hazardous electrical or biological elements must meet CE / ISO safety standards.',
    ['Problem solving' => 5, 'Project management' => 4, 'Presentation skills' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Diagnostic Device Design Competition',
    'COMPETITION', 'Healthcare and Clinical Diagnostics', 'hybrid', 4, 60,
    'Interdisciplinary students in biotechnology, biomedical engineering, and physics.',
    'Understanding of biosensors, optics, or microfluidics.',
    'Sensor design, calibration curve generation, limit-of-detection optimization, and ergonomic user interface design.',
    'Design history file, CAD enclosure models, sensor response data files, and demonstration pitch.',
    'B.Sc., M.Sc.',
    'Functional POC diagnostic strip reader or electrochemical biosensor prototype.',
    'Designed a low-cost colorimetric glucose biosensor winning the National Biomedical Design Contest.',
    'Use artificial urine or saliva matrices during competition demonstrations; no untested clinical human samples permitted.',
    ['ELISA and Immunoassays' => 4, 'Problem solving' => 4, 'Presentation skills' => 4, 'Data Analysis' => 4]
);

a($activities,
    'Computational Biology & Genomics Kaggle Challenge',
    'COMPETITION', 'Bioinformatics, Computational Biology and Genomics', 'online', 4, 40,
    'Open to all students with computational programming and machine learning skills.',
    'Python, data science packages (scikit-learn, PyTorch), and basic molecular biology.',
    'Feature engineering on biological sequences, training classification/regression models, and hyperparameter tuning.',
    'Kaggle leaderboard submission confirmation, open-source Kaggle notebook with reproducible code.',
    'B.Sc., M.Sc., Ph.D.',
    'Documented machine learning notebook achieving competitive validation performance on public biological benchmarks.',
    'Achieved top 5% finish in Kaggle protein structure prediction challenge utilizing transformer-based sequence embeddings.',
    'Computational challenge only. Follow Kaggle competition community rules and intellectual property sharing guidelines.',
    ['Machine Learning in Life Sciences' => 5, 'Python for Bioinformatics' => 4, 'Data Analysis' => 5, 'Problem solving' => 4]
);

a($activities,
    'Agritech & Crop Innovation Challenge',
    'COMPETITION', 'Agricultural, Plant and Crop Biotechnology', 'hybrid', 3, 50,
    'Students passionate about agricultural productivity and rural food security.',
    'Foundational knowledge of crop physiology or agricultural technology.',
    'Field problem identification, farmer interview surveys, bio-input or agronomic solution development, and economic ROI modeling.',
    'Detailed innovation proposal, field demonstration video, and farmer feedback survey report.',
    'B.Sc., M.Sc.',
    'Agritech business proposal detailing formulation, field efficacy trial data, and distribution model.',
    'Finalist in National Agritech Challenge with a microbial consortium bio-stimulant improving drought resilience in dryland pulses.',
    'Field trials must comply with local agricultural university guidelines. No unapproved genetic events may be released into field nurseries.',
    ['Plant Biotechnology' => 4, 'Problem solving' => 4, 'Presentation skills' => 4, 'Project management' => 3]
);

a($activities,
    'Environmental CleanTech & Bio-remediation Sprint',
    'COMPETITION', 'Industrial, Food and Environmental Biotechnology', 'hybrid', 3, 40,
    'Students interested in sustainability, circular economy, and environmental clean-up.',
    'Environmental science or biotechnology coursework.',
    'Pollutant degradation modeling, waste valorization strategy development, and life-cycle impact calculation.',
    'Pitch deck, process flow diagram (PFD), and mass balance spreadsheet.',
    'B.Sc., M.Sc.',
    'Technical pitch and feasibility report for a biological clean-up process targeting industrial effluent streams.',
    'Won 1st prize at Green Innovators Conclave for designing an immobilized microalgal photobioreactor for textile wastewater treatment.',
    'Any lab tests must use synthetic wastewater formulations. Corrosive reagents must be neutralized prior to drain disposal.',
    ['Bioreactor Operation and Fermentation' => 3, 'Problem solving' => 4, 'Presentation skills' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Three Minute Thesis (3MT) Life Sciences Presentation',
    'COMPETITION', 'Research and Development (R&D)', 'offline', 3, 20,
    'Postgraduate and doctoral research scholars conducting original dissertation research.',
    'Active dissertation or doctoral project nearing completion.',
    'Condensing complex scientific methodology into an engaging 3-minute oral narrative for non-specialist audiences without technical jargon.',
    'Video recording of 3MT presentation, single static PowerPoint slide, and competition judges assessment rubric.',
    'M.Sc., Ph.D.',
    '3-minute oral video presentation explaining research significance and societal impact.',
    'Won University Three Minute Thesis (3MT) Competition presenting research on targeted nanoparticle drug delivery to a general public audience.',
    'Oral presentation activity. Maintain high standards of scientific integrity; do not misrepresent speculative outcomes as validated facts.',
    ['Presentation skills' => 5, 'Academic writing' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Synthetic Biology Circuit Designathon',
    'COMPETITION', 'Research and Development (R&D)', 'online', 4, 30,
    'Students interested in genetic engineering, biological circuits, and bio-design.',
    'Understanding of gene expression regulation, promoters, and repressors.',
    'Mathematical modeling of biological Boolean logic gates, promoter dynamic range optimization, and in silico circuit verification.',
    'Circuit SBOL design files, ODE simulation plots, and design specification writeup.',
    'B.Sc., M.Sc., Ph.D.',
    'Complete synthetic genetic circuit blueprint with promoter-repressor dynamic range simulation and crosstalk analysis.',
    'Placed 3rd in National Bio-Designathon by designing a dual-input synthetic genetic AND-gate for targeted tumor cell apoptosis.',
    'Computational bio-design activity. Designs must undergo dual-use research of concern (DUROC) screening to ensure no hazardous toxins or pathogenic pathways are incorporated.',
    ['Gene Cloning and Recombinant DNA' => 4, 'Bioinformatics' => 4, 'Problem solving' => 4, 'Molecular Biology' => 4]
);

// -----------------------------------------------------------------------------
// CATEGORY 3: Practical Projects and Portfolio Development (11 Activities)
// -----------------------------------------------------------------------------
a($activities,
    'Personal Open-Source Bioinformatics Pipeline on GitHub',
    'PROJECT', 'Bioinformatics, Computational Biology and Genomics', 'online', 4, 60,
    'Students with computational and coding aspirations in life sciences.',
    'Proficiency in Linux/Unix, Python or R, and Git version control.',
    'Software modularization, CLI argument parsing, automated testing, documentation, and open-source licensing.',
    'Public GitHub repository with comprehensive README, automated GitHub Actions CI, and test datasets.',
    'B.Sc., M.Sc., Ph.D.',
    'Published GitHub repository containing an end-to-end automated NGS or sequence analysis pipeline with documentation.',
    'Authored an open-source Nextflow pipeline for bacterial RNA-seq analysis with 50+ GitHub stars and continuous integration testing.',
    'Dry-lab software development. Ensure test datasets are synthetic or fully anonymized public domain data.',
    ['Bioinformatics' => 5, 'Python for Bioinformatics' => 5, 'Next-Generation Sequencing (NGS)' => 4, 'Data Analysis' => 4]
);

a($activities,
    'CRISPR Gene Knockout Design & In Silico Guide RNA Validation',
    'PROJECT', 'Research and Development (R&D)', 'online', 4, 40,
    'Students interested in functional genomics and gene editing.',
    'Knowledge of molecular genetics and CRISPR-Cas9 biology.',
    'Identification of exon target loci, guide RNA (sgRNA) design using CRISPOR and Benchling, and off-target cleavage prediction.',
    'Target validation report with off-target CFD score tables and PCR screening primer designs.',
    'B.Sc., M.Sc., Ph.D.',
    'Validated CRISPR knockout blueprint for a candidate disease gene including 3 top-scoring sgRNAs and genotyping strategies.',
    'Designed and computationally validated 4 high-efficiency sgRNAs targeting human BCL2 with zero predicted high-risk off-target loci.',
    'Computational design project. If proceeding to experimental validation, all gRNA plasmids must be handled in authorized BSL-2 laboratories with IBSC approval.',
    ['CRISPR and Gene Editing' => 5, 'Molecular Biology' => 4, 'Bioinformatics' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Metagenomic Microbiome Analysis Portfolio',
    'PROJECT', 'Bioinformatics, Computational Biology and Genomics', 'online', 4, 50,
    'Students interested in microbiome research and high-throughput data analysis.',
    'Command-line familiarity and R/Python skills.',
    '16S rRNA amplicon processing in QIIME2, taxonomy assignment, alpha/beta diversity calculation, and publication-ready visualization in R.',
    'R markdown / Jupyter notebook documenting analysis pipeline, PCoA ordination plots, and taxonomic barplots.',
    'B.Sc., M.Sc., Ph.D.',
    'Comprehensive microbiome analysis portfolio analyzing publicly available human gut or soil metagenomic datasets.',
    'Analyzed publicly available gut microbiome data from 120 Crohn\'s disease patients; demonstrated significant depletion of Faecalibacterium prausnitzii.',
    'Computational project using public Sequence Read Archive (SRA) datasets. Respect data contributor licenses and publication attribution.',
    ['Bioinformatics' => 5, 'R Programming for Biostatistics' => 4, 'Microbiology' => 3, 'Data Analysis' => 4]
);

a($activities,
    'Small-Molecule Virtual Screening & ADMET Report',
    'PROJECT', 'Bioinformatics, Computational Biology and Genomics', 'online', 4, 45,
    'Students aiming for careers in computer-aided drug discovery or pharmaceutical biotechnology.',
    'Foundational medicinal chemistry and structural biology.',
    'Ligand library preparation from ChEMBL/ZINC, docking execution with AutoDock Vina, and ADMET prediction via SwissADME.',
    'Docking score spreadsheets, 2D ligand-protein interaction diagrams, and SwissADME bioavailability radar charts.',
    'B.Sc., M.Sc., Ph.D.',
    'Comprehensive virtual screening report identifying top 5 hit molecules with acceptable pharmacokinetic profiles.',
    'Screened 5,000 phytochemicals against cancer target CDK4; identified 2 lead compounds with nanomolar docking affinity and zero Lipinski violations.',
    'Dry-lab computational study. Data must be verifiable and reproducible using open-source tools.',
    ['Molecular Modeling and Docking' => 5, 'Bioinformatics' => 4, 'Biochemistry' => 3, 'Academic writing' => 4]
);

a($activities,
    'Microbial Fermentation Kinetics Optimization Portfolio',
    'PROJECT', 'Industrial, Food and Environmental Biotechnology', 'offline', 4, 70,
    'Students studying bioprocess engineering or industrial microbiology.',
    'Laboratory experience in microbial culture and spectrophotometry.',
    'Shake-flask cultivation, optical density measurement, substrate consumption analysis, and calculation of specific growth rate (mu) and doubling time.',
    'Batch kinetic graphs (cell dry weight, substrate consumption, product concentration) and regression models.',
    'B.Sc., M.Sc.',
    'Empirical fermentation kinetics study detailing maximum specific growth rate (mu_max) and substrate yield coefficient (Y_x/s).',
    'Optimized carbon-to-nitrogen ratios in Saccharomyces cerevisiae fermentation, increasing ethanol production rate by 24%.',
    'Only non-pathogenic laboratory strains (e.g. S. cerevisiae, E. coli K12) permitted. Autoclave all spent fermentation broths and media before disposal.',
    ['Bioreactor Operation and Fermentation' => 4, 'Microbial Culture' => 4, 'Data Analysis' => 4, 'Laboratory Practice' => 3]
);

a($activities,
    'Plant Tissue Culture Protocol Optimization Portfolio',
    'PROJECT', 'Agricultural, Plant and Crop Biotechnology', 'offline', 4, 60,
    'Students seeking expertise in agricultural and horticultural biotechnology.',
    'Basic plant biology and sterile handling technique.',
    'Designing hormonal factorials (varying 2,4-D, BAP, and Kinetin concentrations), recording callus induction percentages, and organogenesis scoring.',
    'Tabulated growth response data across media formulations with macroscopic photographic progression log.',
    'B.Sc., M.Sc.',
    'Protocol development document establishing the optimal hormonal ratio for direct shoot regeneration from leaf disc explants.',
    'Optimized an in vitro micropropagation protocol for Solanum lycopersicum leaf explants achieving 92% regeneration frequency.',
    'Autoclave all plant culture media; safely neutralise chemical disinfectant solutions (bleach, ethanol) following hazardous waste protocols.',
    ['Plant Tissue Culture' => 5, 'Plant Biotechnology' => 4, 'Aseptic Technique' => 4, 'Research methods' => 3]
);

a($activities,
    'Lateral Flow Immunoassay Strip Prototype Project',
    'PROJECT', 'Healthcare and Clinical Diagnostics', 'offline', 4, 70,
    'Students interested in rapid medical diagnostics and nanotechnology.',
    'Biochemistry and antibody-antigen interaction fundamentals.',
    'Gold nanoparticle synthesis, antibody conjugation, strip assembly (sample pad, conjugate pad, nitrocellulose, absorbent pad), and sensitivity testing.',
    'Prototype test strips, photographic dose-response sensitivity series, and cross-reactivity validation tables.',
    'B.Sc., M.Sc., Ph.D.',
    'Complete technical file and demonstration prototype of a rapid antigen/antibody detection strip.',
    'Developed a rapid lateral flow strip detecting human salivary alpha-amylase within 10 minutes with 96% diagnostic accuracy.',
    'Synthesize gold nanoparticles inside ventilated chemical fume hoods. Use only non-infectious control antigens (e.g. BSA, ovalbumin, or saliva from consenting healthy volunteers with IRB approval).',
    ['ELISA and Immunoassays' => 5, 'Nanobiotechnology' => 4, 'Quality Control (QC)' => 4, 'Problem solving' => 4]
);

a($activities,
    'Enzyme Biocatalysis Kinetic Profiling Project',
    'PROJECT', 'Research and Development (R&D)', 'offline', 3, 50,
    'Students in biochemistry or biotechnology.',
    'Spectrophotometry and enzyme fundamentals.',
    'Preparing substrate dilution series, measuring initial velocities (V0), plotting Michaelis-Menten and Lineweaver-Burk graphs, and determining Km and Vmax.',
    'Raw spectrophotometer absorbance time-series datasets, regression curve fitting files, and kinetic parameter calculation sheets.',
    'B.Sc., M.Sc.',
    'Enzyme kinetic dossier quantifying Km, Vmax, and turnover number (kcat) under varying temperature and pH regimes.',
    'Characterized kinetic parameters of commercial invertase and alkaline phosphatase, determining optimal activation energy and thermal denaturation points.',
    'Avoid mouth pipetting; use appropriate PPE when preparing buffer solutions and hazardous chromogenic substrates (e.g., pNPP).',
    ['Enzyme Kinetics' => 5, 'Biochemistry' => 4, 'Spectrophotometry and Colorimetric Assays' => 4, 'Data Analysis' => 4]
);

a($activities,
    'Single-Cell RNA-Seq Workflow Demonstration Portfolio',
    'PROJECT', 'Bioinformatics, Computational Biology and Genomics', 'online', 4, 50,
    'Students aiming for careers in advanced genomics and precision medicine.',
    'Experience with R or Python and high-throughput data formats.',
    'Raw count matrix filtering, mitochondrial gene thresholding, log-normalization, PCA/UMAP embedding, cell clustering, and marker gene identification.',
    'Jupyter notebook or R markdown containing reproducible single-cell analysis workflow with annotated visualization plots.',
    'B.Sc., M.Sc., Ph.D.',
    'Published analysis portfolio dissecting single-cell heterogeneity from publicly available 10x Genomics datasets.',
    'Re-analyzed single-cell RNA-seq of 15,000 glioblastoma cells; identified 4 distinct malignant cellular states using Seurat in R.',
    'Dry-lab computational project utilizing open access datasets from GEO/SRA; adhere to open science guidelines.',
    ['Next-Generation Sequencing (NGS)' => 5, 'Bioinformatics' => 5, 'R Programming for Biostatistics' => 4, 'Python for Bioinformatics' => 4]
);

a($activities,
    'Food Fermentation & Starter Culture Formulation Case Study',
    'PROJECT', 'Industrial, Food and Environmental Biotechnology', 'offline', 3, 40,
    'Students interested in food microbiology and food technology.',
    'Basic microbiology laboratory skills.',
    'Isolation of lactic acid bacteria from traditional fermented foods, phenotypic characterization, acidification rate measurement, and sensory evaluation.',
    'Fermentation pH kinetic curves, microbial viability counts (CFU/mL), and standardized sensory evaluation scores.',
    'B.Sc., M.Sc.',
    'Food biotechnology formulation report detailing starter culture stability and shelf-life characteristics.',
    'Formulated a defined multi-strain starter culture of Lactobacillus plantarum and Streptococcus thermophilus for soy milk fermentation.',
    'All food samples meant for sensory tasting must be prepared in dedicated food-grade preparation areas completely separate from research microbiology laboratories. Research laboratory samples must never be consumed.',
    ['Microbiology' => 4, 'Microbial Culture' => 4, 'Quality Control (QC)' => 3, 'Problem solving' => 3]
);

a($activities,
    'Life Cycle Assessment (LCA) of Bio-based Plastics Study',
    'PROJECT', 'Industrial, Food and Environmental Biotechnology', 'online', 3, 40,
    'Students passionate about circular bioeconomy and sustainability.',
    'Basic knowledge of biopolymers and environmental metrics.',
    'System boundary definition, inventory data collection, environmental impact category calculation (GWP, acidification, eutrophication), and sensitivity analysis.',
    'LCA study report adhering to ISO 14040/14044 standards with OpenLCA software model files.',
    'B.Sc., M.Sc., Ph.D.',
    'Comprehensive LCA comparative report assessing carbon and water footprints of polyhydroxybutyrate (PHB) versus fossil-derived polypropylene.',
    'Conducted a cradle-to-gate Life Cycle Assessment of bio-plastic production from molasses, demonstrating a 42% reduction in greenhouse gas emissions.',
    'Desk-based computational analysis using open-source LCA databases. Ensure all primary and secondary inventory data sources are properly cited.',
    ['Data Analysis' => 4, 'Academic writing' => 4, 'Critical thinking' => 4, 'Presentation skills' => 3]
);

// -----------------------------------------------------------------------------
// CATEGORY 4: Professional, Academic and Student Clubs (10 Activities)
// -----------------------------------------------------------------------------
a($activities,
    'University Biotechnology Student Society Executive Leadership',
    'CLUB', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'offline', 3, 80,
    'Elected or selected biotechnology students.',
    'Enthusiasm for student leadership, peer collaboration, and science promotion.',
    'Event planning, budget management, team delegation, institutional liaison, and executive decision-making.',
    'Official society appointment letter, annual activity report, budget balance sheets, and participant attendance registers.',
    'B.Sc., M.Sc.',
    'Comprehensive annual society report detailing 8 academic events, industrial visits, and sponsorship management.',
    'Served as President of University Biotechnology Society; orchestrated 12 seminars, 2 industrial visits, and managed a budget of INR 1.5 Lakhs.',
    'Administrative and leadership activity. Ensure all organized campus events strictly adhere to university safety regulations and fire codes.',
    ['Leadership' => 5, 'Project management' => 4, 'Presentation skills' => 4, 'Teamwork' => 5]
);

a($activities,
    'SynBio / Synthetic Biology Student Chapter Leadership',
    'CLUB', 'Research and Development (R&D)', 'hybrid', 3, 60,
    'Students interested in synthetic biology and biotechnology innovation.',
    'Passion for engineering biology and multidisciplinary science.',
    'Community building, organizing journal discussions, hosting guest lectures by leading synthetic biologists, and iGEM awareness.',
    'Chapter charter, member roster, event recordings, and quarterly progress bulletins.',
    'B.Sc., M.Sc., Ph.D.',
    'Quarterly chapter newsletter and documentation of organized workshops on synthetic biology concepts.',
    'Founded university SynBio student chapter growing membership to 80+ students; organized 6 expert webinars with synthetic biology researchers.',
    'Club events involving laboratory demonstrations must be supervised by faculty in certified BSL-1 labs with complete PPE.',
    ['Leadership' => 4, 'Teamwork' => 4, 'Presentation skills' => 4, 'Molecular Biology' => 3]
);

a($activities,
    'Life Sciences National Symposium Organizing Committee',
    'LEADERSHIP', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'offline', 3, 60,
    'Volunteering students representing the department.',
    'Organizational and communication skills.',
    'Call-for-abstracts management, peer-review coordination, keynote speaker hospitality, logistics coordination, and souvenir publication.',
    'Certificate of organizational leadership, conference proceeding souvenir, and program schedule.',
    'B.Sc., M.Sc., Ph.D.',
    'Published conference abstract proceeding and successfully executed multi-day national symposium.',
    'Coordinated national biotechnology conference hosting 350+ delegates and 6 keynote speakers; managed peer-review workflow for 85 abstracts.',
    'Logistical and managerial activity. Coordinate with campus safety and security for venue crowd management and emergency egress.',
    ['Project management' => 5, 'Teamwork' => 5, 'Presentation skills' => 4, 'Leadership' => 4]
);

a($activities,
    'Science Communication & Popular Science Blogging Initiative',
    'CLUB', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'online', 2, 40,
    'Students with strong writing skills and scientific curiosity.',
    'Ability to explain complex biological concepts in engaging prose.',
    'Translating peer-reviewed literature into layperson articles, search engine optimization (SEO), fact-checking, and digital publishing.',
    'Links to published science articles/blog posts, website readership analytics, and social media engagement metrics.',
    'B.Sc., M.Sc., Ph.D.',
    'Portfolio of at least 6 published popular science articles covering biotechnology breakthroughs and societal impacts.',
    'Published 8 popular science articles on CRISPR gene editing and vaccine biotechnology on a national science platform, amassing over 20,000 reads.',
    'Writing and communication activity. Maintain strict adherence to factual scientific accuracy; avoid sensationalism or unsubstantiated health claims.',
    ['Academic writing' => 5, 'Critical thinking' => 4, 'Presentation skills' => 4]
);

a($activities,
    'Bioethics & Biosafety Student Discussion Forum',
    'CLUB', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'hybrid', 2, 30,
    'Students interested in ethical, legal, and social implications (ELSI) of biotechnology.',
    'Open-minded perspective on modern controversial technologies.',
    'Debating genetic privacy, gene drives, human germline editing, equitable drug access, and biosecurity threats.',
    'Discussion forum minutes, policy brief summaries, and recorded debate transcripts.',
    'B.Sc., M.Sc., Ph.D.',
    'Authored student policy brief on regulatory frameworks for genome-edited crops in developing nations.',
    'Moderated monthly bioethics roundtables on human embryo editing and patent monopolies in biopharma, drafting a widely shared student policy brief.',
    'Discussion-based activity. Respect diverse ethical viewpoints while grounding debates in established international bioethics declarations (UNESCO, WHO).',
    ['Bioethics' => 5, 'Critical thinking' => 5, 'Presentation skills' => 4, 'Academic writing' => 3]
);

a($activities,
    'Industry-Academia Networking & Guest Lecture Series Coordinator',
    'LEADERSHIP', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'hybrid', 3, 40,
    'Proactive students with career development interests.',
    'Professional email etiquette and communication skills.',
    'Identifying industry leaders, scheduling online/offline lecture sessions, preparing introductory briefings, and moderating Q&A panels.',
    'Lecture series schedule, introductory slide decks, recorded video archive, and speaker thank-you correspondence.',
    'B.Sc., M.Sc.',
    'Series dossier documenting 6 industrial interaction sessions across biopharma, diagnostics, and agritech sectors.',
    'Curated and hosted an 8-part Industry Speaker Series connecting students with biopharma executives, leading to 5 internship placements.',
    'Networking coordination. Maintain professional conduct and confidentiality when interacting with corporate representatives.',
    ['Presentation skills' => 4, 'Project management' => 4, 'Teamwork' => 4, 'Leadership' => 3]
);

a($activities,
    'Mentorship of Junior Undergraduate Laboratory Trainees',
    'LEADERSHIP', 'Research and Development (R&D)', 'offline', 3, 50,
    'Senior postgraduate or final-year undergraduate students with proven lab competence.',
    'Demonstrated mastery of core laboratory protocols and safety procedures.',
    'Pedagogical instruction, protocol demonstration, troubleshooting guidance, and fostering laboratory hygiene habits in novices.',
    'Mentorship verification log signed by faculty supervisor and mentee feedback evaluations.',
    'B.Sc., M.Sc., Ph.D.',
    'Mentorship progress portfolio documenting protocols taught and junior student competencies attained.',
    'Mentored 4 junior undergraduate students in aseptic microbial techniques and plasmid isolation, achieving 100% protocol proficiency.',
    'Senior students must continuously monitor juniors during wet-lab operations. Never allow mentees to operate autoclaves or handle hazardous chemicals unattended.',
    ['Leadership' => 4, 'Laboratory Practice' => 5, 'Aseptic Technique' => 4, 'Laboratory Safety and Biosafety' => 4]
);

a($activities,
    'Biotech Career & Entrepreneurship Club Leadership',
    'CLUB', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'hybrid', 3, 50,
    'Students aspiring to biotechnology entrepreneurship or corporate leadership.',
    'Interest in business models, venture funding, and startup incubation.',
    'Hosting pitch sessions, dissecting biotech startup case studies, and engaging with university technology transfer offices.',
    'Club meeting agendas, pitch competition pitch decks, and startup case study repositories.',
    'B.Sc., M.Sc.',
    'Biotech startup business case portfolio analyzing market dynamics, valuation, and regulatory pathway of an emerging therapeutic.',
    'Organized university Biotech Pitch Day connecting 10 student venture teams with regional angel investors and incubator managers.',
    'Business and entrepreneurship focus. Respect intellectual property boundaries of participating student researchers.',
    ['Leadership' => 4, 'Project management' => 4, 'Presentation skills' => 4, 'Critical thinking' => 4]
);

a($activities,
    'Bioinformatics Study Circle & Coding Club Organizer',
    'CLUB', 'Bioinformatics, Computational Biology and Genomics', 'hybrid', 3, 50,
    'Students enthusiastic about computational biology, Python, and R programming.',
    'Basic programming foundations.',
    'Peer coding sprints, code reviews, algorithm problem sessions, and tutorial delivery for biological data science.',
    'Study circle syllabus, shared GitHub code repository, and attendance records.',
    'B.Sc., M.Sc., Ph.D.',
    'Curated open curriculum of 10 hands-on Python/R bioinformatics coding exercises with solution guides.',
    'Founded Bioinformatics Coding Circle; delivered 10 peer tutorials on Biopython and ggplot2 data visualization for 40+ biology peers.',
    'Coding and computational peer learning. Adhere to software development best practices and code attribution.',
    ['Python for Bioinformatics' => 4, 'Bioinformatics' => 4, 'Presentation skills' => 4, 'Teamwork' => 4]
);

a($activities,
    'Women in STEM & Life Sciences Chapter Leadership',
    'LEADERSHIP', 'Regulatory Affairs, Quality Assurance and Biotech Business', 'hybrid', 2, 40,
    'Students passionate about diversity, equity, and inclusion in STEM careers.',
    'Commitment to empowering female peers in scientific disciplines.',
    'Organizing mentorship pairing with women scientists, career workshops, panel discussions, and community outreach.',
    'Chapter activity report, event flyers, participant attendance, and mentorship feedback surveys.',
    'B.Sc., M.Sc., Ph.D.',
    'Annual chapter dossier documenting mentorship program impact and panel discussions with prominent women researchers.',
    'Chaired Women in Life Sciences chapter, orchestrating a career mentorship program pairing 30 female students with industry scientists.',
    'Advocacy and community activity. Promote inclusive and equitable participation in scientific research opportunities.',
    ['Leadership' => 5, 'Teamwork' => 4, 'Presentation skills' => 4, 'Project management' => 3]
);

// -----------------------------------------------------------------------------
// CATEGORY 5: Social, Outreach and Environmental Activities (9 Activities)
// -----------------------------------------------------------------------------
a($activities,
    'Community Drinking Water Testing & Microbial Awareness Drive',
    'VOLUNTEERING', 'Industrial, Food and Environmental Biotechnology', 'offline', 3, 40,
    'Volunteering students with basic microbiology training.',
    'Microbial testing laboratory skills.',
    'Community water sampling from rural borewells, coliform detection using H2S test vials, and delivering hygiene awareness talks to villagers.',
    'Water quality test results summary, community sign-off sheets, and photographic field documentation.',
    'B.Sc., M.Sc.',
    'Community water safety report presented to local village panchayat with actionable water sanitation recommendations.',
    'Conducted drinking water quality assessments for 50 rural households; educated over 200 residents on boiling and chlorination disinfection.',
    'Testing must be performed with sealed disposable diagnostic vials. Used test vials must be autoclaved or immersed in 10% bleach before disposal.',
    ['Microbiology' => 3, 'Presentation skills' => 4, 'Laboratory Safety and Biosafety' => 3, 'Teamwork' => 4]
);

a($activities,
    'School Science Outreach & Foldscope Microscope Demonstration Camp',
    'VOLUNTEERING', 'Research and Development (R&D)', 'offline', 2, 30,
    'Students who enjoy teaching and inspiring younger pupils in basic biology.',
    'Clear communication and patience with young learners.',
    'Assembling paper Foldscope microscopes, preparing simple onion peel and pond water slides, and sparking scientific wonder.',
    'Camp photographs, school principal testimonial letter, and student feedback drawings/notes.',
    'B.Sc., M.Sc.',
    'Outreach event report documenting 100+ school students trained in microscopic observation and cellular discovery.',
    'Conducted 3 interactive microscopy camps for government school students, introducing 120 children to microorganisms using paper Foldscopes.',
    'Only safe, non-pathogenic environmental specimens (onion epidermis, pond microalgae) may be used. Use plastic coverslips to eliminate glass breakage risks for children.',
    ['Presentation skills' => 5, 'Microscopy (Brightfield / Fluorescence)' => 3, 'Leadership' => 3, 'Teamwork' => 4]
);

a($activities,
    'Antimicrobial Resistance (AMR) Public Health Awareness Campaign',
    'VOLUNTEERING', 'Healthcare and Clinical Diagnostics', 'hybrid', 2, 35,
    'Students committed to public health advocacy.',
    'Understanding of antibiotic stewardship and microbial resistance evolution.',
    'Designing informational pamphlets in regional languages, street plays (Nukkad Natak), and social media infographics against antibiotic misuse.',
    'Printed brochures/infographics, social media analytics report, and campaign video clip.',
    'B.Sc., M.Sc., Ph.D.',
    'Public health awareness toolkit comprising bilingual infographics and educational video on completing antibiotic regimens.',
    'Spearheaded World Antimicrobial Awareness Week campaign on campus, engaging 500+ students and distributing 1,000 educational leaflets.',
    'Advocacy campaign. Information must strictly reflect WHO and national health guidelines; never provide personalized medical advice.',
    ['Presentation skills' => 5, 'Microbiology' => 3, 'Teamwork' => 4, 'Academic writing' => 3]
);

a($activities,
    'Rare Disease Awareness & Patient Advocacy Volunteering',
    'VOLUNTEERING', 'Healthcare and Clinical Diagnostics', 'hybrid', 2, 30,
    'Compassionate students interested in human genetics and clinical advocacy.',
    'Basic knowledge of genetic disorders and empathy in communication.',
    'Supporting rare disease support groups, organizing awareness walks, and compiling layperson summaries of current clinical research.',
    'Awareness event summary report, patient advocacy testimonial, and published educational flyers.',
    'B.Sc., M.Sc.',
    'Educational booklet translating complex genetic testing concepts into empathetic language for affected families.',
    'Volunteered with national rare disease foundation for Rare Disease Day; co-authored a genetic testing guide for families affected by SMA and DMD.',
    'Patient interactions must maintain strict patient confidentiality (HIPAA / clinical ethics standards). No clinical or diagnostic claims permitted.',
    ['Genetics' => 3, 'Bioethics' => 4, 'Presentation skills' => 4, 'Academic writing' => 4]
);

a($activities,
    'Campus Green Composting & Organic Waste Audit',
    'VOLUNTEERING', 'Industrial, Food and Environmental Biotechnology', 'offline', 2, 40,
    'Students committed to environmental sustainability and circular resource utilization.',
    'Willingness to conduct hands-on field composting operations.',
    'Quantifying campus canteen food waste, managing aerobic compost windrows, monitoring temperature and moisture, and harvesting compost.',
    'Waste audit spreadsheet, compost batch temperature logs, and photographic log of compost yield.',
    'B.Sc., M.Sc.',
    'Campus waste audit report and organic composting guide adopted by campus administrative facilities.',
    'Managed an aerobic composting pilot processing 200 kg of campus cafeteria food waste weekly into nutrient-rich organic garden manure.',
    'Wear thick work gloves, sturdy boots, and dust masks during compost turning to avoid inhalation of fungal spores (Aspergillus). Wash hands thoroughly.',
    ['Microbiology' => 3, 'Teamwork' => 4, 'Project management' => 3, 'Problem solving' => 3]
);

a($activities,
    'Biodiversity Mapping & Citizen Science Flora/Fauna Census',
    'VOLUNTEERING', 'Agricultural, Plant and Crop Biotechnology', 'offline', 2, 35,
    'Nature enthusiasts interested in biodiversity documentation.',
    'Familiarity with plant/animal taxonomy and smartphone camera use.',
    'Conducting line transects, photographing species, identifying specimens, and uploading verified geo-tagged observations to iNaturalist / India Biodiversity Portal.',
    'iNaturalist profile link with verified research-grade observations and campus biodiversity checklist.',
    'B.Sc., M.Sc.',
    'Illustrated campus biodiversity checklist documenting 150+ species of native flora, pollinators, and birds.',
    'Documented 120 research-grade observations on iNaturalist contributing to national urban biodiversity mapping datasets.',
    'Field safety rules apply. Do not disturb wildlife, harvest endangered flora, or enter hazardous terrain without accompaniment and first-aid kits.',
    ['Plant Biotechnology' => 3, 'Data Analysis' => 3, 'Research methods' => 3, 'Teamwork' => 3]
);

a($activities,
    'Native Tree Plantation & Urban Forestation Drive',
    'VOLUNTEERING', 'Agricultural, Plant and Crop Biotechnology', 'offline', 2, 25,
    'All environmentally conscious students.',
    'Enthusiasm for ecological conservation and greening.',
    'Selecting climate-resilient native tree species, preparing soil pits, establishing drip irrigation, and organizing volunteer maintenance schedules.',
    'Plantation event log with GPS coordinates, survival audit schedule, and photographs.',
    'B.Sc., M.Sc.',
    'Urban afforestation project summary documenting planting and survival tracking of 200 native saplings.',
    'Organized a campus afforestation drive planting 150 indigenous trees (Neem, Peepal, Jamun) with an established 90% six-month survival rate.',
    'Use appropriate gardening tools with safety gloves. Ensure utility clearance (underground water and electric lines) before digging pits.',
    ['Teamwork' => 4, 'Leadership' => 3, 'Project management' => 3]
);

a($activities,
    'Soil Health Testing & Organic Farming Extension Drive for Local Farmers',
    'VOLUNTEERING', 'Agricultural, Plant and Crop Biotechnology', 'offline', 3, 40,
    'Students with agricultural biotechnology or soil chemistry training.',
    'Soil sampling and pH/EC/NPK chemical analysis.',
    'Conducting village soil sampling drives, executing rapid soil health kit tests, and issuing personalized soil health cards to smallholder farmers.',
    'Sample analysis register, copies of issued Soil Health Cards, and farmer acknowledgment signatures.',
    'B.Sc., M.Sc.',
    'Village soil health survey report analyzing nutrient deficiencies across 30 local farms with fertilizer advisory notes.',
    'Analyzed soil parameters for 35 local farmers, generating customized soil health reports recommending biofertilizers to reduce chemical nitrogen use.',
    'Safely dispose of soil testing chemical reagents into labeled waste bottles; do not discard test reagents directly onto agricultural fields or water sources.',
    ['Laboratory Practice' => 4, 'Plant Biotechnology' => 3, 'Presentation skills' => 4, 'Problem solving' => 3]
);

a($activities,
    'Blood Donation & Voluntary Stem Cell Donor Registry Drive',
    'VOLUNTEERING', 'Healthcare and Clinical Diagnostics', 'offline', 2, 30,
    'Students interested in community healthcare and clinical engagement.',
    'Good organizational and interpersonal communication skills.',
    'Liaising with government blood banks and national stem cell registries (DATRI), coordinating donor registration, and dispelling bone marrow donation myths.',
    'Official certificate of appreciation from blood bank/registry, donor roster count, and campaign event report.',
    'B.Sc., M.Sc.',
    'Camp summary report documenting successful donation units and registered potential stem cell donors.',
    'Coordinated campus blood donation and stem cell registry camp, facilitating collection of 110 blood units and registering 85 potential stem cell donors.',
    'All blood collection and buccal swab processing is conducted exclusively by licensed medical and paramedical personnel. Student organizers handle only registration and donor logistics.',
    ['Teamwork' => 5, 'Leadership' => 4, 'Presentation skills' => 4, 'Bioethics' => 3]
);

// -----------------------------------------------------------------------------
// Write to file database/biotech_data/activities_data.php
// -----------------------------------------------------------------------------
$code = "<?php\n/**\n * CareerAct — Comprehensive Biotechnology Extracurricular Activities Dataset\n * Exactly 53 Activities across 5 Categories with Rigorous Biosafety Guidelines\n */\n\ndeclare(strict_types=1);\n\nreturn " . var_export($activities, true) . ";\n";

file_put_contents(__DIR__ . '/activities_data.php', $code);
echo "Successfully generated activities_data.php with " . count($activities) . " activities!\n";

