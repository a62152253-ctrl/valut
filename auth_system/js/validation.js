// Validation functions
const validateUsername = (username) => {
    if (!username) return 'Username is required';
    if (username.length < 3) return 'Username must be at least 3 characters';
    if (!/^[a-zA-Z0-9_]+$/.test(username)) return 'Username can only contain letters, numbers, and underscores';
    return '';
};

const validateEmail = (email) => {
    if (!email) return 'Email is required';
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) return 'Invalid email format';
    return '';
};

const validatePassword = (password) => {
    if (!password) return 'Password is required';
    if (password.length < 6) return 'Password must be at least 6 characters';
    return '';
};

const validateConfirmPassword = (password, confirmPassword) => {
    if (!confirmPassword) return 'Please confirm your password';
    if (password !== confirmPassword) return 'Passwords do not match';
    return '';
};

// Get form and handle registration validation
const registerForm = document.getElementById('registerForm');
if (registerForm) {
    const usernameInput = document.getElementById('username');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    const showError = (input, errorElement, message) => {
        if (message) {
            input.classList.add('error');
            errorElement.textContent = message;
        } else {
            input.classList.remove('error');
            errorElement.textContent = '';
        }
    };
    
    // Real-time validation
    if (usernameInput) {
        usernameInput.addEventListener('blur', () => {
            const error = validateUsername(usernameInput.value.trim());
            showError(usernameInput, document.getElementById('usernameError'), error);
        });
    }
    
    if (emailInput) {
        emailInput.addEventListener('blur', () => {
            const error = validateEmail(emailInput.value.trim());
            showError(emailInput, document.getElementById('emailError'), error);
        });
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('blur', () => {
            const error = validatePassword(passwordInput.value);
            showError(passwordInput, document.getElementById('passwordError'), error);
        });
    }
    
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('blur', () => {
            const error = validateConfirmPassword(passwordInput.value, confirmPasswordInput.value);
            showError(confirmPasswordInput, document.getElementById('confirmError'), error);
        });
    }
    
    // Form submission validation
    registerForm.addEventListener('submit', (e) => {
        const usernameError = validateUsername(usernameInput.value.trim());
        const emailError = validateEmail(emailInput.value.trim());
        const passwordError = validatePassword(passwordInput.value);
        const confirmError = validateConfirmPassword(passwordInput.value, confirmPasswordInput.value);
        
        showError(usernameInput, document.getElementById('usernameError'), usernameError);
        showError(emailInput, document.getElementById('emailError'), emailError);
        showError(passwordInput, document.getElementById('passwordError'), passwordError);
        showError(confirmPasswordInput, document.getElementById('confirmError'), confirmError);
        
        if (usernameError || emailError || passwordError || confirmError) {
            e.preventDefault();
        }
    });
}

// Login form validation
const loginForm = document.getElementById('loginForm');
if (loginForm) {
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    
    const showError = (input, errorElement, message) => {
        if (message) {
            input.classList.add('error');
            errorElement.textContent = message;
        } else {
            input.classList.remove('error');
            errorElement.textContent = '';
        }
    };
    
    if (emailInput) {
        emailInput.addEventListener('blur', () => {
            const error = validateEmail(emailInput.value.trim());
            showError(emailInput, document.getElementById('emailError'), error);
        });
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('blur', () => {
            const error = validatePassword(passwordInput.value);
            showError(passwordInput, document.getElementById('passwordError'), error);
        });
    }
    
    loginForm.addEventListener('submit', (e) => {
        const emailError = validateEmail(emailInput.value.trim());
        const passwordError = validatePassword(passwordInput.value);
        
        showError(emailInput, document.getElementById('emailError'), emailError);
        showError(passwordInput, document.getElementById('passwordError'), passwordError);
        
        if (emailError || passwordError) {
            e.preventDefault();
        }
    });
}

// Forgot password form validation
const forgotForm = document.getElementById('forgotForm');
if (forgotForm) {
    const emailInput = document.getElementById('email');
    
    const showError = (input, errorElement, message) => {
        if (message) {
            input.classList.add('error');
            errorElement.textContent = message;
        } else {
            input.classList.remove('error');
            errorElement.textContent = '';
        }
    };
    
    if (emailInput) {
        emailInput.addEventListener('blur', () => {
            const error = validateEmail(emailInput.value.trim());
            showError(emailInput, document.getElementById('emailError'), error);
        });
    }
    
    forgotForm.addEventListener('submit', (e) => {
        const emailError = validateEmail(emailInput.value.trim());
        showError(emailInput, document.getElementById('emailError'), emailError);
        
        if (emailError) {
            e.preventDefault();
        }
    });
}

// Reset password form validation
const resetForm = document.getElementById('resetForm');
if (resetForm) {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    const showError = (input, errorElement, message) => {
        if (message) {
            input.classList.add('error');
            errorElement.textContent = message;
        } else {
            input.classList.remove('error');
            errorElement.textContent = '';
        }
    };
    
    if (passwordInput) {
        passwordInput.addEventListener('blur', () => {
            const error = validatePassword(passwordInput.value);
            showError(passwordInput, document.getElementById('passwordError'), error);
        });
    }
    
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('blur', () => {
            const error = validateConfirmPassword(passwordInput.value, confirmPasswordInput.value);
            showError(confirmPasswordInput, document.getElementById('confirmError'), error);
        });
    }
    
    resetForm.addEventListener('submit', (e) => {
        const passwordError = validatePassword(passwordInput.value);
        const confirmError = validateConfirmPassword(passwordInput.value, confirmPasswordInput.value);
        
        showError(passwordInput, document.getElementById('passwordError'), passwordError);
        showError(confirmPasswordInput, document.getElementById('confirmError'), confirmError);
        
        if (passwordError || confirmError) {
            e.preventDefault();
        }
    });
}
