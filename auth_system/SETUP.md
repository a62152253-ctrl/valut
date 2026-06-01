# Auth System - Setup Guide

## ✅ Status: RUNNING

**Web Server**: http://localhost:5555 ✓
**Database**: MySQL Connected ✓
**PHP**: Running ✓

---

## 🚀 Quick Start

Your authentication system is ready to use!

### Access URLs:
- **Home**: http://localhost:5555
- **Register**: http://localhost:5555/register.php
- **Login**: http://localhost:5555/login.php
- **Dashboard**: http://localhost:5555/dashboard.php
- **Forgot Password**: http://localhost:5555/forgot_password.php
- **Connection Test**: http://localhost:5555/test_connection.php

---

## 📋 Important: Keep MySQL Running

The system requires MySQL to stay running. If you get a "connection refused" error:

### ✅ MySQL is Already Running
MySQL service (PID 17188) is currently active on port 3306.

### ⚠️ If MySQL Stops:

**On Windows with XAMPP:**

```
Run: C:\XAMPP2\mysql_start.bat
```

Wait 10-15 seconds for MySQL to fully start, then refresh the page.

---

## 🧪 Test Registration Flow

### Step 1: Register
1. Go to: http://localhost:5555/register.php
2. Enter username: `testuser`
3. Enter email: `test@example.com`
4. Enter password: `password123`
5. Confirm password: `password123`
6. Click **Register**

### Step 2: Login
1. Go to: http://localhost:5555/login.php
2. Email: `test@example.com`
3. Password: `password123`
4. Click **Login**
5. You'll see the Dashboard

### Step 3: Dashboard
- View your account information
- Email displayed: `test@example.com`
- Click **Change Password** or **Logout**

### Step 4: Reset Password
1. Go to: http://localhost:5555/forgot_password.php
2. Enter email: `test@example.com`
3. Copy the reset link shown on screen
4. Paste into browser or open new tab with the link
5. Enter new password and confirm
6. You can now login with the new password

---

## 📁 Project Files

```
auth_system/
├── index.php                    # Redirect to login
├── register.php                 # Registration page
├── login.php                    # Login page  
├── forgot_password.php          # Reset request
├── reset_password.php           # Reset confirmation
├── dashboard.php                # User dashboard
├── test_connection.php          # DB connection test
├── includes/
│   └── db.php                  # Database config
├── css/
│   └── style.css               # Styling
└── js/
    └── validation.js            # Form validation
```

---

## 🗄️ Database Info

**Database**: `auth_system`
**Host**: localhost
**Port**: 3306
**User**: root
**Password**: (empty)

### Tables Created Automatically:

**users** table:
- id (auto-increment primary key)
- username (unique)
- email (unique)
- password (bcrypt hashed)
- created_at (timestamp)

**password_reset** table:
- id (auto-increment primary key)
- email (foreign key to users)
- token (unique reset token)
- expires_at (token expiration time - 1 hour)
- created_at (timestamp)

---

## 🔒 Security Features

✅ Bcrypt password hashing
✅ Token-based password recovery
✅ Session management
✅ Input sanitization
✅ Protected dashboard (login required)
✅ Automatic token expiration (1 hour)
✅ Form validation (client + server-side)

---

## 🎨 Customization

### Change Colors
Edit `css/style.css` - look for CSS variables:
```css
--primary-color: #667eea;
--danger-color: #ef5350;
--success-color: #66bb6a;
```

### Change Port
Edit PHP server command (currently 5555):
```bash
php -S localhost:YOUR_PORT
```

### Database Credentials
Edit `includes/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'auth_system');
```

---

## ⚙️ Requirements

✅ PHP 7.0+
✅ MySQL 5.7+ or MariaDB
✅ XAMPP (or any PHP+MySQL setup)
✅ Modern browser with JavaScript

---

## 🆘 Troubleshooting

### "Connection refused" on login/register?
- MySQL is not running
- Start it: `C:\XAMPP2\mysql_start.bat`
- Wait 15 seconds
- Refresh the page

### "Cannot find file" error?
- PHP server may have crashed
- Restart it:
  ```
  cd C:\XAMPP2\htdocs\ticfastr\auth_system
  php -S localhost:5555
  ```

### Database table errors?
- Tables should be created automatically
- Check `test_connection.php` shows green ✓
- Delete `auth_system` database and refresh to recreate tables

### Forgot password link not working?
- Copy the full URL shown on screen
- Make sure port 5555 is not blocked
- Clear browser cache if needed

---

## 📝 Test Accounts

After registration, you can test with:

**Account 1:**
- Email: test@example.com
- Password: password123

**Create more accounts** via the register page at:
http://localhost:5555/register.php

---

## ✨ What's Next?

Your system is ready for:
- User testing
- Integration with other applications
- Deployment (remember: use HTTPS in production)
- Email integration for password resets
- Rate limiting for security
- Admin dashboard

---

**All systems operational!** Let me know if you need anything else.
