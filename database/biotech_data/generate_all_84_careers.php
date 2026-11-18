<?php
/**
 * Master generator for database/biotech_data/careers_data.php
 * Generates all 84 Careers across 7 Biotechnology Specialisations
 */

declare(strict_types=1);

$careers = [];

function c(&$careers, $title, $subfield, $desc, $resp, $eduPath, $level, $empType, $minEdu, $prefEdu, $orient, $proj, $prog, $resEv, $highStudy, $skills) {
    $careers[] = [
        'title' => $title,
        'subfield_name' => $subfield,
        'description' => $desc,
        'responsibilities' => $resp,
        'education_path' => $eduPath,
        'typical_level' => $level,
        'employment_type' => $empType,
        'min_education_level' => $minEdu,
        'preferred_education_level' => $prefEdu,
        'role_orientation' => $orient,
        'practical_projects' => $proj,
        'career_progression' => $prog,
        'resume_evidence' => $resEv,
        'higher_study_options' => $highStudy,
        'required_skills' => $skills
    ];
}

// -----------------------------------------------------------------------------
// 1. Research and development (R&D) (12 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Junior Research Fellow (JRF) - Biotechnology', 'Research and Development (R&D)',
    'Conducts supervised laboratory research on sponsored biotechnology research grants, testing hypotheses, executing experimental protocols, and analyzing biological data.',
    "Prepare biochemical reagents, culture media, and bacterial/cell cultures.\nExecute molecular biology protocols including PCR, gel electrophoresis, and cloning.\nRecord rigorous laboratory notebooks and compile statistical results for project milestones.\nPresent findings at weekly lab meetings and support manuscript preparation.",
    'M.Sc. in Biotechnology, Molecular Biology, Biochemistry, or Genetics with CSIR-UGC NET (JRF) / GATE / DBT-BET qualification.',
    'entry', 'Full-time / Fellowship', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Investigation of stress-responsive promoter expression in Arabidopsis thaliana or screening microbial secondary metabolites.',
    'Junior Research Fellow (JRF) -> Senior Research Fellow (SRF) -> Research Scientist -> Principal Investigator',
    'Demonstrated proficiency in molecular cloning, qPCR, and reproducible assay execution on a DST-SERB funded project.',
    'Ph.D. in Biotechnology, Molecular Biology, or Systems Biology.',
    ['Molecular Biology' => 4, 'Laboratory Safety and Biosafety' => 4, 'Aseptic Technique' => 4, 'PCR (Polymerase Chain Reaction)' => 4, 'Gel Electrophoresis' => 4, 'Buffer and Reagent Preparation' => 4, 'Data Analysis' => 3, 'Academic writing' => 3]
);

c($careers, 'Senior Research Fellow (SRF) - Biotechnology', 'Research and Development (R&D)',
    'Leads dedicated segments of doctoral or institute research programs, troubleshooting assays, mentoring junior fellows, and publishing in peer-reviewed journals.',
    "Design advanced experimental matrices for protein characterization or metabolic engineering.\nOperate specialized instrumentation (FPLC, Flow Cytometer, Confocal Microscope).\nDraft peer-reviewed scientific articles and contribute to grant progress reports.\nSupervise undergraduate project trainees and maintain laboratory inventory.",
    'M.Sc. or M.Tech. in Biotechnology with minimum 2 years verified research experience and published research.',
    'mid', 'Full-time / Fellowship', 'postgraduate', 'doctorate', 'Laboratory-oriented',
    'Elucidation of oncogenic kinase signaling cascades and inhibition kinetics using targeted small molecules.',
    'Senior Research Fellow -> Postdoctoral Fellow -> Research Scientist -> Associate Professor / Lab Head',
    'First-author publication in a peer-reviewed journal; validated 3 novel target pathways using CRISPR-Cas9 knockdown.',
    'Doctoral Defense (Ph.D.) -> Postdoctoral Fellowship in premier international institutes.',
    ['Molecular Biology' => 4, 'Cell Biology' => 4, 'Protein Expression and Purification' => 4, 'Western Blotting' => 4, 'Research methods' => 4, 'Critical thinking' => 4, 'Presentation skills' => 4]
);

c($careers, 'Research Scientist - Molecular Biology', 'Research and Development (R&D)',
    'Formulates research hypotheses, establishes molecular assay platforms, and innovates recombinant genetic systems for industrial or medical applications.',
    "Design high-fidelity molecular constructs, expression vectors, and recombinant strains.\nLead assay development for target engagement, enzyme kinetics, and gene expression.\nCollaborate with bioinformatics and bioprocess teams for cross-functional project delivery.\nSecure intellectual property by documenting novel discoveries for patent filings.",
    'Ph.D. in Molecular Biology, Biotechnology, or Genetics; or M.Sc./M.Tech with 5+ years of demonstrable industrial R&D track record.',
    'senior', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory-oriented',
    'Engineered high-titer recombinant Pichia pastoris expression system for human serum albumin analog.',
    'Research Scientist -> Senior Scientist -> Principal Scientist -> R&D Director / VP Discovery',
    'Led a 4-person team delivering 2 proprietary molecular cloning vectors and 1 international PCT patent application.',
    'Postdoctoral Leadership Certification, Executive MBA in Biotechnology Management.',
    ['Molecular Biology' => 5, 'Gene Cloning and Recombinant DNA' => 5, 'CRISPR and Gene Editing' => 4, 'Problem solving' => 5, 'Project management' => 4, 'Teamwork' => 4]
);

c($careers, 'Principal Investigator (PI) - Biotechnology', 'Research and Development (R&D)',
    'Directs an academic or institutional research laboratory, formulates long-term scientific strategy, secures extramural grant funding, and mentors graduate students.',
    "Author major competitive research grant proposals (DBT, DST, ICMR, NIH, Wellcome Trust).\nOversee laboratory budget, equipment procurement, and regulatory biosafety approvals.\nMentor Ph.D. scholars, postdoctoral researchers, and technical staff toward career milestones.\nServe on institutional review boards (IBSC, IAEC) and peer-review scientific literature.",
    'Ph.D. in Biotechnology, Molecular Biology, or related Life Sciences with 3-5+ years postdoctoral experience.',
    'senior', 'Full-time', 'doctorate', 'doctorate', 'Laboratory & Management',
    'Established an autonomous research programme investigating antimicrobial resistance mechanisms in ESKAPE pathogens.',
    'Assistant Professor / PI -> Associate Professor -> Professor / Chair -> Dean of Science / Institute Director',
    'Principal investigator on INR 1.5 Cr extramural research grant; published 25+ papers with h-index 18.',
    'D.Sc. (Doctor of Science), Fellow of National Academy of Sciences (FNA, FASc, FNASc).',
    ['Research methods' => 5, 'Molecular Biology' => 5, 'Academic writing' => 5, 'Project management' => 5, 'Presentation skills' => 5, 'Laboratory Safety and Biosafety' => 4]
);

c($careers, 'Protein Biochemist', 'Research and Development (R&D)',
    'Specializes in recombinant protein expression, chromatographic purification, biochemical characterization, and structural integrity analysis.',
    "Develop purification protocols using affinity, ion exchange, and size-exclusion chromatography (SEC).\nQuantify protein concentrations, purity, and oligomeric states using SDS-PAGE, DLS, and CD spectroscopy.\nExecute in vitro enzymatic assays and determine Michaelis-Menten kinetic parameters.\nTroubleshoot protein insolubility, aggregation, and refolding conditions.",
    'M.Sc. or Ph.D. in Biochemistry, Biotechnology, or Biophysics.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory-oriented',
    'Purification and crystallographic screening of a bacterial beta-lactamase enzyme bound to novel inhibitor.',
    'Associate Protein Biochemist -> Senior Biochemist -> Group Leader Downstream & Structural Biology',
    'Purified over 30 recombinant therapeutic protein batches with >95% purity and validated monomeric integrity.',
    'Ph.D. in Structural Biology, Cryo-EM Specialization.',
    ['Biochemistry' => 5, 'Protein Expression and Purification' => 5, 'Chromatography (HPLC / FPLC)' => 5, 'Spectrophotometry and Colorimetric Assays' => 4, 'SDS-PAGE' => 5]
);

c($careers, 'Cell Line Development Scientist', 'Research and Development (R&D)',
    'Generates and screens high-producing, stable mammalian cell clones (CHO, HEK293) for recombinant therapeutic protein and antibody production.',
    "Transfect mammalian host cells and perform single-cell cloning using FACS or limiting dilution.\nScreen hundreds of clones for specific productivity (qP), growth kinetics, and product quality.\nEstablish and bank research cell banks (RCB) and master cell banks (MCB) under cryopreservation standards.\nAssess clonality assurance and genetic stability over multiple population doublings.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Cell Biology, or Biochemical Engineering.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Generated a clonal CHO-DG44 cell line expressing 3.2 g/L of a monoclonal antibody candidate.',
    'Cell Line Associate -> Scientist -> Senior Scientist Cell Line Engineering -> Head of Upstream R&D',
    'Successfully established 3 clonal mammalian cell lines adhering to ICH Q5D guidelines for monoclonality documentation.',
    'Doctorate in Mammalian Synthetic Biology or Industrial Bioprocessing.',
    ['Mammalian Cell Culture' => 5, 'Cell Biology' => 4, 'Flow Cytometry (FACS)' => 4, 'Gene Cloning and Recombinant DNA' => 4, 'Aseptic Technique' => 5]
);

c($careers, 'Enzymologist / Biocatalysis Scientist', 'Research and Development (R&D)',
    'Explores natural and engineered biocatalysts for green chemical synthesis, pharmaceutical intermediates, and industrial biotransformations.',
    "Screen novel microbial and metagenomic enzyme libraries for desired catalytic activities.\nPerform rational design and directed evolution of enzymes using error-prone PCR and computational modelling.\nOptimize immobilized enzyme systems and evaluate operational stability in continuous flow reactors.\nMeasure enantioselectivity, regioselectivity, and catalytic turnover (kcat/Km).",
    'M.Sc. or Ph.D. in Biochemistry, Enzymology, Biotechnology, or Chemical Biology.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory-oriented',
    'Engineered a transaminase with 40-fold improved thermostability and 99% enantiomeric excess for chiral amine synthesis.',
    'Biocatalysis Chemist -> Enzymology Scientist -> Principal Scientist Biotransformations',
    'Characterized 5 novel esterases from hot spring metagenomic samples and optimized reaction yields to 92%.',
    'Postdoctoral fellowship in Directed Evolution or Computational Protein Engineering.',
    ['Biochemistry' => 5, 'Enzyme Kinetics' => 5, 'Protein Expression and Purification' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Molecular Modeling and Docking' => 3]
);

c($careers, 'Synthetic Biologist', 'Research and Development (R&D)',
    'Designs and constructs biological circuits, synthetic metabolic pathways, and engineered microbial hosts to manufacture novel compounds and smart biosensors.',
    "Assemble complex multi-gene metabolic operons using Golden Gate, Gibson Assembly, or modular cloning.\nImplement CRISPR-dCas9 transcriptional regulators and synthetic genetic logic gates.\nOptimize metabolic flux toward target molecules using metabolomics and metabolic control analysis.\nModel genetic circuit behavior in silico prior to laboratory wet-lab implementation.",
    'M.Sc., M.Tech., or Ph.D. in Synthetic Biology, Biotechnology, Molecular Biology, or Systems Biology.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory & Computational',
    'Engineered a Saccharomyces cerevisiae strain with a synthetic pathway to synthesize terpene fragrance molecules from glycerol.',
    'Synthetic Biology Associate -> Senior Synthetic Biologist -> Lead Platform Architect',
    'iGEM team alumni; published synthetic gene network regulation study; assembled 12-gene metabolic pathway.',
    'Ph.D. in Synthetic Biology, Fellowship in Engineering Biology.',
    ['Molecular Biology' => 5, 'Gene Cloning and Recombinant DNA' => 5, 'CRISPR and Gene Editing' => 5, 'Metabolic Engineering' => 5, 'Bioinformatics' => 4]
);

c($careers, 'Microbiologist - R&D', 'Research and Development (R&D)',
    'Isolates, identifies, and characterises micro-organisms with industrial, agricultural, or therapeutic utility, studying microbial genetics and physiology.',
    "Sample diverse environments for extremophilic and novel microbial species.\nPerform 16S rRNA gene sequencing and phenotypic profiling for strain identification.\nFormulate defined cultivation media and optimize secondary metabolite biosynthesis.\nMaintain sterile working environments and curate cryopreserved culture collections.",
    'B.Sc. or M.Sc. in Microbiology, Biotechnology, or Life Sciences.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory-oriented',
    'Isolated 14 actinomycete strains from rhizosphere soils showing potent antagonism against clinical MRSA isolates.',
    'Trainee Microbiologist -> R&D Microbiologist -> Senior Microbiologist -> Microbiology Group Leader',
    'Characterized antibiotic susceptibility patterns of 60 bacterial isolates using CLSI broth microdilution standards.',
    'M.Sc. Microbiology, Ph.D. in Applied Microbiology and Microbial Genomics.',
    ['Microbiology' => 5, 'Aseptic Technique' => 5, 'Microbial Culture' => 5, 'Staining Techniques' => 4, 'Laboratory Safety and Biosafety' => 4]
);

c($careers, 'Assay Development Scientist', 'Research and Development (R&D)',
    'Develops, optimizes, and validates robust high-throughput biochemical and cell-based assays for drug discovery and biomarker detection.',
    "Formulate fluorescent, luminescent, and colorimetric binding and functional assay formats.\nCalculate assay quality parameters including Z-prime factor, signal-to-noise ratio, and coefficient of variation.\nAdapt manual benchtop assays to automated liquid handling and robotic screening platforms.\nWrite standardized assay execution protocols and statistical validation summaries.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Biochemistry, Pharmacology, or Molecular Biology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Developed a 384-well homogeneous time-resolved fluorescence (HTRF) assay for screening kinase inhibitors.',
    'Assay Scientist -> Senior Assay Scientist -> High-Throughput Screening (HTS) Manager',
    'Validated high-throughput cell viability assay with consistent Z-prime > 0.75 across 10,000 compound screen.',
    'Ph.D. in Chemical Biology or In Vitro Pharmacology.',
    ['ELISA and Immunoassays' => 5, 'Mammalian Cell Culture' => 4, 'Spectrophotometry and Colorimetric Assays' => 4, 'Data Analysis' => 4, 'Quality Control (QC)' => 4]
);

c($careers, 'Laboratory Manager - Biotechnology Research', 'Research and Development (R&D)',
    'Manages day-to-day operations of modern biotech laboratories, ensuring equipment calibration, chemical inventory, biosafety compliance, and efficient workflow.',
    "Supervise annual maintenance contracts (AMC), preventive maintenance, and calibration of instruments.\nManage procurement of bio-reagents, consumables, primers, and hazardous chemicals under budget.\nEnforce Good Laboratory Practice (GLP) and institutional biosafety guidelines.\nConduct safety inductions and hazard communication training for students and technical staff.",
    'B.Sc., M.Sc., or M.Tech. in Biotechnology, Chemistry, or Life Sciences with 3-5 years laboratory experience.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Operations & Management',
    'Overhauled chemical and biological inventory to digital barcode tracking, reducing waste and expired stock by 35%.',
    'Laboratory Executive -> Laboratory Manager -> Operations Director - Scientific Facilities',
    'Managed 15-bench biotechnology research facility maintaining 100% compliance during annual environmental and safety audits.',
    'Postgraduate Diploma in Scientific Management or Laboratory Health & Safety (NEBOSH).',
    ['Laboratory Practice' => 5, 'Laboratory Safety and Biosafety' => 5, 'Good Laboratory Practice (GLP)' => 5, 'Project management' => 4, 'Teamwork' => 4]
);

c($careers, 'Postdoctoral Research Fellow', 'Research and Development (R&D)',
    'Conducts independent, advanced research in world-class academic institutes or biotech research centers, pioneering discoveries and mentoring PhD candidates.',
    "Formulate independent sub-projects within larger funded research consortia.\nAuthor high-impact research publications in top-quartile journals.\nPresent research at national and international conferences.\nWrite fellowship and exploratory grant proposals to transition toward faculty or industrial scientist roles.",
    'Ph.D. in Biotechnology, Molecular Biology, Biochemistry, or related life science branch.',
    'senior', 'Full-time / Fellowship', 'doctorate', 'doctorate', 'Advanced Research',
    'Characterization of eukaryotic chromatin remodeling complexes using single-particle cryo-EM and ChIP-seq.',
    'Postdoctoral Fellow -> Senior Postdoctoral Fellow -> Assistant Professor / Senior Scientist',
    'Published 3 first-author papers in international peer-reviewed journals; awarded prestigious national postdoctoral fellowship.',
    'Tenure-track academic faculty appointment or Principal Scientist in biopharmaceutical R&D.',
    ['Research methods' => 5, 'Molecular Biology' => 5, 'Academic writing' => 5, 'Presentation skills' => 5, 'Critical thinking' => 5]
);

// -----------------------------------------------------------------------------
// 2. Biopharmaceutical and pharmaceutical biotechnology (14 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Upstream Bioprocess Scientist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Develops and optimizes cell culture and microbial fermentation processes in benchtop to pilot-scale bioreactors for recombinant therapeutic production.',
    "Formulate fed-batch and perfusion feeding strategies to maximize viable cell density and specific productivity.\nOperate 5L to 200L single-use and stainless-steel bioreactors with automated DO and pH control loops.\nPerform root-cause investigations into culture drift, lactate accumulation, and nutrient depletion.\nDraft Tech Transfer documents for pilot-to-commercial manufacturing scale-up.",
    'B.Tech., M.Sc., or M.Tech. in Biotechnology, Bioprocess Engineering, or Biochemical Engineering.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory & Production',
    'Optimized amino acid feeding strategy in 10L CHO bioreactor increasing mAb titer from 2.1 to 4.4 g/L.',
    'Bioprocess Associate -> Upstream Scientist -> Senior Upstream Scientist -> Upstream Development Head',
    'Authored 4 Tech Transfer packages to pilot manufacturing; achieved zero contamination runs across 18 consecutive campaigns.',
    'Ph.D. in Biochemical Engineering or Industrial Bioprocess Optimization.',
    ['Bioreactor Operation and Fermentation' => 5, 'Mammalian Cell Culture' => 5, 'Upstream Bioprocessing' => 5, 'Aseptic Technique' => 5, 'Good Manufacturing Practice (GMP)' => 4]
);

c($careers, 'Downstream Processing Scientist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Designs, develops, and scales up purification processes for biologics, including centrifugation, depth filtration, chromatography, and tangential flow filtration (TFF).',
    "Screen resin chemistries (Protein A, CEX, AEX, HIC) for optimal host cell protein (HCP) and DNA clearance.\nOperate automated chromatography skids (AKTA avant/process) for preparative purification.\nOptimize ultrafiltration/diafiltration (UF/DF) parameters for high-concentration formulation.\nExecute viral clearance studies (viral inactivation at low pH and viral nanofiltration).",
    'B.Tech., M.Sc., or M.Tech. in Biotechnology, Chemical Engineering, or Biochemistry.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory & Production',
    'Developed a 3-step chromatography purification platform achieving >99.2% monomer purity and 78% overall step yield.',
    'Downstream Associate -> Downstream Scientist -> Purification Group Leader -> Director DSP',
    'Hands-on expertise in AKTA FPLC operation, column packing integrity testing (HETP), and membrane flux optimization.',
    'Ph.D. in Biochemical Separation Processes or Downstream Bioprocess Engineering.',
    ['Downstream Processing' => 5, 'Chromatography (HPLC / FPLC)' => 5, 'Tangential Flow Filtration (TFF)' => 5, 'Protein Expression and Purification' => 4, 'Good Manufacturing Practice (GMP)' => 4]
);

c($careers, 'Formulation Scientist - Biologics', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Develops stable liquid and lyophilized formulations for therapeutic proteins, monoclonal antibodies, and vaccines to prevent aggregation and degradation.',
    "Screen excipients, buffers, sugars, and surfactants for preventing thermal and mechanical protein stress.\nPerform accelerated stability studies using SEC-HPLC, dynamic light scattering (DLS), and sub-visible particle counting.\nFormulate freeze-drying (lyophilization) thermal cycles and determine glass transition temperature (Tg').\nInvestigate drug-container interaction and silicone oil compatibility in pre-filled syringes.",
    'M.Pharm., M.S., or Ph.D. in Pharmaceutics, Biotechnology, or Physical Biochemistry.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Formulated high-concentration (150 mg/mL) subcutaneous antibody formulation with viscosity < 15 cP and >24 months room temperature stability.',
    'Formulation Associate -> Scientist Formulation -> Head of Drug Product Development',
    'Designed stability testing protocols conforming to ICH Q1A and ICH Q5C requirements.',
    'Ph.D. in Pharmaceutical Sciences or Biophysical Protein Chemistry.',
    ['Biochemistry' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Quality Control (QC)' => 4, 'Good Manufacturing Practice (GMP)' => 4, 'Problem solving' => 4]
);

c($careers, 'Monoclonal Antibody (mAb) Production Specialist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Oversees end-to-end biomanufacturing runs for monoclonal antibody therapeutics, ensuring adherence to cGMP and batch record specifications.',
    "Execute master batch records (MBR) for commercial mAb manufacturing campaigns.\nMonitor critical process parameters (CPP) and critical quality attributes (CQA) during cultivation and harvesting.\nEnsure clean-in-place (CIP) and steam-in-place (SIP) cycles meet validated sterility levels.\nInitiate and investigate deviations, out-of-specification (OOS) events, and corrective actions (CAPA).",
    'B.Sc., B.Tech., or M.Sc. in Biotechnology, Industrial Microbiology, or Biochemical Engineering.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Manufacturing & Plant',
    'Supervised 2000L bioreactor campaign producing commercial biosimilar rituximab with zero critical deviations.',
    'Manufacturing Technician -> Production Specialist -> Shift Lead -> Biomanufacturing Plant Manager',
    'Trained in aseptic cleanroom gowning Grade A/B, electronic batch records (eBR), and automated SCADA systems.',
    'Advanced Professional Certification in Biopharmaceutical Manufacturing (ISPE / PDA).',
    ['Good Manufacturing Practice (GMP)' => 5, 'Bioreactor Operation and Fermentation' => 4, 'Upstream Bioprocessing' => 4, 'Downstream Processing' => 4, 'Aseptic Technique' => 5]
);

c($careers, 'Vaccine Development Scientist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Designs and evaluates prophylactic and therapeutic vaccine platforms including recombinant protein sub-units, mRNA vaccines, and viral vectors.',
    "Clone and express recombinant antigenic targets and adjuvant conjugates.\nFormulate lipid nanoparticles (LNPs) for encapsulation and cellular delivery of mRNA payloads.\nConduct in vitro antigenicity and neutralization assays using pseudovirus models.\nCharacterize humoral and cell-mediated immune responses in pre-clinical animal models.",
    'M.Sc., M.Tech., or Ph.D. in Immunology, Virology, Biotechnology, or Vaccinology.',
    'senior', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory-oriented',
    'Developed a subunit recombinant booster candidate against Dengue virus non-structural protein 1 (NS1).',
    'Vaccine Scientist -> Senior Immunologist -> Director of Vaccine R&D',
    'Co-authored 2 vaccine discovery papers and demonstrated high neutralizing antibody titers in preclinical challenge models.',
    'Ph.D. in Vaccinology or Molecular Immunology.',
    ['Immunology' => 5, 'Molecular Biology' => 4, 'ELISA and Immunoassays' => 5, 'Flow Cytometry (FACS)' => 4, 'Mammalian Cell Culture' => 4]
);

c($careers, 'Cell and Gene Therapy Specialist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Engineers patient-derived and allogeneic cell products (CAR-T, stem cells) and viral vectors (AAV, Lentivirus) for advanced therapeutic medicinal products (ATMPs).',
    "Perform primary T-cell isolation, activation, viral transduction, and ex vivo expansion in closed bioreactor systems.\nProduce and titrate lentiviral and adeno-associated viral (AAV) vectors using ultracentrifugation and qPCR.\nCharacterize cell phenotype, exhaustion markers, and vector copy number (VCN) per cell.\nAdhere to cleanroom Grade A isolator environments and strict chain-of-identity protocols.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Stem Cell Biology, or Molecular Medicine.',
    'senior', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory & Clinical',
    'Developed a closed-system manufacturing workflow for second-generation CD19 CAR-T cells yielding >10^9 viable cells with 68% transduction.',
    'Cell Therapy Associate -> Gene Therapy Specialist -> Senior Scientist Advanced Therapeutics',
    'Demonstrated competence in primary cell culture, non-viral electroporation, and flow-cytometric multi-color immunophenotyping.',
    'Ph.D. in Regenerative Medicine or Cell and Gene Therapy Engineering.',
    ['Mammalian Cell Culture' => 5, 'Flow Cytometry (FACS)' => 5, 'CRISPR and Gene Editing' => 4, 'Molecular Biology' => 4, 'Good Manufacturing Practice (GMP)' => 4]
);

c($careers, 'Analytical Development Scientist - Biopharma', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Establishes and validates physico-chemical and biophysical analytical methods to characterize biologics and release drug substances.',
    "Develop reversed-phase, ion-exchange, and size-exclusion HPLC/UPLC methods for purity quantification.\nPerform peptide mapping and intact mass spectrometry (LC-MS/MS) for post-translational modification (PTM) profiling.\nValidate analytical methods according to ICH Q2(R1) guidelines for linearity, range, precision, and accuracy.\nAuthor method validation protocols and transfer analytical packages to QC release laboratories.",
    'M.Sc., M.Tech., or Ph.D. in Analytical Chemistry, Biochemistry, or Pharmaceutical Biotechnology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Developed a high-resolution peptide mapping LC-MS method quantifying N-glycosylation microheterogeneity in trastuzumab biosimilar.',
    'Analytical Chemist -> Analytical Development Scientist -> Principal Scientist Analytical Characterization',
    'Validated 6 distinct physicochemical methods submitted in regulatory dossier (IND/BLA) modules.',
    'Ph.D. in Bioanalytical Mass Spectrometry or Biophysical Chemistry.',
    ['Chromatography (HPLC / FPLC)' => 5, 'Mass Spectrometry' => 5, 'Quality Control (QC)' => 5, 'Biochemistry' => 4, 'Good Laboratory Practice (GLP)' => 4]
);

c($careers, 'Fermentation Process Engineer', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Focuses on engineering principles of biological reactor systems, addressing mass transfer (kLa), heat transfer, fluid dynamics, and automated process control.',
    "Calculate and model oxygen transfer rates (OTR) and carbon dioxide evolution rates (CER) in large stirred-tank reactors.\nDesign computational fluid dynamic (CFD) models of impeller geometries for low shear and optimal mixing.\nTroubleshoot foaming, shear sensitivity, and cooling limitations during process scale-up.\nIntegrate process analytical technology (PAT) probes (Raman, NIR, off-gas analyzers) into PLC/SCADA control systems.",
    'B.Tech. or M.Tech. in Bioprocess Engineering, Chemical Engineering, or Biotechnology.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Engineering & Production',
    'Scaled up microbial fermentation from 50L pilot to 15,000L production vessel achieving identical specific growth rates and product profile.',
    'Process Engineer -> Senior Fermentation Engineer -> Engineering Lead Bioprocessing',
    'Certified in Aspen Plus / SuperPro Designer process simulation; expertise in mass/energy balances of bioprocesses.',
    'Ph.D. in Chemical and Biomolecular Engineering.',
    ['Bioreactor Operation and Fermentation' => 5, 'Upstream Bioprocessing' => 5, 'Bioprocess Scale-Up' => 5, 'Data Analysis' => 4, 'Problem solving' => 5]
);

c($careers, 'Biomanufacturing Operations Associate', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Executes day-to-day cleanroom manufacturing operations, equipment preparation, media/buffer formulation, and equipment sanitization.',
    "Prepare large-volume buffers and growth media following validated batch production records.\nOperate CIP/SIP systems, autoclaves, and clean utilities (WFI, clean steam, compressed air).\nPerform aseptic connections using sterile tube welders and sterile disconnectors.\nMaintain detailed documentation in accordance with Data Integrity and ALCOA+ principles.",
    'B.Sc. or B.Tech. in Biotechnology, Biochemistry, Microbiology, or Chemical Engineering.',
    'entry', 'Full-time', 'undergraduate', 'undergraduate', 'Manufacturing Plant',
    'Formulated and sterile-filtered 50,000 liters of biopharmaceutical buffers with zero batch record rejections.',
    'Operations Associate -> Senior Operations Associate -> Shift Supervisor -> Manufacturing Manager',
    'Certified in cleanroom gowning Grade C/D; hands-on knowledge of cGMP documentation standards.',
    'M.Sc. or M.Tech. in Industrial Biotechnology or Operations Management.',
    ['Good Manufacturing Practice (GMP)' => 4, 'Aseptic Technique' => 4, 'Buffer and Reagent Preparation' => 5, 'Laboratory Safety and Biosafety' => 4, 'Teamwork' => 4]
);

c($careers, 'Technology Transfer Scientist - Bioprocess', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Acts as the bridge between R&D development and commercial manufacturing plants, transferring processes, equipment scale parameters, and recipes.',
    "Author Technology Transfer Plans (TTP), Process Descriptions, and Gap Analysis Risk Assessments.\nMap pilot equipment capabilities to facility fit requirements at receiving manufacturing sites.\nProvide on-floor technical oversight during water runs, engineering runs, and demonstration batches.\nTroubleshoot process divergences between laboratory and manufacturing scale.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Chemical Engineering, or Biochemical Engineering.',
    'senior', 'Full-time', 'postgraduate', 'postgraduate', 'Technical & Operations',
    'Successfully transferred a recombinant enzyme production process across international manufacturing facilities with 100% equivalence.',
    'Tech Transfer Scientist -> Senior Tech Transfer Manager -> Director Global Manufacturing Science and Technology (MSAT)',
    'Managed cross-functional transfer team; authored 8 MSAT technical reports and validation protocols.',
    'MBA in Operations or Leadership in Global Pharmaceutical Supply Chains.',
    ['Bioprocess Scale-Up' => 5, 'Good Manufacturing Practice (GMP)' => 4, 'Project management' => 5, 'Upstream Bioprocessing' => 4, 'Downstream Processing' => 4]
);

c($careers, 'QC Microbiologist - Pharma & Biologics', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Performs mandatory microbial quality control testing on raw materials, in-process samples, water systems, cleanrooms, and finished parenteral products.',
    "Conduct bioburden testing, microbial limits testing, and sterility testing in Grade A laminar flow hoods.\nPerform bacterial endotoxin testing (BET) using Kinetic Chromogenic / Turbidimetric LAL assays.\nExecute routine environmental monitoring (active air, passive settle plates, surface contact plates, and personnel swabs).\nIdentify environmental microbial isolates using phenotypic and MALDI-TOF mass spectrometry.",
    'B.Sc. or M.Sc. in Microbiology, Biotechnology, or Life Sciences.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory-oriented',
    'Performed over 500 environmental monitoring assays across Grade A to D cleanroom suites maintaining zero OOS delays.',
    'QC Microbiologist -> Senior QC Microbiologist -> QC Microbiology Supervisor -> QC Manager',
    'Trained in pharmacopeial microbial testing (IP, USP, EP, JP); expert in LAL pyrogen testing.',
    'Postgraduate Diploma in Pharmaceutical Quality Assurance.',
    ['Microbiology' => 5, 'Quality Control (QC)' => 5, 'Aseptic Technique' => 5, 'Good Manufacturing Practice (GMP)' => 4, 'Good Laboratory Practice (GLP)' => 4]
);

c($careers, 'QC Chemist - Biologics', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Executes routine release and stability testing of biopharmaceutical drug products using validated chemical, chromatographic, and electrophoretic methods.',
    "Test commercial drug product lots for pH, osmolality, protein concentration, and particulate matter.\nRun routine HPLC/UPLC assays (SEC-HPLC for aggregates, CEX-HPLC for charge variants).\nPerform capillary electrophoresis (CE-SDS) and isoelectric focusing for molecular integrity.\nDocument analytical results directly in validated Laboratory Information Management Systems (LIMS).",
    'B.Sc., B.Pharm., or M.Sc. in Chemistry, Biochemistry, or Biotechnology.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory-oriented',
    'Analyzed 120 commercial batches of recombinant insulin using validated RP-HPLC and SEC assays.',
    'QC Analyst -> Senior QC Chemist -> QC Section Head -> Quality Control Director',
    'Hands-on expertise in Waters Empower software, Agilent ChemStation, and pharmacopeial wet chemical testing.',
    'M.Sc. in Analytical Chemistry or Pharmaceutical Analysis.',
    ['Chromatography (HPLC / FPLC)' => 4, 'Quality Control (QC)' => 5, 'Good Laboratory Practice (GLP)' => 5, 'Spectrophotometry and Colorimetric Assays' => 4, 'SDS-PAGE' => 4]
);

c($careers, 'Lyophilization Specialist - Biopharma', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Designs, optimizes, and oversees freeze-drying processes to stabilize temperature-sensitive biologics, vaccines, and diagnostic reagents.',
    "Determine critical formulation temperatures (collapse temperature Tc, eutectic temperature Te) via freeze-dry microscopy.\nProgram and optimize primary and secondary drying ramp rates and chamber vacuum pressures.\nOperate industrial freeze-dryers with automated vial stoppering under nitrogen backfill.\nCharacterize cake appearance, reconstitution time, and residual moisture content via Karl Fischer titration.",
    'M.Sc. or M.Tech. in Biotechnology, Pharmaceutics, or Chemical Engineering.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Production',
    'Reduced lyophilization cycle duration by 28 hours while preserving vaccine titer and achieving residual moisture < 1.2%.',
    'Lyo Process Associate -> Lyophilization Specialist -> Senior Freeze-Drying Scientist',
    'Formulated 10+ stable freeze-dried biological formulations with demonstrated accelerated stability.',
    'Ph.D. in Pharmaceutical Technology or Cryobiology.',
    ['Biochemistry' => 4, 'Good Manufacturing Practice (GMP)' => 4, 'Problem solving' => 4, 'Quality Control (QC)' => 4, 'Bioprocess Scale-Up' => 3]
);

c($careers, 'Biosimilar Development Scientist', 'Biopharmaceutical and Pharmaceutical Biotechnology',
    'Performs comprehensive structural, physicochemical, and functional biosimilarity exercises establishing equivalence with originator reference biologics.',
    "Conduct extensive head-to-head similarity testing with reference product lots across primary, secondary, and tertiary structures.\nExecute antibody-dependent cellular cytotoxicity (ADCC) and complement-dependent cytotoxicity (CDC) functional bioassays.\nAssess pharmacokinetic and immunogenicity profiles using validated surface plasmon resonance (Biacore) assays.\nCompile comprehensive similarity dossiers aligned with WHO, EMA, and US FDA biosimilar guidance.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Biochemistry, or Molecular Pharmacology.',
    'senior', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory & Regulatory',
    'Coordinated totality-of-the-evidence analytical biosimilarity package for adalimumab biosimilar candidate for regulatory submission.',
    'Biosimilar Scientist -> Senior Biosimilar Lead -> Head of Biologics Development',
    'Demonstrated expertise in Biacore SPR binding affinity, reporter gene cell assays, and regulatory similarity submission.',
    'Executive Leadership in Biopharmaceutical Innovation.',
    ['Biochemistry' => 5, 'ELISA and Immunoassays' => 5, 'Regulatory Affairs (CDSCO/FDA/EMA)' => 5, 'Chromatography (HPLC / FPLC)' => 4, 'Mammalian Cell Culture' => 4]
);

// -----------------------------------------------------------------------------
// 3. Healthcare and clinical diagnostics (12 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Clinical Molecular Diagnostician', 'Healthcare and Clinical Diagnostics',
    'Performs and interprets high-complexity nucleic acid-based diagnostic tests (real-time PCR, Sanger sequencing, NGS panels) for patient management.',
    "Extract genomic DNA/RNA from clinical blood, tissue, and swab specimens under NABL/CAP guidelines.\nPerform diagnostic multiplex qPCR for infectious pathogens (HIV, HBV, HCV, Mycobacterium tuberculosis).\nAnalyze melting curves and cycle threshold (Ct) values; report clinical actionable findings.\nMaintain strict contamination prevention protocols in separated pre-PCR, PCR, and post-PCR rooms.",
    'M.Sc. in Medical Biotechnology, Molecular Biology, Genetics, or Microbiology.',
    'entry', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical Laboratory',
    'Validated and processed over 2,500 real-time RT-PCR diagnostic assays maintaining 100% external quality assurance scheme (EQAS) compliance.',
    'Molecular Diagnostic Technologist -> Senior Diagnostician -> Molecular Diagnostic Lab Director',
    'Certification in Clinical Molecular Pathology testing; expert in CLSI molecular diagnostic guidelines.',
    'Ph.D. in Clinical Biochemistry or Molecular Diagnostics.',
    ['Quantitative PCR (RT-qPCR)' => 5, 'Molecular Biology' => 4, 'Good Clinical Laboratory Practice (GCLP)' => 5, 'Quality Control (QC)' => 4, 'Aseptic Technique' => 4]
);

c($careers, 'Cytogeneticist / Cytogenetics Technologist', 'Healthcare and Clinical Diagnostics',
    'Analyzes human chromosomes for numerical and structural abnormalities associated with congenital disorders, infertility, and hematologic malignancies.',
    "Harvest lymphocytes, bone marrow, and amniotic fluid cells for metaphase chromosome preparation.\nPerform G-banding (GTG) and karyotype analysis using automated karyotyping imaging software.\nExecute Fluorescence In Situ Hybridization (FISH) on metaphase spreads and interphase nuclei.\nReport cytogenetic findings according to International System for Human Cytogenomic Nomenclature (ISCN).",
    'M.Sc. in Genetics, Medical Biotechnology, Human Genetics, or Zoology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical Laboratory',
    'Analyzed and karyotyped over 800 constitutional and leukemia cytogenetic cases identifying reciprocal translocations and trisomies.',
    'Junior Cytogeneticist -> Cytogenetic Technologist -> Senior Cytogeneticist -> Chief of Cytogenomics',
    'Trained in metaphase chromosome scoring, dual-color break-apart FISH probes, and ISCN reporting.',
    'Ph.D. in Human Cytogenetics and Genomic Medicine.',
    ['Genetics' => 5, 'Fluorescence In Situ Hybridization (FISH)' => 5, 'Karyotyping and Cytogenetics' => 5, 'Microscopy (Brightfield / Fluorescence)' => 5, 'Good Clinical Laboratory Practice (GCLP)' => 4]
);

c($careers, 'Medical Laboratory Technologist - Molecular Lab', 'Healthcare and Clinical Diagnostics',
    'Executes day-to-day diagnostic assays in accredited hospital pathology and molecular testing laboratories.',
    "Accession and inspect clinical patient specimens for integrity and diagnostic suitability.\nPrepare reagents, calibrate thermal cyclers, and execute automated nucleic acid extractions.\nPerform initial run quality checks including positive, negative, and internal extraction controls.\nMaintain equipment logs and report equipment drift to senior scientific officers.",
    'B.Sc. in Medical Laboratory Technology (BMLT), B.Sc. Medical Biotechnology, or B.Sc. Microbiology.',
    'entry', 'Full-time', 'undergraduate', 'undergraduate', 'Clinical Laboratory',
    'Processed routine diagnostic specimen batches with 99.8% on-time turnaround time (TAT).',
    'Junior Lab Technologist -> Senior Medical Lab Technologist -> Technical Supervisor',
    'Registered Medical Laboratory Technologist with state paramedical council; trained in hospital LIS workflows.',
    'M.Sc. in Medical Laboratory Technology or Medical Microbiology.',
    ['DNA / RNA Extraction' => 5, 'Aseptic Technique' => 4, 'Good Clinical Laboratory Practice (GCLP)' => 4, 'Laboratory Safety and Biosafety' => 4, 'Teamwork' => 4]
);

c($careers, 'Genetic Counselor', 'Healthcare and Clinical Diagnostics',
    'Evaluates individual and family medical histories to assess risk of inherited diseases, interpreting genetic tests and guiding patients.',
    "Construct detailed multi-generational family pedigrees analyzing Mendelian and non-Mendelian inheritance patterns.\nEducate patients on complex genomic testing options (carrier screening, exome sequencing, prenatal tests).\nInterpret genetic test reports and explain variant classifications (pathogenic, VUS, benign) in empathetic language.\nCoordinate with medical geneticists and multidisciplinary clinical oncology teams.",
    'M.Sc. in Genetic Counseling, Human Genetics, or Medical Genetics with professional board certification.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical & Patient-Facing',
    'Counseled over 300 families with hereditary breast and ovarian cancer (HBOC) syndrome and familial hypercholesterolemia.',
    'Associate Genetic Counselor -> Senior Genetic Counselor -> Genetic Counseling Program Director',
    'Board Certified Genetic Counselor (BGCI or equivalent); author of patient genetic literacy guides.',
    'Doctorate in Genetic Counseling, Public Health Genetics, or Clinical Ethics.',
    ['Genetics' => 5, 'Bioethics' => 5, 'Academic writing' => 4, 'Critical thinking' => 4, 'Presentation skills' => 5]
);

c($careers, 'Molecular Pathologist', 'Healthcare and Clinical Diagnostics',
    'Directs diagnostic molecular laboratories, interpreting genomic sequencing assays to diagnose cancers, hereditary diseases, and rare mutations.',
    "Review and sign out next-generation sequencing (NGS) comprehensive oncology gene panels.\nCorrelate histopathological findings with somatic mutation profiles (EGFR, BRAF, KRAS, TP53).\nLead molecular tumor boards providing evidence-based precision targeted therapy recommendations.\nOversee NABL / CAP laboratory accreditation audits and proficiency testing programs.",
    'M.D. in Pathology / Ph.D. in Molecular Pathology or Human Genetics.',
    'senior', 'Full-time', 'doctorate', 'doctorate', 'Clinical & Diagnostic',
    'Signed out 1,200+ clinical somatic cancer NGS panels and established institutional variant interpretation guidelines.',
    'Molecular Pathologist -> Laboratory Medical Director -> Chair of Molecular Pathology',
    'Board certified pathologist / clinical laboratory director; recognized expert in AMP/ASCO/CAP variant curation.',
    'Fellow of Royal College of Pathologists / College of American Pathologists.',
    ['Genetics' => 5, 'Molecular Biology' => 5, 'Good Clinical Laboratory Practice (GCLP)' => 5, 'Research methods' => 4, 'Critical thinking' => 5]
);

c($careers, 'Immunodiagnostics Specialist', 'Healthcare and Clinical Diagnostics',
    'Designs and performs automated chemiluminescent (CLIA), ELISA, and multiplex immunoassay panels for autoimmune diseases and infectious serology.',
    "Operate and calibrate automated random-access chemiluminescent immunoassay analyzers.\nValidate assay sensitivity and analytical specificity using receiver operating characteristic (ROC) curves.\nScreen for antinuclear antibodies (ANA) using indirect immunofluorescence on HEp-2 cells.\nTroubleshoot heterophile antibody interference and prozone/hook effects in patient samples.",
    'M.Sc. in Immunology, Medical Biotechnology, or Biochemistry.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical Laboratory',
    'Implemented a 16-parameter autoimmune liver disease line-blot panel reducing turnaround time from 7 days to 24 hours.',
    'Immunology Technologist -> Immunodiagnostics Specialist -> Technical Head Clinical Serology',
    'Certified in automated clinical immunoassay platforms (Roche Cobas, Abbott Architect, Diasorin Liaison).',
    'Ph.D. in Immunobiology or Clinical Immunology.',
    ['Immunology' => 5, 'ELISA and Immunoassays' => 5, 'Good Clinical Laboratory Practice (GCLP)' => 4, 'Quality Control (QC)' => 4, 'Data Analysis' => 4]
);

c($careers, 'Clinical Research Coordinator (CRC) - Biotech', 'Healthcare and Clinical Diagnostics',
    'Coordinates clinical drug, vaccine, and medical device trials at hospital investigational sites following ICH-GCP guidelines.',
    "Screen and recruit eligible patient subjects according to protocol inclusion and exclusion criteria.\nObtain and document voluntary informed consent under ethical standards.\nCoordinate patient study visits, investigational product administration, and laboratory specimen processing.\nMaintain essential regulatory binders, electronic case report forms (eCRF), and report adverse events.",
    'B.Sc., M.Sc., B.Pharm., or Pharm.D. in Biotechnology, Life Sciences, or Pharmacy.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Clinical Site Operations',
    'Coordinated Phase III multi-center immunotherapy clinical trial enrolling 45 patients with 98% protocol adherence.',
    'Clinical Research Coordinator -> Senior CRC -> Site Operations Manager -> Clinical Trial Manager',
    'Certified Clinical Research Coordinator (CCRC / SOCRA / ACRP); thorough mastery of CDSCO clinical trial rules.',
    'M.Sc. in Clinical Research or Regulatory Sciences.',
    ['Good Clinical Practice (GCP)' => 5, 'Bioethics' => 4, 'Project management' => 4, 'Teamwork' => 4, 'Academic writing' => 3]
);

c($careers, 'Clinical Trial Data Associate', 'Healthcare and Clinical Diagnostics',
    'Manages, validates, and cleans clinical trial electronic databases to ensure regulatory readiness and data integrity.',
    "Program and test electronic data capture (EDC) systems (Medidata Rave, Oracle InForm, REDCap).\nIssue and resolve automated and manual clinical data queries with hospital site coordinators.\nPerform serious adverse event (SAE) data reconciliation between safety and clinical databases.\nParticipate in database lock activities prior to statistical analysis plan (SAP) execution.",
    'B.Sc. or M.Sc. in Biotechnology, Bioinformatics, Statistics, or Life Sciences.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Data & Remote',
    'Cleaned and validated 12,000 electronic case report form data points across 3 global oncology clinical trials.',
    'Data Associate -> Clinical Data Manager -> Lead Data Manager -> Director Clinical Data Management',
    'Certified Clinical Data Manager (CCDM); proficient in CDISC CDASH and SDTM clinical data standards.',
    'Postgraduate Diploma in Clinical Data Management and SAS Programming.',
    ['Data Analysis' => 4, 'Good Clinical Practice (GCP)' => 4, 'Project management' => 3, 'Problem solving' => 4, 'Teamwork' => 4]
);

c($careers, 'Point-of-Care Diagnostics Developer', 'Healthcare and Clinical Diagnostics',
    'Designs and prototypes rapid, equipment-free diagnostic tests (lateral flow assays, microfluidic chips) for decentralized healthcare.',
    "Conjugate colloidal gold nanoparticles and fluorescent latex beads to detection antibodies.\nDispense test and control lines on nitrocellulose membranes using automated micro-dispensers.\nOptimize buffer composition to prevent non-specific binding and ensure rapid capillary sample flow.\nTest real clinical whole blood and saliva specimens against laboratory gold-standard PCR/ELISA.",
    'M.Sc., M.Tech., or Ph.D. in Nanotechnology, Biotechnology, Biomedical Engineering, or Biophysics.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Product R&D',
    'Invented a 15-minute lateral flow immunoassay for multiplex detection of typhoid IgM/IgG with >94% sensitivity.',
    'Diagnostic Product Engineer -> Point-of-Care Scientist -> Head of Rapid Diagnostics R&D',
    'Holder of 2 patents in microfluidic paper diagnostic devices; experienced in ISO 13485 medical device standards.',
    'Ph.D. in Biomedical Engineering or Nanobiotechnology.',
    ['ELISA and Immunoassays' => 5, 'Nanobiotechnology' => 5, 'Quality Control (QC)' => 4, 'Problem solving' => 4, 'Presentation skills' => 4]
);

c($careers, 'HLA Typing Specialist - Transplants', 'Healthcare and Clinical Diagnostics',
    'Performs human leukocyte antigen (HLA) tissue typing and cross-matching for solid organ and hematopoietic stem cell transplantation.',
    "Extract high-molecular-weight DNA and perform high-resolution HLA typing (HLA-A, B, C, DRB1, DQB1) using NGS.\nExecute flow cytometric and complement-dependent cytotoxicity (CDC) cross-matching.\nScreen transplant candidates for anti-HLA donor-specific antibodies (DSA) using Luminex single-antigen bead assays.\nCompile histocompatibility reports determining virtual crossmatch compatibility for organ allocation.",
    'M.Sc. in Medical Biotechnology, Immunology, Genetics, or Biochemistry.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical Laboratory',
    'Conducted 350+ renal and bone marrow transplant histocompatibility evaluations under ASHI/EFI standards.',
    'HLA Technologist -> Histocompatibility Specialist -> Laboratory Director Immunogenetics',
    'Certified Histocompatibility Technologist (CHT / CHS); expert in HLA allele nomenclature.',
    'Ph.D. in Immunogenetics or Transplantation Immunology.',
    ['Immunology' => 5, 'Next-Generation Sequencing (NGS)' => 4, 'Flow Cytometry (FACS)' => 4, 'Good Clinical Laboratory Practice (GCLP)' => 4, 'Critical thinking' => 4]
);

c($careers, 'Infectious Disease Diagnostic Scientist', 'Healthcare and Clinical Diagnostics',
    'Develops and executes advanced molecular tests for tracking emerging viral pathogens, bacterial resistance genes, and outbreak investigations.',
    "Implement real-time syndromic respiratory and gastrointestinal multiplex molecular panels.\nSequence viral genomes (SARS-CoV-2, Influenza, Dengue) to identify lineages and immune escape mutations.\nScreen environmental and wastewater samples for pathogen surveillance using droplet digital PCR (ddPCR).\nAuthor epidemiological surveillance summaries for public health agencies.",
    'M.Sc. or Ph.D. in Medical Microbiology, Virology, Molecular Biology, or Biotechnology.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory & Surveillance',
    'Established state-wide wastewater genomic surveillance pipeline detecting emerging viral variants 2 weeks before clinical surges.',
    'Surveillance Officer -> Diagnostic Scientist -> Chief Public Health Molecular Epidemiologist',
    'Published 5 papers on antimicrobial resistance genetics; expert in BSL-2+ and BSL-3 laboratory containment.',
    'Ph.D. in Molecular Virology or Infectious Disease Epidemiology.',
    ['Quantitative PCR (RT-qPCR)' => 5, 'Microbiology' => 5, 'Next-Generation Sequencing (NGS)' => 4, 'Laboratory Safety and Biosafety' => 5, 'Good Clinical Laboratory Practice (GCLP)' => 4]
);

c($careers, 'Diagnostic Assay Validation Specialist', 'Healthcare and Clinical Diagnostics',
    'Specializes in the rigorous analytical and clinical verification of in vitro diagnostic (IVD) kits conforming to ISO 15189 and CDSCO/FDA regulations.',
    "Design validation protocols evaluating limit of detection (LoD), limit of quantitation (LoQ), analytical specificity, and interfering substances.\nExecute precision studies (repeatability and reproducibility) across multiple operators, instruments, and days.\nCalculate sensitivity, specificity, positive predictive value (PPV), and negative predictive value (NPV) using clinical cohorts.\nCompile comprehensive Technical Files and Design Dossiers for regulatory CE-IVD and IVDR clearance.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Biochemistry, or Biomedical Sciences.',
    'senior', 'Full-time', 'postgraduate', 'postgraduate', 'Quality & Regulatory',
    'Led analytical validation dossier for a companion diagnostic PCR test cleared by CDSCO and granted CE mark.',
    'Assay Specialist -> Validation Lead -> Head of Diagnostic Quality Assurance & Regulatory',
    'Auditor for ISO 13485 and ISO 15189 medical laboratory standards; author of 10+ IVD design history files.',
    'Executive Certification in In Vitro Diagnostic Regulatory Compliance.',
    ['Quality Control (QC)' => 5, 'Regulatory Affairs (CDSCO/FDA/EMA)' => 5, 'Quantitative PCR (RT-qPCR)' => 4, 'Data Analysis' => 5, 'Academic writing' => 4]
);

// -----------------------------------------------------------------------------
// 4. Agricultural, plant and crop biotechnology (12 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Plant Tissue Culture Specialist', 'Agricultural, Plant and Crop Biotechnology',
    'Propagates disease-free, high-value crop plants, horticultural species, and forestry clones using aseptic micropropagation techniques.',
    "Formulate Murashige & Skoog (MS) media and optimize plant growth regulator (auxin/cytokinin) ratios for organogenesis.\nInitiate sterile shoot cultures from meristem explants and achieve rapid shoot multiplication.\nInduce ex vitro and in vitro rooting; acclimatize plantlets in controlled greenhouse hardening facilities.\nScreen parent stock for viral pathogens using serological and RT-PCR tests prior to bulk propagation.",
    'B.Sc. or M.Sc. in Plant Biotechnology, Botany, Agricultural Biotechnology, or Horticulture.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory & Greenhouse',
    'Established commercial micropropagation protocol for elite banana (Grand Naine) producing 200,000 virus-tested plantlets annually.',
    'Tissue Culture Trainee -> Micropropagation Specialist -> Tissue Culture Lab Manager -> Production Head',
    'Managed commercial cleanroom production facility; expert in aseptic handling and physiological plant hardening.',
    'Ph.D. in Plant Cell and Tissue Culture or Crop Improvement.',
    ['Plant Tissue Culture' => 5, 'Aseptic Technique' => 5, 'Buffer and Reagent Preparation' => 4, 'Laboratory Safety and Biosafety' => 4, 'Teamwork' => 4]
);

c($careers, 'Crop Geneticist / Plant Breeder', 'Agricultural, Plant and Crop Biotechnology',
    'Develops superior crop varieties with improved yield, biotic pest resistance, drought tolerance, and enhanced nutritional composition.',
    "Plan hybridization schemes, backcrossing programs, and selection nurseries across multiple field locations.\nUtilize molecular markers to select desired quantitative trait loci (QTLs) at early seedling stages.\nEvaluate agronomic traits including grain yield, biomass, lodging resistance, and maturity duration.\nAnalyze genotype-by-environment (GxE) interactions and submit candidate lines to national variety testing trials.",
    'M.Sc. or Ph.D. in Genetics and Plant Breeding, Agricultural Biotechnology, or Plant Sciences.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Field & Laboratory',
    'Co-developed a high-yielding, drought-tolerant rice variety containing Sub1 and qDTY QTLs released for commercial cultivation.',
    'Assistant Breeder -> Crop Geneticist -> Lead Plant Breeder -> Director Crop Improvement',
    'Published 8 research papers in crop genetics; registered 2 elite germplasm accessions with NBPGR.',
    'Ph.D. in Plant Breeding and Genetics, Postdoctoral Fellowship in Genomic Selection.',
    ['Plant Biotechnology' => 5, 'Genetics' => 5, 'Molecular Markers (SSR / SNP)' => 5, 'Data Analysis' => 4, 'Research methods' => 4]
);

c($careers, 'Agricultural Biotechnologist', 'Agricultural, Plant and Crop Biotechnology',
    'Applies modern molecular tools to address agricultural productivity challenges, crop disease defense, and biofortification.',
    "Isolate and characterize novel plant defense genes and transcription factors.\nConstruct expression vectors for gene overexpression or RNA interference (RNAi) silencing in crop models.\nQuantify plant hormone levels and secondary metabolites using HPLC and GC-MS.\nConduct controlled growth-chamber biotic and abiotic stress response assays.",
    'M.Sc., M.Tech., or Ph.D. in Agricultural Biotechnology, Molecular Biology, or Botany.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Greenhouse',
    'Characterized a WRKY transcription factor in chickpea conferring enhanced fungal blight resistance upon overexpression.',
    'Research Associate -> Agricultural Biotechnologist -> Senior Agricultural Scientist',
    'Demonstrated expertise in plant RNA isolation, real-time expression profiling, and stress physiology assays.',
    'Ph.D. in Agricultural Biotechnology or Functional Genomics.',
    ['Plant Biotechnology' => 5, 'Molecular Biology' => 4, 'Quantitative PCR (RT-qPCR)' => 4, 'Gel Electrophoresis' => 4, 'Academic writing' => 4]
);

c($careers, 'Seed Quality Analyst', 'Agricultural, Plant and Crop Biotechnology',
    'Conducts physical, physiological, and genetic purity testing of commercial seed lots adhering to ISTA (International Seed Testing Association) rules.',
    "Perform standard germination tests, seed vigor tests, and tetrazolium viability assessments.\nVerify hybrid genetic purity using SSR marker capillary electrophoresis and grow-out tests (GOT).\nDetect seed-borne pathogens (fungi, bacteria, viruses) using blotter tests and diagnostic PCR.\nIssue official seed certification reports ensuring compliance with the Indian Seeds Act and OECD seed schemes.",
    'B.Sc. or M.Sc. in Agriculture, Seed Science and Technology, Botany, or Biotechnology.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory-oriented',
    'Analyzed over 1,500 commercial hybrid maize and cotton seed lots ensuring >99.0% genetic purity and >85% germination.',
    'Seed Analyst -> Senior Seed Quality Officer -> QA Manager - Seed Operations',
    'ISTA Certified Seed Sampler and Analyst; expertise in automated seed morphology imaging systems.',
    'Postgraduate Diploma in Seed Technology and Quality Management.',
    ['Molecular Markers (SSR / SNP)' => 4, 'Plant Biotechnology' => 4, 'Quality Control (QC)' => 5, 'Microscopy (Brightfield / Fluorescence)' => 4, 'Good Laboratory Practice (GLP)' => 4]
);

c($careers, 'Biofertilizer & Biopesticide Production Specialist', 'Agricultural, Plant and Crop Biotechnology',
    'Develops and manufactures microbial inoculants (Rhizobium, Azotobacter, Trichoderma, Bacillus thuringiensis) for sustainable agriculture.',
    "Formulate mass cultivation media and run pilot industrial fermenters for beneficial bacterial and fungal strains.\nEvaluate carrier materials (peat, talc, liquid formulations) for shelf-life stability and viable colony-forming units (CFU).\nConduct pot and field trials testing biofertilizer efficacy on crop nutrient uptake and soil microbial biomass.\nEnsure products meet national specifications (FCO - Fertilizer Control Order standards).",
    'B.Sc., M.Sc., or M.Tech. in Microbiology, Agricultural Microbiology, or Industrial Biotechnology.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory & Production',
    'Developed a liquid carrier formulation for Azospirillum brasilense with >14 months shelf-life at ambient tropical temperatures.',
    'Production Executive -> Biopesticide Specialist -> Plant Head - Biological Inputs',
    'Commercialized 3 biofertilizer formulations generating over 100 tonnes annual production capacity.',
    'Ph.D. in Agricultural Microbiology or Microbial Ecology.',
    ['Microbiology' => 5, 'Bioreactor Operation and Fermentation' => 4, 'Microbial Culture' => 5, 'Aseptic Technique' => 4, 'Quality Control (QC)' => 4]
);

c($careers, 'Molecular Marker Breeding Specialist', 'Agricultural, Plant and Crop Biotechnology',
    'Deploys marker-assisted selection (MAS), marker-assisted backcrossing (MABC), and genomic selection pipelines in corporate plant breeding.',
    "Design high-throughput SNP genotyping assays (KASP, TaqMan, Axiom arrays) for trait-linked loci.\nAutomate DNA extraction from thousands of seedling leaf samples using robotic liquid handlers.\nInterpret capillary electrophoresis electropherograms and call SNP genotypes for selection decisions.\nCalculate linkage disequilibrium, construct genetic linkage maps, and fine-map QTLs.",
    'M.Sc. or Ph.D. in Plant Breeding, Agricultural Biotechnology, Genetics, or Bioinformatics.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Computational',
    'Executed high-throughput MAS pipeline screening 25,000 tomato seedlings per season for tomato spotted wilt virus resistance.',
    'Marker Specialist -> MAS Breeding Lead -> Head of Trait Genetics',
    'Hands-on expertise in KASP assay design, FluoDia genotyping software, and QTL IciMapping.',
    'Ph.D. in Molecular Plant Breeding or Quantitative Crop Genetics.',
    ['Molecular Markers (SSR / SNP)' => 5, 'Genetics' => 5, 'PCR (Polymerase Chain Reaction)' => 4, 'Bioinformatics' => 4, 'Data Analysis' => 4]
);

c($careers, 'Plant Transformation Scientist', 'Agricultural, Plant and Crop Biotechnology',
    'Introduces foreign genes and targeted genome edits into recalcitrant crop plant tissues via Agrobacterium tumefaciens or biolistic gene guns.',
    "Construct binary vectors containing promoter-gene cassettes, selectable markers, and guide RNAs.\nPerform Agrobacterium-mediated transformation of embryonic calli, cotyledon explants, or immature embryos.\nSelect transgenic events on antibiotic/herbicide media and regenerate whole fertile plants in tissue culture.\nConfirm transgene integration, copy number, and absence of vector backbone via Southern blot and ddPCR.",
    'M.Sc., M.Tech., or Ph.D. in Plant Biotechnology, Molecular Biology, or Genetics.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Laboratory & Greenhouse',
    'Generated 45 independent transgenic soybean events with targeted CRISPR-Cas9 knockout of fatty acid desaturase 2 (FAD2).',
    'Transformation Scientist -> Senior Scientist Genetic Transformation -> Head of Plant Biotechnology',
    'Demonstrated mastery in tissue culture regeneration of recalcitrant monocot and dicot crops; authored 4 transformation protocols.',
    'Ph.D. in Plant Genetic Engineering or Functional Plant Genomics.',
    ['Plant Tissue Culture' => 5, 'Plant Biotechnology' => 5, 'Gene Cloning and Recombinant DNA' => 5, 'CRISPR and Gene Editing' => 4, 'Aseptic Technique' => 5]
);

c($careers, 'Soil Microbiologist', 'Agricultural, Plant and Crop Biotechnology',
    'Studies soil microbiome diversity, plant-microbe symbiotic relationships, and nutrient cycling (carbon, nitrogen, phosphorus) to improve soil health.',
    "Extract environmental DNA from diverse agricultural soil profiles and perform 16S/ITS amplicon sequencing.\nQuantify soil enzymatic activities (dehydrogenase, urease, phosphatase) using spectrophotometric assays.\nInvestigate mycorrhizal root colonization and biological nitrogen fixation nodules.\nAssess soil microbial carbon utilization patterns using Biolog EcoPlates.",
    'M.Sc. or Ph.D. in Soil Science, Microbiology, Agricultural Biotechnology, or Environmental Science.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Field & Laboratory',
    'Mapped rhizosphere microbiome shifts under regenerative agriculture practices across 50 farm locations.',
    'Soil Microbiologist -> Senior Environmental Soil Scientist -> Research Leader Soil Biological Health',
    'Published 6 papers on rhizosphere metabolomics; consultant to sustainable farming farmer-producer organizations.',
    'Ph.D. in Soil Microbial Ecology or Biogeochemistry.',
    ['Microbiology' => 5, 'Microbial Culture' => 4, 'Next-Generation Sequencing (NGS)' => 4, 'Data Analysis' => 4, 'Research methods' => 4]
);

c($careers, 'Agrigenomics Specialist', 'Agricultural, Plant and Crop Biotechnology',
    'Applies whole-genome sequencing, transcriptomics, and pangenomics to elucidate complex agricultural traits in crops, livestock, and aquaculture species.',
    "Process multi-omics datasets (WGS, RNA-seq, ChIP-seq) across agricultural cultivars and wild relatives.\nConstruct crop pangenomes identifying structural variants, presence/absence variants (PAVs), and domestication sweeps.\nDevelop genomic prediction models estimating breeding values (GEBVs) for complex multi-genic traits.\nCollaborate with international crop genomics consortia and deposit open-access datasets in NCBI/ENA.",
    'M.Sc., M.Tech., or Ph.D. in Bioinformatics, Agricultural Genomics, Computational Biology, or Genetics.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational & Research',
    'Built pangenome reference for 120 pigeonpea landraces uncovering structural variants associated with pod-borer resistance.',
    'Agrigenomics Analyst -> Senior Agrigenomics Scientist -> Genomics Lead Crop Innovations',
    'Proficient in Linux, Python/R, GATK variant calling pipelines, and PLINK/TASSEL genomic tools.',
    'Ph.D. in Computational Biology or Crop Evolutionary Genomics.',
    ['Bioinformatics' => 5, 'Next-Generation Sequencing (NGS)' => 5, 'Genetics' => 5, 'Python for Bioinformatics' => 4, 'Data Analysis' => 5]
);

c($careers, 'Plant Pathologist - Molecular Diagnostics', 'Agricultural, Plant and Crop Biotechnology',
    'Identifies and characterizes crop viral, fungal, bacterial, and nematode diseases using high-sensitivity molecular assays.',
    "Sample symptomatic crop plants from farmer fields and isolate causative phytopathogens.\nPerform isothermal amplification (LAMP) and real-time PCR for on-field early pathogen detection.\nCharacterize fungicide resistance mutations (e.g., in beta-tubulin and Cyp51 genes) by targeted sequencing.\nDevelop integrated disease management recommendations and advise regional agricultural extension officers.",
    'M.Sc. or Ph.D. in Plant Pathology, Agricultural Biotechnology, or Microbiology.',
    'entry', 'Full-time', 'postgraduate', 'postgraduate', 'Field & Laboratory',
    'Developed a field-deployable visual loop-mediated isothermal amplification (LAMP) kit for detecting Xanthomonas oryzae in rice.',
    'Junior Pathologist -> Molecular Plant Pathologist -> Senior Plant Protection Scientist',
    'Published diagnostic identification guide for regional quarantine phytopathogens; trained in plant clinic operations.',
    'Ph.D. in Molecular Plant Pathology or Phytovirology.',
    ['Plant Biotechnology' => 4, 'Microbiology' => 4, 'PCR (Polymerase Chain Reaction)' => 5, 'Gel Electrophoresis' => 4, 'Problem solving' => 4]
);

c($careers, 'Micropropagation Facility Manager', 'Agricultural, Plant and Crop Biotechnology',
    'Manages large-scale commercial plant tissue culture laboratories producing millions of disease-free plants annually.',
    "Plan production schedules, operator shift assignments, and laminar flow hood throughput targets.\nSupervise bulk media preparation autoclaving suites, media dispensing lines, and growth room environmental controls.\nEnforce strict phytosanitary hygiene protocols to minimize microbial contamination outbreaks.\nOversee hardening polyhouses, delivery logistics to commercial nurseries, and customer quality feedback.",
    'B.Sc., M.Sc., or B.Tech. in Biotechnology, Agriculture, Horticulture, or Botany with 5+ years operational experience.',
    'senior', 'Full-time', 'undergraduate', 'postgraduate', 'Operations & Commercial',
    'Managed 40-hood commercial micropropagation laboratory producing 5 million banana and sugarcane plantlets per year.',
    'Production Supervisor -> Micropropagation Facility Manager -> VP Plant Production Operations',
    'Reduced growth room contamination rates from 4.5% to under 0.8% through improved airflow and operator training.',
    'Executive Certification in Operations and Supply Chain Management.',
    ['Plant Tissue Culture' => 5, 'Aseptic Technique' => 5, 'Project management' => 5, 'Leadership' => 5, 'Quality Control (QC)' => 4]
);

c($careers, 'GM Crop Field Trial Coordinator', 'Agricultural, Plant and Crop Biotechnology',
    'Oversees contained and multi-location confined field trials (BRL-I and BRL-II) for genetically modified crops in compliance with RCGM/GEAC guidelines.',
    "Prepare Confined Field Trial (CFT) applications, standard operating procedures, and isolation distance compliance plans.\nSupervise planting, perimeter fencing, pollen barrier management, and controlled harvesting.\nMonitor and record event-specific phenology, non-target organism effects, and gene flow indices.\nOversee strict post-harvest residue destruction (incineration / deep burial) and 12-month post-trial volunteer monitoring.",
    'M.Sc. in Agricultural Biotechnology, Agronomy, Genetics, or Seed Science.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Field & Regulatory',
    'Coordinated 6 multi-location confined field trials for Bt cotton and drought-tolerant maize across 3 agricultural state zones.',
    'Field Trial Officer -> Field Trial Coordinator -> Regulatory Field Affairs Director',
    'Certified in Indian biosafety regulatory guidelines for transgenics (RCGM/GEAC compliance); audited by state regulatory bodies.',
    'Postgraduate Diploma in Regulatory Affairs and Biosafety Management.',
    ['Biosafety and Regulatory Compliance' => 5, 'Plant Biotechnology' => 4, 'Project management' => 4, 'Laboratory Safety and Biosafety' => 4, 'Leadership' => 4]
);

// -----------------------------------------------------------------------------
// 5. Industrial, food and environmental biotechnology (12 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Industrial Fermentation Specialist', 'Industrial, Food and Environmental Biotechnology',
    'Operates and optimizes large industrial fermenters producing organic acids, amino acids, antibiotics, and industrial bulk chemicals.',
    "Formulate cost-effective industrial fermentation media using cane molasses, corn steep liquor, and agro-wastes.\nControl dissolved oxygen, pH, anti-foam dosing, and nutrient feed rates in 10,000L to 100,000L fermenters.\nPerform offline optical density, residual sugar, and product titer assays to track batch kinetics.\nImplement clean-in-place (CIP) and steam-in-place (SIP) cycles maintaining sterile operational integrity.",
    'B.Sc., B.Tech., or M.Sc. in Industrial Biotechnology, Chemical Engineering, or Microbiology.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Plant & Manufacturing',
    'Optimized fed-batch citric acid fermentation in 20,000L fermenter achieving 142 g/L titer with Aspergillus niger.',
    'Fermentation Technician -> Fermentation Specialist -> Fermentation Plant Lead -> VP Manufacturing',
    'Hands-on expertise in industrial Allen-Bradley PLC control systems, sterilizer validation, and mass yield calculations.',
    'M.Tech. in Bioprocess Engineering or Industrial Biochemical Engineering.',
    ['Bioreactor Operation and Fermentation' => 5, 'Upstream Bioprocessing' => 5, 'Microbial Culture' => 4, 'Aseptic Technique' => 4, 'Problem solving' => 4]
);

c($careers, 'Environmental Biotechnologist - Bioremediation', 'Industrial, Food and Environmental Biotechnology',
    'Utilizes microorganisms and plant systems to degrade industrial pollutants, detoxify heavy metals, and remediate contaminated soil and aquatic habitats.',
    "Isolate and characterize hydrocarbon-degrading and pesticide-mineralizing bacterial consortia.\nDesign in situ and ex situ bioremediation systems including biopiles, bio-sparging, and constructed wetlands.\nMonitor degradation pathways and intermediate metabolites using GC-MS and HPLC.\nConduct phytoremediation field evaluations measuring heavy metal bioaccumulation factors in plant tissues.",
    'M.Sc. or Ph.D. in Environmental Biotechnology, Environmental Science, Microbiology, or Biotechnology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Field & Laboratory',
    'Designed a microbial consortium bio-augmentation system that degraded 88% of petroleum hydrocarbons in refinery soil within 60 days.',
    'Environmental Officer -> Environmental Biotechnologist -> Senior Bioremediation Consultant',
    'Published 4 research articles on chlorinated solvent biodegradation; technical consultant to Central Pollution Control Board (CPCB) projects.',
    'Ph.D. in Environmental Biotechnology or Microbial Bioremediation.',
    ['Microbiology' => 4, 'Microbial Culture' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Research methods' => 4, 'Problem solving' => 4]
);

c($careers, 'Waste-to-Energy Bioprocess Specialist', 'Industrial, Food and Environmental Biotechnology',
    'Designs and operates anaerobic digestion and microbial fuel cell systems converting organic municipal and industrial wastes into biomethane and electricity.',
    "Analyze waste feedstock characteristics including total solids (TS), volatile solids (VS), and chemical oxygen demand (COD).\nOperate continuous stirred tank anaerobic reactors (CSTR) optimizing volatile fatty acid (VFA) to alkalinity ratios.\nMonitor biogas composition (methane, CO2, H2S) using continuous infrared and electrochemical gas analyzers.\nUpgrade raw biogas to bio-CNG (compressed biomethane) via biological desulfurization and membrane scrubbing.",
    'B.Tech., M.Sc., or M.Tech. in Bioprocess Engineering, Biotechnology, Environmental Engineering, or Renewable Energy.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Plant & Engineering',
    'Commissioned a 50-tonne/day food-waste anaerobic digester producing 4,200 m3 of biomethane daily for captive power generation.',
    'Biogas Engineer -> Waste-to-Energy Specialist -> Plant Operations Head -> Renewable Energy Director',
    'Certified in biogas plant design and safety standards; expert in anaerobic microbial kinetics and digestate management.',
    'Ph.D. in Bioenergy and Waste Valorization Technologies.',
    ['Bioreactor Operation and Fermentation' => 4, 'Bioprocess Scale-Up' => 4, 'Microbiology' => 4, 'Problem solving' => 5, 'Project management' => 4]
);

c($careers, 'Food Biotechnologist', 'Industrial, Food and Environmental Biotechnology',
    'Applies biotechnology to enhance food nutrition, safety, flavor, and preservation through fermentation, enzyme technology, and rapid pathogen detection.',
    "Screen and develop starter cultures for fermented dairy, plant-based dairy alternatives, and fermented condiments.\nFormulate industrial food enzymes (proteases, lactases, pectinases) to optimize food texture and clarity.\nDetect food adulterants and foodborne pathogens (Salmonella, Listeria) using multiplex PCR and rapid immunoassays.\nEnsure compliance with FSSAI, US FDA, and Codex Alimentarius food safety guidelines.",
    'B.Tech., B.Sc., or M.Sc. in Food Biotechnology, Food Science and Technology, or Microbiology.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Laboratory & Plant',
    'Developed a plant-based fermented yogurt alternative using a selected probiotic Lactobacillus culture with high sensory acceptability.',
    'Food Technologist -> Food Biotechnologist -> R&D Manager - Food Innovations -> VP Food R&D',
    'Certified in HACCP / ISO 22000 Food Safety Management Systems; demonstrated expertise in food microbiology.',
    'M.Tech. or Ph.D. in Food Biotechnology or Dairy Microbiology.',
    ['Microbiology' => 4, 'Aseptic Technique' => 4, 'Quality Control (QC)' => 5, 'PCR (Polymerase Chain Reaction)' => 4, 'Teamwork' => 4]
);

c($careers, 'Brewing & Distillation Fermentation Scientist', 'Industrial, Food and Environmental Biotechnology',
    'Specializes in yeast physiology, mashing biochemistry, fermentation kinetics, and flavor compound profiling in commercial brewing and distilling.',
    "Manage and maintain pure yeast cultures (Saccharomyces cerevisiae, S. pastorianus) with high viability and vitality.\nMonitor sugar consumption, gravity attenuation, ethanol production, and diacetyl reduction throughout fermentation.\nAnalyze volatile aromatic esters and higher alcohols using headspace gas chromatography (HS-GC-MS).\nTroubleshoot wild yeast and bacterial contaminations using selective microbiological media and PCR screens.",
    'B.Sc., M.Sc., or B.Tech. in Biotechnology, Microbiology, Brewing Science, or Biochemical Engineering.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Plant & Laboratory',
    'Optimized fermentation thermal profile in 100,000L fermenter reducing maturation time by 3 days while meeting sensory targets.',
    'Brewer -> Brewing Microbiologist -> Fermentation Scientist -> Master Brewer / Head of Brewing Operations',
    'Diploma in Brewing (IBD London or Siebel Institute); expert in sensory tasting panels and yeast propagators.',
    'Master of Science in Brewing and Distilling.',
    ['Bioreactor Operation and Fermentation' => 4, 'Microbiology' => 5, 'Microbial Culture' => 4, 'Quality Control (QC)' => 4, 'Problem solving' => 4]
);

c($careers, 'Industrial Enzyme Application Specialist', 'Industrial, Food and Environmental Biotechnology',
    'Partners with textile, paper and pulp, detergent, and leather industries to integrate bio-enzymatic processes replacing harsh chemical treatments.',
    "Conduct laboratory trials evaluating cellulase, amylase, lipase, and protease performance under industrial wash conditions.\nMeasure fabric bio-polishing efficiency, deinking of recycled paper pulp, and enzymatic bating of animal hides.\nTroubleshoot enzyme inhibition caused by surfactants, chelators, and extreme pH/temperature industrial environments.\nProvide technical customer presentations and quantify cost and environmental carbon footprint savings.",
    'B.Sc., B.Tech., or M.Sc. in Biotechnology, Biochemistry, Applied Chemistry, or Chemical Engineering.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Technical & Client-Facing',
    'Implemented an enzymatic scouring and bleaching process for cotton textiles saving 40% water and 30% thermal energy.',
    'Technical Service Associate -> Application Specialist -> Lead Application Scientist -> Commercial Director',
    'Author of 12 industrial trial application notes; demonstrated expertise in enzyme assay kinetics under extreme matrix conditions.',
    'Postgraduate Diploma in Industrial Chemical Management or MBA in Technical Marketing.',
    ['Enzyme Kinetics' => 5, 'Biochemistry' => 4, 'Spectrophotometry and Colorimetric Assays' => 4, 'Presentation skills' => 5, 'Problem solving' => 4]
);

c($careers, 'Biofuel & Algae Bioenergy Scientist', 'Industrial, Food and Environmental Biotechnology',
    'Cultivates microalgae and engineered cyanobacteria in open raceway ponds and photobioreactors to produce biodiesel, bioethanol, and high-value pigments.',
    "Screen oleaginous algal strains for high lipid accumulation and rapid biomass doubling rates.\nOperate photobioreactors (PBR) optimizing light irradiance, CO2 sparging, and nitrogen starvation cues.\nExtract neutral lipids and perform transesterification to biodiesel analyzing fatty acid methyl esters (FAME) by GC-MS.\nCo-extract high-value astaxanthin, beta-carotene, and protein biomass for animal feed co-products.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Phycology, Renewable Energy, or Chemical Engineering.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Pilot Plant',
    'Achieved 42% neutral lipid content in Chlorella vulgaris grown in high-rate algal ponds using industrial flue gas CO2.',
    'Algal Biologist -> Bioenergy Scientist -> Senior Scientist Renewable Fuels -> Director Bioenergy',
    'Published 5 papers on algal bioprocessing; experienced in raceway pond hydraulics and biomass harvesting technologies.',
    'Ph.D. in Algal Biotechnology or Biomass Energy Conversion.',
    ['Bioreactor Operation and Fermentation' => 4, 'Microbiology' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Research methods' => 4, 'Problem solving' => 4]
);

c($careers, 'Effluent Treatment Plant (ETP) Biologist', 'Industrial, Food and Environmental Biotechnology',
    'Manages biological wastewater treatment processes (activated sludge, MBBR, UASB) for chemical, pharmaceutical, and textile manufacturing plants.',
    "Monitor mixed liquor suspended solids (MLSS), sludge volume index (SVI), and microscopic protozoan/filamentous bio-indicators.\nCalculate biological oxygen demand (BOD), chemical oxygen demand (COD), and total ammoniacal nitrogen removal efficiencies.\nTroubleshoot sludge bulking, toxic shock loading, and foaming using bio-augmentation and nutrient balancing.\nMaintain continuous online water quality sensors ensuring compliance with industrial discharge consent standards.",
    'B.Sc. or M.Sc. in Environmental Science, Environmental Biotechnology, Microbiology, or Chemistry.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Plant Operations',
    'Operated 2.5 MLD pharmaceutical effluent treatment plant achieving consistent BOD < 30 ppm and COD < 250 ppm.',
    'ETP Operator -> ETP Biologist -> Plant EHS Supervisor -> Environmental Compliance Manager',
    'Certified in Industrial Wastewater Management; expert in phase-contrast microscopy of activated sludge floc morphology.',
    'Postgraduate Diploma in Environmental Pollution Control Technology.',
    ['Microbiology' => 4, 'Microscopy (Brightfield / Fluorescence)' => 4, 'Laboratory Safety and Biosafety' => 4, 'Problem solving' => 4, 'Teamwork' => 4]
);

c($careers, 'Biomaterials Scientist', 'Industrial, Food and Environmental Biotechnology',
    'Synthesizes and characterizes biopolymer materials (bacterial cellulose, polyhydroxyalkanoates - PHA, chitosan, collagen) for medical and biodegradable applications.',
    "Ferment microbial producers of polyhydroxyalkanoates and optimize polymer recovery using green non-halogenated solvents.\nFabricate electrospun nanofiber scaffolds, hydrogels, and 3D porous matrices for tissue regeneration.\nCharacterize mechanical tensile strength, degradation rates, and thermal properties (DSC, TGA).\nEvaluate in vitro biocompatibility, cytotoxicity, and cellular adhesion using mammalian cell cultures.",
    'M.Sc., M.Tech., or Ph.D. in Biomaterials, Polymer Science, Biotechnology, or Biomedical Engineering.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory-oriented',
    'Developed a 100% home-compostable PHA-chitosan composite packaging film with high oxygen barrier properties.',
    'Biomaterials Chemist -> Biomaterials Scientist -> Senior Scientist Regenerative Materials',
    'Published 4 papers on biocompatible scaffolds; experienced in ASTM international biodegradable plastic standard testing.',
    'Ph.D. in Biomaterials and Tissue Engineering.',
    ['Biochemistry' => 4, 'Mammalian Cell Culture' => 4, 'Quality Control (QC)' => 4, 'Research methods' => 4, 'Problem solving' => 4]
);

c($careers, 'Probiotics & Nutraceuticals Development Scientist', 'Industrial, Food and Environmental Biotechnology',
    'Isolates, characterizes, and formulates beneficial probiotic strains and bioactive functional ingredients for dietary supplements and clinical nutrition.',
    "Screen bacterial strains for acid tolerance (gastric juice pH 2.0), bile salt resistance, and intestinal epithelial cell adhesion.\nPerform whole-genome sequencing to verify absence of acquired antibiotic resistance genes and virulence factors.\nDevelop microencapsulation technologies protecting sensitive live bacteria through spray drying and shelf-life storage.\nConduct randomized, double-blind human clinical trials documenting targeted health and immune benefits.",
    'M.Sc., M.Tech., or Ph.D. in Microbiology, Food Biotechnology, Nutrition, or Biochemistry.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Laboratory & Product R&D',
    'Formulated a 5-strain clinical probiotic supplement containing 50 billion CFU with validated room-temperature stability of 24 months.',
    'Probiotic Microbiologist -> Formulation Scientist -> R&D Head Nutraceuticals',
    'Authored 3 regulatory GRAS (Generally Recognized as Safe) dossiers; expert in probiotic viability flow cytometry testing.',
    'Ph.D. in Probiotic Microbiology or Functional Food Science.',
    ['Microbiology' => 5, 'Microbial Culture' => 5, 'Quality Control (QC)' => 4, 'Aseptic Technique' => 4, 'Academic writing' => 4]
);

c($careers, 'Bioprospecting Specialist', 'Industrial, Food and Environmental Biotechnology',
    'Explores biodiversity in extreme environments (hot springs, deep-sea trenches, mangrove swamps) to discover novel enzymes, drugs, and secondary metabolites.',
    "Conduct ethical, compliant biological field sampling expeditions adhering to the Convention on Biological Diversity (CBD) and Nagoya Protocol.\nConstruct metagenomic functional libraries directly from environmental samples without cultivation.\nScreen extracts for novel antibacterial, antifungal, antimalarial, and cytotoxic biological activities.\nPurify active metabolites using bioassay-guided fractionation, HPLC, and NMR structural elucidation.",
    'M.Sc. or Ph.D. in Biotechnology, Natural Product Chemistry, Microbiology, or Marine Biology.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Field & Laboratory',
    'Discovered a novel non-ribosomal peptide antifungal compound from a marine sponge-associated Streptomyces strain.',
    'Bioprospector -> Natural Products Chemist -> Head of Natural Discovery Platforms',
    'Published 7 papers in natural product discovery; expert in Access and Benefit Sharing (ABS) compliance under National Biodiversity Authority (NBA).',
    'Ph.D. in Marine Biotechnology or Pharmacognosy and Natural Products.',
    ['Microbiology' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Bioethics' => 4, 'Research methods' => 5, 'Critical thinking' => 5]
);

c($careers, 'Circular Bioeconomy Consultant', 'Industrial, Food and Environmental Biotechnology',
    'Advises corporate entities, agro-industries, and governmental agencies on valorizing biological waste streams into high-value bioproducts.',
    "Conduct life cycle assessments (LCA) and techno-economic analyses (TEA) of bio-based manufacturing routes versus fossil equivalents.\nIdentify market opportunities for converting agricultural crop residues into biochemicals, biochar, and bio-plastics.\nFormulate corporate circular sustainability strategies aligned with UN Sustainable Development Goals (SDGs).\nAuthor technical whitepapers and guide investment due diligence for venture capital and green climate funds.",
    'M.Sc., M.Tech., or MBA in Biotechnology, Environmental Economics, Sustainability Studies, or Biochemical Engineering.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Consulting & Strategy',
    'Delivered techno-economic feasibility study for a 100-TPD second-generation bioethanol refinery utilizing rice straw.',
    'Sustainability Analyst -> Bioeconomy Consultant -> Senior Manager Circular Bio-Strategy -> Partner Bio-Consulting',
    'Certified in ISO 14040 Life Cycle Assessment; published multiple industry reports on India bioeconomy projections.',
    'Executive Leadership in Climate and Circular Economy Innovation.',
    ['Data Analysis' => 5, 'Presentation skills' => 5, 'Project management' => 4, 'Critical thinking' => 5, 'Academic writing' => 4]
);

// -----------------------------------------------------------------------------
// 6. Bioinformatics, computational biology and genomics (12 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Junior Bioinformatician', 'Bioinformatics, Computational Biology and Genomics',
    'Performs basic sequence alignments, biological database querying, and standard data wrangling pipelines for wet-lab research groups.',
    "Perform BLAST searches, multiple sequence alignments (Clustal Omega, MUSCLE), and phylogenetic tree construction.\nParse and process FASTA, FASTQ, SAM/BAM, and VCF files using Bash and Python scripts.\nExtract biological annotations from NCBI GenBank, UniProt, Ensembl, and PDB databases.\nAssist researchers in visualizing genomic tracks on UCSC Genome Browser and IGV.",
    'B.Sc. or B.Tech. in Bioinformatics, Biotechnology, Computer Science, or Computational Biology.',
    'entry', 'Full-time', 'undergraduate', 'undergraduate', 'Computational & Data',
    'Automated batch retrieval and domain annotation of 2,500 kinase protein sequences using Python BioPython scripts.',
    'Bioinformatics Intern -> Junior Bioinformatician -> Bioinformatics Analyst -> Senior Bioinformatician',
    'Proficient in Linux command line, Git version control, Python, and bioperl/biopython libraries.',
    'M.Sc. or M.Tech. in Bioinformatics or Computational Genomics.',
    ['Bioinformatics' => 5, 'Python for Bioinformatics' => 4, 'Genetics' => 4, 'Data Analysis' => 4, 'Problem solving' => 4]
);

c($careers, 'NGS Data Analyst', 'Bioinformatics, Computational Biology and Genomics',
    'Analyzes high-throughput next-generation sequencing datasets including whole-genome (WGS), whole-exome (WES), and RNA-seq experiments.',
    "Execute quality control and adapter trimming on raw sequencing reads using FastQC and Trimmomatic.\nAlign reads to reference genomes using BWA-MEM and STAR aligners.\nCall single nucleotide variants (SNVs) and indels using GATK best-practices workflows.\nAnnotate genetic variants with VEP and ANNOVAR; perform differential gene expression analysis using DESeq2.",
    'M.Sc., M.Tech., or B.Tech. in Bioinformatics, Biotechnology, or Genomics.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Computational & Data',
    'Analyzed whole-exome sequencing data of 50 pediatric rare disease trios identifying causal pathogenic mutations in 18 cases.',
    'NGS Analyst -> Senior NGS Bioinformatician -> Lead Genomics Data Scientist',
    'Proficient in Snakemake / Nextflow workflow managers, Docker containers, and high-performance computing (HPC) clusters.',
    'Ph.D. in Computational Genomics or Human Medical Genetics.',
    ['Next-Generation Sequencing (NGS)' => 5, 'Bioinformatics' => 5, 'R Programming for Biostatistics' => 5, 'Python for Bioinformatics' => 4, 'Genetics' => 4]
);

c($careers, 'Computational Biologist', 'Bioinformatics, Computational Biology and Genomics',
    'Develops algorithmic and mathematical models to understand complex biological mechanisms, regulatory networks, and dynamic cellular systems.',
    "Formulate differential equation and stochastic models of intracellular biochemical reaction networks.\nReconstruct genome-scale metabolic models (GEMs) and perform flux balance analysis (FBA) using COBRA toolbox.\nInfer gene regulatory networks from multi-omics data integrating ChIP-seq, ATAC-seq, and RNA-seq.\nValidate in silico model predictions against published experimental datasets.",
    'M.Sc., M.Tech., or Ph.D. in Computational Biology, Systems Biology, Bioinformatics, or Biophysics.',
    'mid', 'Full-time', 'postgraduate', 'doctorate', 'Computational Research',
    'Developed a predictive computational model of central carbon metabolism in Synechocystis cyanobacteria guiding metabolic engineering.',
    'Computational Biologist -> Senior Computational Biologist -> Principal Scientist Systems Biology',
    'Published 5 first-author papers in quantitative biology; expert in MATLAB, Python SciPy, and network biology tools (Cytoscape).',
    'Ph.D. in Systems and Computational Biology.',
    ['Bioinformatics' => 5, 'Data Analysis' => 5, 'Python for Bioinformatics' => 5, 'Molecular Biology' => 4, 'Research methods' => 5]
);

c($careers, 'Structural Bioinformatician / Molecular Modeler', 'Bioinformatics, Computational Biology and Genomics',
    'Predicts, visualizes, and simulates three-dimensional macromolecular protein structures and drug-target interactions.',
    "Predict 3D protein structures using AlphaFold2, ColabFold, and homology modeling (MODELLER).\nExecute all-atom molecular dynamics (MD) simulations of protein-ligand and protein-membrane complexes using GROMACS.\nAnalyze conformational transitions, RMSD/RMSF trajectories, and free energy of binding via MM-PBSA/GBSA.\nPerform virtual high-throughput screening and molecular docking using AutoDock Vina and Schrödinger Glide.",
    'M.Sc., M.Tech., or Ph.D. in Bioinformatics, Biophysics, Molecular Modeling, or Computational Chemistry.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational & Modeling',
    'Simulated 500-ns molecular dynamics of an oncogenic KRAS mutant revealing an allosteric pocket for covalent small-molecule targeting.',
    'Structural Modeler -> Structural Bioinformatician -> Head of Computer-Aided Drug Design (CADD)',
    'Demonstrated mastery in PyMOL, ChimeraX, GROMACS, and state-of-the-art deep learning structural prediction tools.',
    'Ph.D. in Structural Biophysics or Computational Chemical Biology.',
    ['Molecular Modeling and Docking' => 5, 'Bioinformatics' => 5, 'Biochemistry' => 4, 'Python for Bioinformatics' => 4, 'Problem solving' => 5]
);

c($careers, 'Genomic Data Scientist', 'Bioinformatics, Computational Biology and Genomics',
    'Applies statistical learning and machine learning algorithms to large-scale biobanks, population cohorts, and clinical genomic databases.',
    "Analyze genome-wide association studies (GWAS) linking millions of genetic variants to complex human disease phenotypes.\nCalculate polygenic risk scores (PRS) and evaluate predictive performance in multi-ancestry populations.\nHandle massive biobank datasets (UK Biobank, All of Us, Indian Genome Project) using cloud infrastructure (AWS/GCP).\nTrain machine learning classifiers to predict disease progression and drug responsiveness from genomic features.",
    'M.Sc., M.Tech., or Ph.D. in Data Science, Bioinformatics, Biostatistics, or Genomics.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Data & AI',
    'Built a machine learning model integrating polygenic risk scores and clinical biomarkers predicting Type-2 diabetes risk with AUC 0.84.',
    'Genomic Data Scientist -> Senior Data Scientist -> Director Precision Medicine Informatics',
    'Expertise in Hail, PLINK, Python scikit-learn, PyTorch, and Apache Spark for big biological data.',
    'Ph.D. in Statistical Genomics or Biomedical Informatics.',
    ['Machine Learning in Life Sciences' => 5, 'R Programming for Biostatistics' => 5, 'Python for Bioinformatics' => 5, 'Genetics' => 5, 'Data Analysis' => 5]
);

c($careers, 'Metagenomics Analyst', 'Bioinformatics, Computational Biology and Genomics',
    'Profiles taxonomic composition, functional gene capacity, and metabolic potential of complex environmental and human gut microbiomes.',
    "Process shotgun metagenomic and 16S/18S/ITS amplicon sequencing data using QIIME2, Kraken2, and MetaPhlAn.\nPerform de novo metagenomic assembly and binning into metagenome-assembled genomes (MAGs) using metaSPAdes and MetaBAT2.\nAnnotate functional pathways (KEGG, MetaCyc, CAZy) to profile community carbohydrate and secondary metabolite metabolism.\nCorrelate microbiome alpha and beta diversity metrics with host health parameters and clinical metadata.",
    'M.Sc. or Ph.D. in Bioinformatics, Microbiology, Genomics, or Computational Biology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational Analysis',
    'Reconstructed 45 novel metagenome-assembled genomes (MAGs) from human gut microbiome uncovering uncharacterized short-chain fatty acid producers.',
    'Metagenomics Associate -> Metagenomics Analyst -> Senior Microbiome Scientist',
    'Published 4 papers on microbiome dynamics; expert in microbial ecological statistics and compositional data analysis in R.',
    'Ph.D. in Microbiome Genomics or Microbial Ecology.',
    ['Bioinformatics' => 5, 'Microbiology' => 4, 'R Programming for Biostatistics' => 5, 'Next-Generation Sequencing (NGS)' => 4, 'Python for Bioinformatics' => 4]
);

c($careers, 'Proteomics Data Analyst', 'Bioinformatics, Computational Biology and Genomics',
    'Analyzes high-resolution mass spectrometry data to identify and quantify thousands of proteins and their post-translational modifications.',
    "Process shotgun LC-MS/MS data using MaxQuant, Proteome Discoverer, and FragPipe.\nMatch experimental peptide fragmentation spectra against UniProt proteome databases evaluating false discovery rates (FDR).\nPerform label-free (LFQ) and isobaric tandem mass tag (TMT) quantitative differential expression analysis.\nMap post-translational modifications (phosphorylation, ubiquitination, acetylation) and perform functional pathway enrichment.",
    'M.Sc., M.Tech., or Ph.D. in Bioinformatics, Analytical Biochemistry, or Systems Biology.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational & Analytical',
    'Analyzed phospho-proteomic time-course across 60 breast cancer biopsies mapping dynamic activation of PI3K/Akt signaling.',
    'Proteomics Analyst -> Senior Proteomics Scientist -> Head of Proteomics Informatics',
    'Demonstrated expertise in Perseus, R Bioconductor proteomics packages, and DIA/DDA mass spectrometry data processing.',
    'Ph.D. in Clinical Proteomics or Bioanalytical Mass Spectrometry.',
    ['Mass Spectrometry' => 5, 'Biochemistry' => 4, 'Bioinformatics' => 5, 'R Programming for Biostatistics' => 5, 'Data Analysis' => 4]
);

c($careers, 'Single-Cell RNA-Seq Analyst', 'Bioinformatics, Computational Biology and Genomics',
    'Specializes in dissecting cellular heterogeneity and developmental trajectories at individual cell resolution using single-cell genomics.',
    "Process 10x Genomics scRNA-seq and snRNA-seq datasets using Cell Ranger and Seurat / Scanpy pipelines.\nPerform quality filtering, normalization, dimensional reduction (PCA, UMAP, t-SNE), and unsupervised clustering.\nAnnotate distinct cell types using canonical marker gene expression profiles and reference atlases.\nInfer developmental trajectories and pseudotime lineages using Monocle3 and scVelo (RNA velocity).",
    'M.Sc., M.Tech., or Ph.D. in Bioinformatics, Computational Biology, or Genomics.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational Research',
    'Mapped cellular atlas of 80,000 human peripheral blood mononuclear cells during acute viral infection identifying exhausted CD8+ T-cell subsets.',
    'Single-Cell Analyst -> Senior Single-Cell Bioinformatician -> Principal Single-Cell Scientist',
    'Proficient in Python AnnData, Seurat in R, Harmony batch correction, and spatial transcriptomics analysis (10x Visium).',
    'Ph.D. in Single-Cell Genomics or Developmental Systems Biology.',
    ['Next-Generation Sequencing (NGS)' => 5, 'Bioinformatics' => 5, 'Python for Bioinformatics' => 5, 'R Programming for Biostatistics' => 5, 'Cell Biology' => 4]
);

c($careers, 'Cheminformatics & Molecular Docking Scientist', 'Bioinformatics, Computational Biology and Genomics',
    'Applies chemical structure algorithms, quantitative structure-activity relationships (QSAR), and molecular docking for rational drug design.',
    "Manage and screen virtual chemical compound libraries containing millions of small molecules (ZINC, ChEMBL).\nCalculate molecular physicochemical properties, Lipinski Rule of 5 compliance, and ADMET profiles.\nBuild 2D and 3D QSAR models predicting pharmacological inhibitory potencies (IC50, Ki).\nPerform pharmacophore modeling and ligand-based drug design for target hit discovery.",
    'M.Sc., M.Tech., or Ph.D. in Cheminformatics, Medicinal Chemistry, Bioinformatics, or Pharmacy.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Computational & Chemistry',
    'Screened 1.2 million drug-like compounds identifying 5 novel nanomolar allosteric inhibitors of dengue virus protease.',
    'Cheminformatics Scientist -> Senior CADD Scientist -> Director Computational Chemistry',
    'Proficient in RDKit, Open Babel, KNIME analytical workflows, and automated Python cheminformatics scripting.',
    'Ph.D. in Computer-Aided Drug Discovery or Medicinal Chemistry.',
    ['Molecular Modeling and Docking' => 5, 'Bioinformatics' => 4, 'Python for Bioinformatics' => 4, 'Biochemistry' => 4, 'Problem solving' => 5]
);

c($careers, 'AI/ML Drug Discovery Scientist', 'Bioinformatics, Computational Biology and Genomics',
    'Develops deep generative models, graph neural networks (GNNs), and transformer models for de novo molecular design and target prediction.',
    "Train graph convolutional neural networks to predict molecular binding affinities and toxicity endpoints.\nImplement generative models (diffusion models, VAEs, molecular transformers) for de novo drug molecule generation.\nFine-tune large language models (LLMs) on protein sequences for thermostability and expression optimization.\nCollaborate with medicinal chemists to synthesize and biologically validate top AI-generated candidates.",
    'M.Tech., M.S., or Ph.D. in Computer Science, Computational Biology, Data Science, or Biophysics.',
    'senior', 'Full-time', 'postgraduate', 'doctorate', 'AI & R&D',
    'Trained a generative diffusion model producing 12 novel molecular scaffolds with experimentally confirmed sub-micromolar target inhibition.',
    'AI Scientist -> Senior AI Drug Discovery Scientist -> Head of AI-Driven Therapeutics',
    'Expertise in PyTorch, PyTorch Geometric, HuggingFace transformers, and cloud GPU cluster orchestration.',
    'Ph.D. in Machine Learning for Biomolecules or AI in Drug Design.',
    ['Machine Learning in Life Sciences' => 5, 'Python for Bioinformatics' => 5, 'Molecular Modeling and Docking' => 4, 'Data Analysis' => 5, 'Problem solving' => 5]
);

c($careers, 'Bioinformatics Pipeline Developer', 'Bioinformatics, Computational Biology and Genomics',
    'Builds robust, reproducible, and scalable bioinformatics workflows deployed on high-performance compute clusters and cloud environments.',
    "Architect automated, fault-tolerant analysis workflows using Nextflow and Snakemake.\nContainerize biological software packages and dependencies using Docker and Singularity.\nDeploy cloud infrastructure on AWS Batch, Google Cloud Life Sciences, or Kubernetes for petabyte-scale data processing.\nImplement continuous integration (CI/CD) automated testing for bio-computational software tools.",
    'B.Tech., M.Tech., or M.Sc. in Computer Science, Bioinformatics, or Software Engineering.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Software & Cloud',
    'Engineered production Nextflow RNA-seq pipeline processing 1,000 patient samples in 4 hours on AWS cloud with 100% reproducibility.',
    'Pipeline Engineer -> Senior Bioinformatics Engineer -> Cloud Genomics Architect',
    'Contributor to open-source nf-core pipelines; certified AWS Solutions Architect or GCP Cloud Engineer.',
    'Master of Science in Software Engineering or Cloud Computing.',
    ['Bioinformatics' => 5, 'Python for Bioinformatics' => 5, 'Next-Generation Sequencing (NGS)' => 4, 'Problem solving' => 5, 'Project management' => 4]
);

c($careers, 'Clinical Genomics Bioinformatician', 'Bioinformatics, Computational Biology and Genomics',
    'Specializes in the clinical interpretation and reporting of patient sequencing data in accredited hospital and diagnostic genomics laboratories.',
    "Curate single nucleotide variants, copy number variations (CNVs), and structural variants against ClinVar and OMIM.\nClassify variants according to ACMG/AMP clinical guidelines (Pathogenic, Likely Pathogenic, VUS, Likely Benign, Benign).\nFilter sequencing data to identify diagnostic matches for patient clinical phenotypes using Human Phenotype Ontology (HPO) terms.\nGenerate standardized, physician-facing clinical molecular diagnostic genomic reports.",
    'M.Sc. or Ph.D. in Bioinformatics, Human Genetics, Medical Genomics, or Molecular Medicine.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Clinical & Diagnostic',
    'Curated and clinically interpreted over 1,500 whole-exome sequencing cases conforming to ACMG guidelines.',
    'Clinical Bioinformatician -> Senior Variant Scientist -> Head of Clinical Genomics Informatics',
    'Board certified in molecular diagnostics informatics; published multiple clinical novel disease-gene association studies.',
    'Ph.D. in Medical Genomics or Clinical Molecular Pathology.',
    ['Genetics' => 5, 'Bioinformatics' => 5, 'Next-Generation Sequencing (NGS)' => 5, 'Good Clinical Laboratory Practice (GCLP)' => 4, 'Critical thinking' => 5]
);

// -----------------------------------------------------------------------------
// 7. Regulatory affairs, quality assurance and biotech business (10 Careers)
// -----------------------------------------------------------------------------
c($careers, 'Regulatory Affairs Executive - Biologics', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Prepares and submits regulatory dossiers for investigational new drugs (IND), biosimilars, and biologics to health authorities (CDSCO, US FDA, EMA).',
    "Compile Common Technical Document (CTD) and electronic CTD (eCTD) modules, focusing on Module 3 (CMC - Chemistry, Manufacturing, and Controls).\nCoordinate responses to deficiency letters and regulatory inspection queries from national drug authorities.\nTrack evolving regulatory guidelines for gene therapies, vaccines, and recombinant biosimilar products.\nParticipate in regulatory agency pre-submission consultation meetings and institutional biosafety committees.",
    'B.Pharm., M.Pharm., M.Sc., or M.Tech. in Biotechnology, Regulatory Affairs, or Life Sciences.',
    'entry', 'Full-time', 'undergraduate', 'postgraduate', 'Regulatory & Compliance',
    'Authored CMC Module 3 documentation for successful CDSCO marketing authorization approval of a therapeutic monoclonal antibody.',
    'Regulatory Executive -> Regulatory Manager -> Regulatory Affairs Director -> VP Global Regulatory Strategy',
    'Certified Regulatory Affairs Professional (RAC); thorough mastery of CDSCO Medical Device and Biologics rules.',
    'Postgraduate Diploma or Master in Pharmaceutical Regulatory Affairs.',
    ['Regulatory Affairs (CDSCO/FDA/EMA)' => 5, 'Good Manufacturing Practice (GMP)' => 4, 'Academic writing' => 5, 'Project management' => 4, 'Critical thinking' => 4]
);

c($careers, 'Quality Assurance (QA) Associate - Biotech', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Ensures that biomanufacturing and testing operations comply with national and international Current Good Manufacturing Practices (cGMP).',
    "Review and approve batch production records (BPR), cleaning logs, and standard operating procedures (SOPs).\nInvestigate manufacturing deviations, incidents, and out-of-specification (OOS) analytical results using 5-Why and Fishbone tools.\nCoordinate corrective and preventive action (CAPA) plans and track implementation timeliness.\nConduct routine internal cGMP compliance audits across cleanroom suites and warehouse facilities.",
    'B.Sc., B.Tech., or M.Sc. in Biotechnology, Chemistry, Pharmacy, or Microbiology.',
    'entry', 'Full-time', 'undergraduate', 'undergraduate', 'Quality & Compliance',
    'Reviewed and released over 300 commercial biopharmaceutical batch records maintaining zero audit observations.',
    'QA Officer -> QA Associate -> QA Assistant Manager -> QA Director',
    'Certified Quality Auditor (ASQ CQA); expert in electronic Document Management Systems (MasterControl / Veeva).',
    'M.Sc. in Quality Management or Pharmaceutical Quality Assurance.',
    ['Good Manufacturing Practice (GMP)' => 5, 'Quality Control (QC)' => 4, 'Laboratory Safety and Biosafety' => 4, 'Problem solving' => 4, 'Teamwork' => 4]
);

c($careers, 'QA Manager - GMP Biopharma', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Directs the entire site Quality Management System (QMS), hosting regulatory agency inspections and driving quality culture across operations.',
    "Host and lead inspections by US FDA, EMA, WHO, and CDSCO regulatory authorities with zero critical observations.\nOversee change control systems, annual product quality reviews (APQR), and supplier qualification programs.\nManage quality risk assessments following ICH Q9 quality risk management principles.\nDirect site quality staff and approve formal product market release or quarantine dispositions.",
    'M.Sc., M.Tech., or M.Pharm. with 8-12+ years of biopharmaceutical cGMP quality leadership.',
    'senior', 'Full-time', 'postgraduate', 'postgraduate', 'Leadership & Quality',
    'Successfully hosted 3 international regulatory inspections (US FDA PAI and EU GMP) resulting in zero 483 citations.',
    'QA Manager -> Head of Quality Operations -> VP Global Quality & Compliance',
    'Recognized authority on ICH Q7, Q8, Q9, and Q10 quality guidelines; active member of Parenteral Drug Association (PDA).',
    'Executive Leadership in Pharmaceutical Quality Systems.',
    ['Good Manufacturing Practice (GMP)' => 5, 'Quality Control (QC)' => 5, 'Leadership' => 5, 'Project management' => 5, 'Regulatory Affairs (CDSCO/FDA/EMA)' => 4]
);

c($careers, 'Clinical Research Associate (CRA)', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Monitors conduct of clinical trials across hospital sites on behalf of pharmaceutical sponsors or Contract Research Organizations (CROs).',
    "Conduct site qualification visits (SQV), site initiation visits (SIV), routine monitoring visits (IMV), and close-out visits (COV).\nPerform 100% source data verification (SDV) cross-checking medical records against electronic case report forms.\nVerify that investigational medicinal product (IMP) storage, accountability, and temperature logs are compliant.\nEnsure patient safety reporting for adverse events (AE) and serious adverse events (SAE) conforms to GCP timelines.",
    'B.Pharm., M.Pharm., M.Sc., or B.Tech. in Biotechnology, Nursing, or Life Sciences.',
    'entry', 'Full-time / Frequent Travel', 'undergraduate', 'postgraduate', 'Monitoring & Clinical',
    'Monitored 12 clinical trial sites across South India for a Phase II multicenter oncology study maintaining 100% GCP compliance.',
    'CRA I -> CRA II -> Senior CRA -> Clinical Project Manager -> Director Clinical Operations',
    'Certified Clinical Research Associate (CCRA / ACRP); thorough knowledge of New Drugs and Clinical Trials Rules 2019.',
    'Postgraduate Diploma in Clinical Research and Pharmacovigilance.',
    ['Good Clinical Practice (GCP)' => 5, 'Bioethics' => 4, 'Project management' => 4, 'Presentation skills' => 4, 'Teamwork' => 4]
);

c($careers, 'Biotech Patent Analyst / IP Specialist', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Evaluates patentability of biological inventions, conducts freedom-to-operate (FTO) searches, and drafts patent applications.',
    "Conduct prior art searches on patent and non-patent literature using databases (Derwent, Orbit, Google Patents, NCBI).\nPerform patent landscape analysis mapping competitor technology clusters in biologics and genetic engineering.\nDraft provisional and complete patent specifications describing biological molecules, vectors, and formulations.\nAnalyze claims for patentability under Section 3(d), 3(j), and 3(k) of the Indian Patent Act.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, Molecular Biology, Biochemistry, with Registered Patent Agent credential.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Intellectual Property',
    'Conducted comprehensive Freedom-To-Operate (FTO) clearance for a commercial CRISPR diagnostic platform across US, EP, and IN jurisdictions.',
    'Patent Analyst -> Senior IP Associate -> Patent Attorney / IP Manager -> Head of Global IP',
    'Registered Patent Agent with Indian Patent Office (CGPDTM); expertise in biological sequence patent searching (ST.26 format).',
    'LL.B. (Bachelor of Laws) or Postgraduate Diploma in Intellectual Property Rights.',
    ['Bioethics' => 4, 'Molecular Biology' => 4, 'Academic writing' => 5, 'Critical thinking' => 5, 'Problem solving' => 4]
);

c($careers, 'Biosafety Officer (BSO)', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Ensures laboratory and manufacturing facilities maintain biosafety standards, containment protocols, and biohazardous waste compliance.',
    "Serve as member-secretary on Institutional Biosafety Committees (IBSC) reviewing recombinant DNA research proposals.\nInspect BSL-1, BSL-2, and BSL-3 laboratory containment facilities for proper negative pressure and HEPA filtration.\nEnforce guidelines for genetically modified organisms (GMOs) and hazardous biological agents under DBT/MoEFCC rules.\nConduct biohazard spill drill training and maintain occupational health immunization programs for laboratory staff.",
    'M.Sc. or Ph.D. in Microbiology, Biotechnology, Biosafety, or Environmental Health & Safety.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Safety & Compliance',
    'Audited and certified 20 academic and industrial biotechnology laboratories to DBT Biosafety Regulations 2020.',
    'Safety Officer -> Biosafety Officer -> Institutional Biosafety Director',
    'Certified Biosafety Professional (CBSP / ABSA); expert in risk group classification and biological waste management.',
    'Postgraduate Diploma in Industrial Safety and Occupational Health.',
    ['Biosafety and Regulatory Compliance' => 5, 'Laboratory Safety and Biosafety' => 5, 'Good Laboratory Practice (GLP)' => 4, 'Presentation skills' => 4, 'Leadership' => 4]
);

c($careers, 'Medical Science Liaison (MSL) - Biotech', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Acts as the primary scientific peer bridge between biopharmaceutical companies and key opinion leader (KOL) physicians and oncologists.',
    "Engage with clinical specialists in non-promotional, peer-to-peer discussions on mechanism of action and clinical trial data.\nDeliver advanced scientific presentations at regional medical symposia and advisory boards.\nFacilitate investigator-initiated research studies (IIS) and assist clinical development teams with trial site identification.\nGather and synthesize clinical insights on unmet medical needs to inform corporate drug development strategy.",
    'M.Pharm., Pharm.D., M.D., or Ph.D. in Biotechnology, Pharmacology, or Molecular Medicine.',
    'senior', 'Full-time / Field Travel', 'postgraduate', 'doctorate', 'Scientific & Field',
    'Established scientific collaborative partnerships with 35 leading academic oncologists across premier regional cancer centers.',
    'Medical Science Liaison -> Senior MSL -> Medical Advisor -> Medical Director',
    'Published clinical researcher with outstanding scientific communication and peer-to-peer relationship skills.',
    'Executive Leadership in Medical Affairs and Clinical Strategy.',
    ['Presentation skills' => 5, 'Molecular Biology' => 4, 'Immunology' => 4, 'Good Clinical Practice (GCP)' => 4, 'Academic writing' => 4]
);

c($careers, 'Biopharma Sales & Application Specialist', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Provides technical and scientific support for complex biotech instruments (flow cytometers, NGS sequencers, bioreactors) to academic and pharma clients.',
    "Conduct instrument demonstrations, customer technical workshops, and hands-on assay training for researchers.\nTroubleshoot customer experimental failures and optimize protocol parameters for client laboratories.\nCollaborate with sales teams to configure specialized reagent packages and capital equipment proposals.\nRepresent product lines at national scientific conferences, trade expos, and vendor showcases.",
    'B.Sc., B.Tech., or M.Sc. in Biotechnology, Biochemistry, Microbiology, or Biomedical Engineering.',
    'entry', 'Full-time / Client Travel', 'undergraduate', 'postgraduate', 'Technical Sales & Support',
    'Delivered 40+ on-site customer training sessions on real-time PCR and FPLC systems achieving 98% customer satisfaction rating.',
    'Application Specialist -> Senior Application Specialist -> Technical Product Manager -> Business Unit Head',
    'Trained by original equipment manufacturers (OEM); expert in equipment software and customer troubleshooting.',
    'MBA in Marketing or Healthcare Commercial Management.',
    ['PCR (Polymerase Chain Reaction)' => 4, 'Flow Cytometry (FACS)' => 4, 'Chromatography (HPLC / FPLC)' => 4, 'Presentation skills' => 5, 'Teamwork' => 4]
);

c($careers, 'Biotech Product Manager', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Directs the commercial lifecycle, market positioning, and revenue growth of life science reagents, diagnostic kits, or laboratory consumables.',
    "Conduct voice-of-customer (VOC) market research identifying unmet needs in academic and clinical laboratory workflows.\nDefine product feature roadmaps, target customer personas, and competitive pricing strategies.\nCoordinate cross-functionally with R&D, manufacturing, quality, and marketing for successful new product launches.\nMonitor product revenue, gross margins, inventory forecasts, and market share trends.",
    'B.Tech. or M.Sc. in Biotechnology with MBA in Marketing or Product Management.',
    'mid', 'Full-time', 'undergraduate', 'postgraduate', 'Commercial Strategy',
    'Managed portfolio of 60 molecular biology reagents achieving INR 12 Cr annual revenue with 22% year-on-year growth.',
    'Associate Product Manager -> Product Manager -> Senior Product Manager -> Marketing Director',
    'Certified Product Manager (AIPMM); demonstrated track record in life sciences commercial go-to-market execution.',
    'Executive Program in General Management or Commercial Innovation.',
    ['Project management' => 5, 'Presentation skills' => 5, 'Data Analysis' => 4, 'Leadership' => 4, 'Molecular Biology' => 3]
);

c($careers, 'Biotech Venture Analyst / Tech Transfer Officer', 'Regulatory Affairs, Quality Assurance and Biotech Business',
    'Evaluates university spinouts, novel patent disclosures, and early-stage biotech startups for investment or commercial licensing.',
    "Perform scientific due diligence assessing technical feasibility, IP defensibility, and market size of biotech innovations.\nBuild discounted cash flow (DCF) and risk-adjusted net present value (rNPV) financial valuation models for drug pipelines.\nNegotiate intellectual property licensing agreements and royalty share terms between academic universities and industry partners.\nSupport biotech founders in pitch deck refinement, milestone tracking, and seed/Series A venture capital fundraising.",
    'M.Sc., M.Tech., or Ph.D. in Biotechnology, with MBA or CFA / Venture Capital experience.',
    'mid', 'Full-time', 'postgraduate', 'postgraduate', 'Investment & Strategy',
    'Evaluated 70+ life sciences startup applications and conducted technical due diligence for 4 closed seed investments totaling INR 8 Cr.',
    'Venture Analyst -> Associate -> Vice President Biotech Investments -> Venture Partner',
    'Published author on biotechnology valuation metrics; member of Association of University Technology Managers (AUTM).',
    'Executive Education in Venture Capital and Private Equity.',
    ['Critical thinking' => 5, 'Presentation skills' => 5, 'Project management' => 4, 'Data Analysis' => 5, 'Academic writing' => 4]
);

// -----------------------------------------------------------------------------
// Write to file database/biotech_data/careers_data.php
// -----------------------------------------------------------------------------
$code = "<?php\n/**\n * CareerAct — Comprehensive Biotechnology Careers Dataset\n * Exactly 84 Detailed Careers across 7 Specialisations\n */\n\ndeclare(strict_types=1);\n\nreturn " . var_export($careers, true) . ";\n";

file_put_contents(__DIR__ . '/careers_data.php', $code);
echo "Successfully generated careers_data.php with " . count($careers) . " careers!\n";

