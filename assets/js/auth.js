/**
 * CareerAct — Authentication Interactions & Enhancements
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Password Visibility Toggle
    const toggleButtons = document.querySelectorAll('.password-toggle-btn');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);

            if (!input) return;

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            // Change icon
            const eyeIcon = this.querySelector('.icon-eye');
            const eyeOffIcon = this.querySelector('.icon-eye-off');

            if (eyeIcon && eyeOffIcon) {
                if (isPassword) {
                    eyeIcon.style.display = 'none';
                    eyeOffIcon.style.display = 'inline-block';
                    this.setAttribute('aria-label', 'Hide password');
                } else {
                    eyeIcon.style.display = 'inline-block';
                    eyeOffIcon.style.display = 'none';
                    this.setAttribute('aria-label', 'Show password');
                }
            }
        });
    });

    // 2. Client-side Real-time Password Confirmation Check
    const signupForm = document.getElementById('signupForm');
    if (signupForm) {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const confirmError = document.getElementById('confirm_password_error');

        function validatePasswordMatch() {
            if (!passwordInput || !confirmPasswordInput) return;

            if (confirmPasswordInput.value.length > 0) {
                if (passwordInput.value !== confirmPasswordInput.value) {
                    confirmPasswordInput.classList.add('is-invalid');
                    if (confirmError) {
                        confirmError.textContent = 'Passwords do not match.';
                        confirmError.style.display = 'block';
                    }
                } else {
                    confirmPasswordInput.classList.remove('is-invalid');
                    if (confirmError) {
                        confirmError.textContent = '';
                        confirmError.style.display = 'none';
                    }
                }
            }
        }

        if (confirmPasswordInput && passwordInput) {
            confirmPasswordInput.addEventListener('input', validatePasswordMatch);
            passwordInput.addEventListener('input', validatePasswordMatch);
        }
    }

    // 3. Auto-dismiss alerts on close click or after 8 seconds if desired
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        // Accessibility focus on alert if error
        if (alert.classList.contains('alert-error')) {
            alert.setAttribute('tabindex', '-1');
            alert.focus();
        }
    });
});
