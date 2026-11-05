<?php
/**
 * CareerAct — 2D AI Robot Character SVG Component
 * Vector graphic, pure CSS transforms, lightweight 60fps animations.
 *
 * @param string $initialState 'idle' | 'thinking' | 'speaking' | 'listening' | 'success'
 */
function renderRobotSvg(string $initialState = 'idle'): void {
?>
<div class="ai-robot state-<?= htmlspecialchars($initialState) ?>" id="aiRobot">
    <div class="robot-ambient-glow" id="robotAmbientGlow"></div>
    <svg viewBox="0 0 280 320" width="240" height="270" class="robot-svg" aria-label="CareerAct 2D AI Robot Interviewer" role="img">
        <defs>
            <!-- Helmet Metallic Gradient -->
            <linearGradient id="chassisGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#334155" />
                <stop offset="45%" stop-color="#1E293B" />
                <stop offset="100%" stop-color="#0F172A" />
            </linearGradient>

            <!-- Glossy Visor Screen Gradient -->
            <linearGradient id="visorGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#090D16" />
                <stop offset="70%" stop-color="#020617" />
                <stop offset="100%" stop-color="#0B132B" />
            </linearGradient>

            <!-- Core Reactor Radiant Glow -->
            <radialGradient id="coreGlowCyan" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#E0F2FE" />
                <stop offset="30%" stop-color="#38BDF8" />
                <stop offset="75%" stop-color="#6366F1" />
                <stop offset="100%" stop-color="#1E1B4B" />
            </radialGradient>

            <!-- Holographic Base Shadow -->
            <radialGradient id="holoShadow" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="rgba(56, 189, 248, 0.45)" />
                <stop offset="40%" stop-color="rgba(99, 102, 241, 0.25)" />
                <stop offset="100%" stop-color="transparent" />
            </radialGradient>

            <!-- Glowing Filters -->
            <filter id="eyeGlowFilter" x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="3" result="blur" />
                <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
        </defs>

        <!-- Holographic Floor Projection / Floating Shadow -->
        <ellipse cx="140" cy="302" rx="68" ry="12" fill="url(#holoShadow)" class="robot-shadow" />

        <!-- Floating Robot Group -->
        <g class="robot-floater" id="robotFloater">
            <!-- Left & Right Ear Antennae Sensors with LED Dots -->
            <rect x="48" y="74" width="14" height="42" rx="7" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" />
            <circle cx="55" cy="85" r="4.5" fill="#38BDF8" class="ear-led ear-led-left" filter="url(#eyeGlowFilter)" />
            <line x1="55" y1="94" x2="55" y2="106" stroke="#64748B" stroke-width="2" />

            <rect x="218" y="74" width="14" height="42" rx="7" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" />
            <circle cx="225" cy="85" r="4.5" fill="#38BDF8" class="ear-led ear-led-right" filter="url(#eyeGlowFilter)" />
            <line x1="225" y1="94" x2="225" y2="106" stroke="#64748B" stroke-width="2" />

            <!-- Antenna Top Fin / Beacon -->
            <path d="M 134 40 L 140 22 L 146 40 Z" fill="#1E293B" stroke="#475569" stroke-width="1.5" />
            <circle cx="140" cy="20" r="4" fill="#38BDF8" class="ear-led" filter="url(#eyeGlowFilter)" />

            <!-- Main Helmet Shell -->
            <rect x="58" y="38" width="164" height="122" rx="52" fill="url(#chassisGrad)" stroke="#6366F1" stroke-width="2" stroke-opacity="0.6" />
            
            <!-- Helmet Top Accent Seam -->
            <path d="M 94 48 Q 140 40 186 48" stroke="#475569" stroke-width="1.5" fill="none" opacity="0.6" />

            <!-- Digital Visor Glass (Face Panel) -->
            <rect x="72" y="58" width="136" height="82" rx="36" fill="url(#visorGrad)" stroke="#38BDF8" stroke-width="1.8" stroke-opacity="0.8" />
            
            <!-- Visor Upper Light Reflection Arc -->
            <path d="M 84 72 Q 140 62 196 72" stroke="rgba(255,255,255,0.22)" stroke-width="2.5" stroke-linecap="round" fill="none" />

            <!-- Visor Scanner Line (Active in Thinking State) -->
            <line x1="78" y1="98" x2="202" y2="98" class="radar-scanline" />

            <!-- Digital Expressive Eyes (Left & Right) -->
            <g class="eyes-container" id="eyesContainer">
                <!-- Left Eye -->
                <ellipse cx="108" cy="98" rx="14" ry="18" class="robot-eye eye-left" filter="url(#eyeGlowFilter)" />
                <circle cx="112" cy="94" r="4" fill="#FFFFFF" class="eye-pupil" />
                <circle cx="104" cy="102" r="2" fill="rgba(255,255,255,0.6)" class="eye-pupil" />

                <!-- Right Eye -->
                <ellipse cx="172" cy="98" rx="14" ry="18" class="robot-eye eye-right" filter="url(#eyeGlowFilter)" />
                <circle cx="176" cy="94" r="4" fill="#FFFFFF" class="eye-pupil" />
                <circle cx="168" cy="102" r="2" fill="rgba(255,255,255,0.6)" class="eye-pupil" />
            </g>

            <!-- Digital Audio Equalizer Mouth (Active in Speaking State) -->
            <g class="robot-mouth" id="robotMouth" transform="translate(0, 0)">
                <rect x="122" y="125" width="4" height="8" rx="2" class="mouth-bar mb-1" />
                <rect x="131" y="122" width="4" height="14" rx="2" class="mouth-bar mb-2" />
                <rect x="140" y="120" width="4" height="18" rx="2" class="mouth-bar mb-3" />
                <rect x="149" y="122" width="4" height="14" rx="2" class="mouth-bar mb-4" />
                <rect x="158" y="125" width="4" height="8" rx="2" class="mouth-bar mb-5" />
            </g>

            <!-- Floating Neck Connector Ring -->
            <rect x="115" y="160" width="50" height="12" rx="6" fill="#1E293B" stroke="#6366F1" stroke-width="1.5" />
            <circle cx="140" cy="166" r="3" fill="#38BDF8" opacity="0.8" />

            <!-- Floating Shoulder Armor Pads -->
            <g class="shoulders-group">
                <path d="M 64 182 Q 78 176 84 195 Q 70 206 60 196 Z" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" class="shoulder-pad left" />
                <circle cx="72" cy="190" r="2" fill="#38BDF8" />
                
                <path d="M 216 182 Q 202 176 196 195 Q 210 206 220 196 Z" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" class="shoulder-pad right" />
                <circle cx="208" cy="190" r="2" fill="#38BDF8" />
            </g>

            <!-- Body Torso / Aerodynamic Chassis -->
            <path d="M 84 172 Q 140 164 196 172 L 186 254 Q 140 266 94 254 Z" fill="url(#chassisGrad)" stroke="#6366F1" stroke-width="2" stroke-opacity="0.7" />
            
            <!-- Torso Accent Seams -->
            <path d="M 106 186 L 120 244" stroke="#475569" stroke-width="1.2" opacity="0.5" />
            <path d="M 174 186 L 160 244" stroke="#475569" stroke-width="1.2" opacity="0.5" />

            <!-- Central Chest Core Reactor -->
            <g class="core-reactor-group">
                <circle cx="140" cy="214" r="24" fill="#0F172A" stroke="#6366F1" stroke-width="2" />
                <circle cx="140" cy="214" r="17" fill="none" stroke="#38BDF8" stroke-width="1.5" stroke-dasharray="4 2" class="core-mid-ring" />
                <circle cx="140" cy="214" r="11" fill="url(#coreGlowCyan)" class="core-inner" filter="url(#eyeGlowFilter)" />
                <circle cx="140" cy="214" r="4" fill="#FFFFFF" />
            </g>

            <!-- Floating Robotic Hands with Magnetic Joints -->
            <g class="hands-group">
                <ellipse cx="56" cy="236" rx="9" ry="14" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" class="robot-hand hand-left" />
                <circle cx="56" cy="236" r="3" fill="#38BDF8" opacity="0.7" />

                <ellipse cx="224" cy="236" rx="9" ry="14" fill="url(#chassisGrad)" stroke="#475569" stroke-width="1.5" class="robot-hand hand-right" />
                <circle cx="224" cy="236" r="3" fill="#38BDF8" opacity="0.7" />
            </g>
        </g>
    </svg>
</div>
<?php
}

