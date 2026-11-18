<?php
/**
 * CareerAct — Script to assemble all 84 Biotechnology Career Roles
 * Outputs database/biotech_data/careers_data.php
 */

declare(strict_types=1);

$careers = [];

// Helper function to add career
function addCareer(&$careers, $title, $subfield, $desc, $resp, $eduPath, $level, $empType, $minEdu, $prefEdu, $orient, $proj, $prog, $resEv, $highStudy, $skills) {
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
addCareer($careers,
    'Junior Research Fellow (JRF) - Biotechnology',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Senior Research Fellow (SRF) - Biotechnology',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Research Scientist - Molecular Biology',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Principal Investigator (PI) - Biotechnology',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Protein Biochemist',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Cell Line Development Scientist',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Enzymologist / Biocatalysis Scientist',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Synthetic Biologist',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Microbiologist - R&D',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Assay Development Scientist',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Laboratory Manager - Biotechnology Research',
    'Research and Development (R&D)',
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

addCareer($careers,
    'Postdoctoral Research Fellow',
    'Research and Development (R&D)',
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
addCareer($careers,
    'Upstream Bioprocess Scientist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Downstream Processing Scientist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Formulation Scientist - Biologics',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Monoclonal Antibody (mAb) Production Specialist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Vaccine Development Scientist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Cell and Gene Therapy Specialist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Analytical Development Scientist - Biopharma',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Fermentation Process Engineer',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Biomanufacturing Operations Associate',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Technology Transfer Scientist - Bioprocess',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'QC Microbiologist - Pharma & Biologics',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'QC Chemist - Biologics',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Lyophilization Specialist - Biopharma',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

addCareer($careers,
    'Biosimilar Development Scientist',
    'Biopharmaceutical and Pharmaceutical Biotechnology',
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

// Helper progress check
echo "Careers loaded so far: " . count($careers) . "\n";

