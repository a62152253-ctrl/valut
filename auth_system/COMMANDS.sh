#!/bin/bash
# Quick Command Reference - Copy & Paste Ready

# ═══════════════════════════════════════════════════════════════════
# 🚀 STARTUP & INITIALIZATION
# ═══════════════════════════════════════════════════════════════════

# First time setup (run once)
chmod +x setup.sh test-integration.sh backup.sh
./setup.sh

# Start containers
docker-compose up -d

# Wait for startup (30 seconds)
sleep 30

# Run integration tests
./test-integration.sh

# ═══════════════════════════════════════════════════════════════════
# 📋 DAILY OPERATIONS
# ═══════════════════════════════════════════════════════════════════

# View application logs (live)
docker-compose logs -f app

# View all services logs
docker-compose logs -f

# Restart specific service
docker-compose restart app      # Restart PHP app
docker-compose restart mysql    # Restart database
docker-compose restart nginx    # Restart web server

# Stop all containers
docker-compose down

# Rebuild after code changes
docker-compose build --no-cache

# ═══════════════════════════════════════════════════════════════════
# 🗄️  DATABASE MANAGEMENT
# ═══════════════════════════════════════════════════════════════════

# Access MySQL CLI
docker exec -it vaultly-mysql mysql -u vaultly_user -p vaultly_db

# Backup database NOW (manual)
./backup.sh ./backups

# View all backups
ls -lah backups/

# Restore from backup
gzip -dc backups/vaultly-backup-20240101_020000.sql.gz | \
  docker exec -i vaultly-mysql mysql -u vaultly_user -p vaultly_db

# Check database size
docker exec vaultly-mysql du -sh /var/lib/mysql

# ═══════════════════════════════════════════════════════════════════
# 🔐 SECURITY OPERATIONS
# ═══════════════════════════════════════════════════════════════════

# View security events (last 50)
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT event_type, user_id, ip_address, created_at FROM security_logs ORDER BY created_at DESC LIMIT 50;"

# Count events by type
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT event_type, COUNT(*) as count FROM security_logs GROUP BY event_type ORDER BY count DESC;"

# Find failed login attempts (last 24 hours)
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT ip_address, COUNT(*) FROM security_logs WHERE event_type='login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY ip_address ORDER BY COUNT(*) DESC;"

# Check rate limit status
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT endpoint, identifier, attempts FROM rate_limits ORDER BY last_attempt DESC LIMIT 20;"

# ═══════════════════════════════════════════════════════════════════
# 👤 USER MANAGEMENT
# ═══════════════════════════════════════════════════════════════════

# List all users
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT id, username, email, email_verified, totp_enabled, created_at FROM users;"

# Reset user password (ADMIN ONLY)
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "UPDATE users SET password='\$2y\$10\$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' WHERE id=1;"

# Mark user email as verified
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "UPDATE users SET email_verified=1 WHERE email='user@example.com';"

# Delete user (CASCADE deletes related data)
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM users WHERE id=1;"

# ═══════════════════════════════════════════════════════════════════
# 📧 EMAIL & SMTP
# ═══════════════════════════════════════════════════════════════════

# Test SMTP connection (from PHP)
docker exec vaultly-app php -r "
include 'includes/EmailManager.php';
\$result = EmailManager::testConnection();
echo json_encode(\$result, JSON_PRETTY_PRINT);
"

# Check SMTP configuration
grep SMTP .env

# View email verification tokens
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT user_id, token, expires_at, verified_at FROM email_verification;"

# ═══════════════════════════════════════════════════════════════════
# 🔒 2FA & AUTHENTICATION
# ═══════════════════════════════════════════════════════════════════

# View 2FA enabled users
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT id, username, email, totp_enabled FROM users WHERE totp_enabled=1;"

# View backup codes status
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT user_id, COUNT(*) as total, SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) as unused FROM totp_backup_codes GROUP BY user_id;"

# View WebAuthn credentials
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT user_id, device_name, created_at, last_used FROM webauthn_credentials;"

# View remember tokens
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT user_id, ip_address, created_at, expires_at FROM remember_tokens WHERE expires_at > NOW();"

# ═══════════════════════════════════════════════════════════════════
# 📊 MONITORING & HEALTH
# ═══════════════════════════════════════════════════════════════════

# Check container health
docker-compose ps

# View detailed container info
docker inspect vaultly-app | grep -A 10 '"Health"'

# Check disk usage
docker exec vaultly-app df -h /app
docker exec vaultly-mysql du -sh /var/lib/mysql

# Monitor resource usage (live)
docker stats vaultly-app vaultly-mysql vaultly-web

# Check network connectivity
docker exec vaultly-app ping mysql -c 1

# ═══════════════════════════════════════════════════════════════════
# 🔧 CONFIGURATION & DEPLOYMENT
# ═══════════════════════════════════════════════════════════════════

# View current environment
docker exec vaultly-app env | grep -E 'DB_|SMTP_|APP_|FORCE_HTTPS'

# Update .env and reload
nano .env
docker-compose up -d --force-recreate

# Generate SSL certificate (self-signed)
openssl req -x509 -newkey rsa:4096 \
  -keyout ssl/key.pem -out ssl/cert.pem \
  -days 365 -nodes \
  -subj "/CN=vaultly.local"

# Get Let's Encrypt certificate
certbot certonly --standalone -d vaultly.yourdomain.com
cp /etc/letsencrypt/live/vaultly.yourdomain.com/fullchain.pem ssl/cert.pem
cp /etc/letsencrypt/live/vaultly.yourdomain.com/privkey.pem ssl/key.pem
docker-compose restart nginx

# ═══════════════════════════════════════════════════════════════════
# 🐛 DEBUGGING
# ═══════════════════════════════════════════════════════════════════

# Run PHP linter
docker exec vaultly-app php -l includes/EmailManager.php

# Check PHP syntax all includes
docker exec vaultly-app bash -c 'for f in includes/*.php; do php -l "$f"; done'

# View detailed PHP errors
docker exec vaultly-app tail -f /var/log/php-errors.log

# Check Nginx error log
docker exec vaultly-web tail -f /var/log/nginx/error.log

# Execute arbitrary PHP code
docker exec vaultly-app php -r "
include 'includes/db.php';
echo 'Connected to: ' . DB_HOST . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL;
"

# ═══════════════════════════════════════════════════════════════════
# 🧹 CLEANUP & MAINTENANCE
# ═══════════════════════════════════════════════════════════════════

# Remove old rate limit entries
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM rate_limits WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY);"

# Remove expired password reset tokens
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM password_reset WHERE expires_at < NOW();"

# Remove expired email verification tokens
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM email_verification WHERE expires_at < NOW();"

# Remove expired share links
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM vault_shares WHERE expires_at < NOW();"

# Remove expired remember tokens
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "DELETE FROM remember_tokens WHERE expires_at < NOW();"

# Clear old backups manually
find ./backups -name "*.sql.gz" -mtime +30 -delete

# Clean Docker system
docker system prune -a  # CAREFUL: Removes unused images

# ═══════════════════════════════════════════════════════════════════
# 🚢 DEPLOYMENT
# ═══════════════════════════════════════════════════════════════════

# Production deployment checklist
echo "Before deploying to production:"
echo "[ ] SMTP configured (.env)"
echo "[ ] SSL certificate installed (ssl/cert.pem, ssl/key.pem)"
echo "[ ] Database backed up"
echo "[ ] Security logs enabled"
echo "[ ] 2FA recommended for users"
echo "[ ] Rate limiting tested"
echo "[ ] Tests passed: ./test-integration.sh"

# Deploy to production
docker-compose -f docker-compose.yml up -d

# Verify deployment
./test-integration.sh && echo "✅ Deployment successful!"

# ═══════════════════════════════════════════════════════════════════
# 📞 QUICK HELP
# ═══════════════════════════════════════════════════════════════════

# Print this help
cat << 'EOF'
📚 Quick Reference Guide
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🚀 STARTUP:      ./setup.sh
🧪 TESTS:        ./test-integration.sh
📊 LOGS:         docker-compose logs -f app
🗄️  DATABASE:    docker exec -it vaultly-mysql mysql ...
🔐 SECURITY:     docker-compose logs -f | grep security
🔄 RESTART:      docker-compose restart
🛑 STOP:         docker-compose down
💾 BACKUP:       ./backup.sh ./backups

Need help? Check: PRODUCTION_SETUP.md
EOF

# Save all commands to file
cat > COMMANDS.sh << 'ENDCMDS'
# (Paste commands from above here)
ENDCMDS
