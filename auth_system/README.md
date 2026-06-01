# Auth System - Login, Register & Forgot Password

Complete authentication system built with PHP, HTML, CSS, and JavaScript.

## 🚀 Quick Start

The PHP server is already running on **localhost:5555**

### Access the application:
- **Login/Register**: http://localhost:5555
- **Direct URLs**:
  - Register: http://localhost:5555/register.php
  - Login: http://localhost:5555/login.php
  - Dashboard: http://localhost:5555/dashboard.php (after login)
  - Forgot Password: http://localhost:5555/forgot_password.php

## 📋 Features

✅ **User Registration**
- Username validation (3+ characters)
- Email validation
- Password strength (6+ characters)
- Password confirmation
- Duplicate email/username prevention

✅ **User Login**
- Email-based authentication
- Secure password verification (bcrypt hashing)
- Session management
- Remember login

✅ **Forgot Password**
- Email-based password reset
- Secure reset tokens (valid for 1 hour)
- Token expiration handling

✅ **Dashboard**
- Protected page (requires login)
- User information display
- Change password link
- Logout functionality

✅ **Security Features**
- Password hashing with bcrypt
- Prepared statements for database queries
- Input sanitization
- CSRF protection through session
- Secure session management

## 📁 Project Structure

```
auth_system/
├── index.php                 # Redirect to login
├── register.php             # Registration page
├── login.php                # Login page
├── forgot_password.php      # Password recovery request
├── reset_password.php       # Password reset with token
├── dashboard.php            # User dashboard (protected)
├── includes/
│   └── db.php              # Database configuration & functions
├── css/
│   └── style.css           # All styling
└── js/
    └── validation.js        # Client-side validation
```

## 🗄️ Database Setup

The application uses MySQL with these tables automatically created:

**users table:**
- id (Primary Key)
- username (Unique)
- email (Unique)
- password (hashed)
- created_at (timestamp)

**password_reset table:**
- id (Primary Key)
- email (Foreign Key)
- token (Unique)
- expires_at (DateTime)
- created_at (timestamp)

## 🔧 Database Configuration

Edit `includes/db.php` to configure:

```php
define('DB_HOST', 'localhost');  // Default: localhost
define('DB_USER', 'root');       // Default: root
define('DB_PASS', '');           // Default: empty
define('DB_NAME', 'auth_system'); // Default: auth_system
```

## 🧪 Test the System

### 1. Register
- Go to: http://localhost:5555/register.php
- Fill in: username, email, password
- Submit

### 2. Login
- Go to: http://localhost:5555/login.php
- Use registered email and password
- You'll be redirected to dashboard

### 3. Forgot Password
- Go to: http://localhost:5555/forgot_password.php
- Enter your email
- Copy the reset link that appears
- Use the link to reset your password

### 4. Dashboard
- After login, view your account info
- Click "Change Password" to reset
- Click "Logout" to exit

## 🎨 Customization

### Change Colors
Edit `css/style.css` - CSS variables in `:root`:
```css
--primary-color: #667eea;
--danger-color: #ef5350;
--success-color: #66bb6a;
```

### Change Server Port
In your terminal, use:
```bash
cd auth_system
php -S localhost:YOUR_PORT
```

## ⚙️ Dependencies

- PHP 7.0+
- MySQL 5.7+ or MariaDB
- Modern web browser with JavaScript enabled

## 🔒 Security Notes

- Passwords are hashed with bcrypt
- All inputs are sanitized
- Reset tokens expire after 1 hour
- Session-based authentication
- For production: use HTTPS, email actual reset links, add rate limiting

## 📝 Notes

- Reset links are displayed on screen (in production, send via email)
- Database is created automatically on first load
- Sessions persist until browser close or manual logout
- All passwords are never stored in plain text

---

**Status**: ✅ Ready to use on localhost:5555
