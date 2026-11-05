/**
 * CareerAct — 2D AI Robot Interviewer Interactive Controller
 * Drives conversational assessment flow, robot animation states, typewriter text, and asynchronous submission.
 */

class RobotInterviewer {
    constructor(config) {
        this.assessmentId = config.assessmentId;
        this.totalQuestions = config.totalQuestions || 5;
        this.currentQuestionIndex = config.currentQuestionIndex || 1;
        this.csrfToken = config.csrfToken;
        this.skillName = config.skillName || 'Skill';
        this.studentName = config.studentName || 'Student';
        this.questions = config.questions || [];
        this.answers = {};

        this.robotEl = document.getElementById('aiRobot');
        this.speechTextEl = document.getElementById('robotSpeechText');
        this.statusTextEl = document.getElementById('robotStatusText');
        this.progressTrackEl = document.getElementById('chatProgressFill');
        this.progressLabelEl = document.getElementById('chatProgressLabel');
        this.submitBtn = document.getElementById('btnSubmitResponse');
        this.textarea = document.getElementById('conversationalAnswer');
        this.wordCounter = document.getElementById('wordCounterIndicator');
        this.thinkingOverlay = document.getElementById('thinkingOverlay');

        this.init();
    }

    init() {
        this.bindEvents();
        this.showQuestion(this.currentQuestionIndex, true);
    }

    bindEvents() {
        if (this.textarea) {
            this.textarea.addEventListener('input', () => this.handleTextareaInput());
            this.textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    this.submitCurrentAnswer();
                }
            });
        }

        if (this.submitBtn) {
            this.submitBtn.addEventListener('click', () => this.submitCurrentAnswer());
        }
    }

    setRobotState(state) {
        if (!this.robotEl) return;
        this.robotEl.className = 'ai-robot state-' + state;

        if (this.statusTextEl) {
            switch(state) {
                case 'thinking':
                    this.statusTextEl.textContent = 'Evaluating Response...';
                    break;
                case 'speaking':
                    this.statusTextEl.textContent = 'Interviewer Speaking';
                    break;
                case 'listening':
                    this.statusTextEl.textContent = 'Interviewer Listening';
                    break;
                case 'success':
                    this.statusTextEl.textContent = 'Assessment Completed';
                    break;
                default:
                    this.statusTextEl.textContent = 'AI Interviewer Active';
                    break;
            }
        }
    }

    speak(text, speed = 20, onComplete = null) {
        if (!this.speechTextEl) return;
        this.setRobotState('speaking');
        this.speechTextEl.textContent = '';
        
        let index = 0;
        const interval = setInterval(() => {
            if (index < text.length) {
                this.speechTextEl.textContent += text.charAt(index);
                index++;
            } else {
                clearInterval(interval);
                this.setRobotState('listening');
                if (onComplete) onComplete();
            }
        }, speed);
    }

    handleTextareaInput() {
        if (!this.textarea || !this.wordCounter) return;
        const text = this.textarea.value.trim();
        const words = text ? text.split(/\s+/).length : 0;
        
        if (words >= 15) {
            this.wordCounter.className = 'word-count-indicator valid';
            this.wordCounter.innerHTML = `<span style="color:#059669; font-weight:700;">✓</span> ${words} words entered (optimal for AI evaluation)`;
            if (this.submitBtn) this.submitBtn.disabled = false;
        } else if (words > 0) {
            this.wordCounter.className = 'word-count-indicator';
            this.wordCounter.innerHTML = `${words} words (minimum 15 recommended)`;
            if (this.submitBtn) this.submitBtn.disabled = false;
        } else {
            this.wordCounter.className = 'word-count-indicator';
            this.wordCounter.innerHTML = `0 words (minimum 15 recommended)`;
            if (this.submitBtn) this.submitBtn.disabled = true;
        }
    }

    showQuestion(qNum, isFirst = false) {
        this.currentQuestionIndex = qNum;

        // Update progress bar
        const pct = Math.round((qNum / this.totalQuestions) * 100);
        if (this.progressTrackEl) this.progressTrackEl.style.width = pct + '%';
        if (this.progressLabelEl) {
            this.progressLabelEl.innerHTML = `Question ${qNum} of ${this.totalQuestions} <span style="font-size:11.5px; color:#1769E8; font-weight:600;">(${pct}% Completed)</span>`;
        }

        // Hide other question cards, show current
        document.querySelectorAll('.question-card-unit').forEach(card => card.style.display = 'none');
        const activeCard = document.getElementById('qCard-' + qNum);
        if (activeCard) activeCard.style.display = 'block';

        // Clear textarea for new answer
        if (this.textarea) {
            this.textarea.value = this.answers[qNum] || '';
            this.textarea.disabled = false;
            this.handleTextareaInput();
            setTimeout(() => this.textarea.focus(), 300);
        }

        // Conversational robot greeting / question lead-in
        let intro = '';
        if (isFirst) {
            intro = `Hello ${this.studentName}! I am your CareerAct AI interviewer. I will evaluate your ${this.skillName} across 5 situational workplace challenges. Here is your first scenario!`;
        } else if (qNum === 2) {
            intro = `Great perspective on that first problem! Now, let's explore how you handle team coordination and conflicting priorities.`;
        } else if (qNum === 3) {
            intro = `Solid reasoning! Imagine an unexpected high-stakes emergency develops right before a deadline. What steps would you take?`;
        } else if (qNum === 4) {
            intro = `Interesting approach! Let's examine how you communicate with challenging stakeholders and defend your core strategy.`;
        } else {
            intro = `Final challenge! Consider this complex systemic trade-off. How would you diagnose the root cause and deliver a lasting resolution?`;
        }

        this.speak(intro, 18);
    }

    async submitCurrentAnswer() {
        const text = this.textarea ? this.textarea.value.trim() : '';
        if (!text) {
            alert('Please type your response to the scenario before continuing.');
            if (this.textarea) this.textarea.focus();
            return;
        }

        const qNum = this.currentQuestionIndex;
        const qCard = document.getElementById('qCard-' + qNum);
        const qId = qCard ? qCard.dataset.questionId : 0;
        this.answers[qNum] = text;

        // Enter thinking state
        this.setRobotState('thinking');
        if (this.speechTextEl) {
            this.speechTextEl.textContent = (qNum === this.totalQuestions)
                ? 'Synthesizing all responses with Google Gemini and calculating your vocational competency scores...'
                : 'Analyzing your practical approach, problem-solving sequence, and reasoning...';
        }

        if (this.textarea) this.textarea.disabled = true;
        if (this.submitBtn) this.submitBtn.disabled = true;
        if (this.thinkingOverlay) this.thinkingOverlay.classList.add('active');

        // Prepare POST data
        const formData = new FormData();
        formData.append('csrf_token', this.csrfToken);
        formData.append('ajax_submit_answer', '1');
        formData.append('assessment_id', this.assessmentId);
        formData.append('question_id', qId);
        formData.append('question_index', qNum);
        formData.append('answer_text', text);

        try {
            const response = await fetch('skills_assessment.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            if (!response.ok) {
                throw new Error('Server returned HTTP ' + response.status);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to record answer');
            }

            if (this.thinkingOverlay) this.thinkingOverlay.classList.remove('active');

            if (data.is_completed) {
                // Assessment Finished!
                this.setRobotState('success');
                this.speak(
                    `Congratulations ${this.studentName}! Your assessment has been evaluated across all 4 competency pillars. Revealing your complete diagnostic report!`,
                    18,
                    () => {
                        window.location.href = data.redirect_url || `skills_assessment.php?action=result&id=${this.assessmentId}&msg=eval_completed`;
                    }
                );
            } else {
                // Transition to next question
                this.recordInHistory(qNum, text);
                this.showQuestion(qNum + 1, false);
            }
        } catch (err) {
            console.warn('AJAX submit failed, falling back to standard form submission:', err);
            // Fallback: Populate form and submit normally
            const fallbackForm = document.getElementById('standardAssessmentForm');
            if (fallbackForm) {
                const ansInput = document.getElementById('hidden_ans_' + qNum);
                if (ansInput) ansInput.value = text;
                if (qNum === this.totalQuestions) {
                    fallbackForm.submit();
                } else {
                    this.showQuestion(qNum + 1, false);
                }
            } else {
                alert('Connection glitch: ' + err.message + '. Please click Submit again to retry.');
                this.setRobotState('listening');
                if (this.textarea) this.textarea.disabled = false;
                if (this.submitBtn) this.submitBtn.disabled = false;
                if (this.thinkingOverlay) this.thinkingOverlay.classList.remove('active');
            }
        }
    }

    recordInHistory(qNum, answerText) {
        const historyContainer = document.getElementById('chatHistoryList');
        if (!historyContainer) return;

        const qCard = document.getElementById('qCard-' + qNum);
        const scenarioText = qCard ? qCard.querySelector('.scenario-main-text').textContent.trim() : `Scenario ${qNum}`;

        const item = document.createElement('div');
        item.className = 'chat-history-item';
        item.innerHTML = `
            <div class="chat-history-item-q">Scenario ${qNum} • Submitted Approach:</div>
            <div class="chat-history-item-a">${this.escapeHtml(answerText)}</div>
        `;
        historyContainer.appendChild(item);

        const wrapper = document.getElementById('chatHistoryWrapper');
        if (wrapper) wrapper.style.display = 'block';
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

