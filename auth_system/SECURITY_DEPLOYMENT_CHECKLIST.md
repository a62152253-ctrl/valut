# VAULTLY PRODUCTION DEPLOYMENT SECURITY CHECKLIST
# ═══════════════════════════════════════════════════════════════════════════════

## PRE-DEPLOYMENT

### Application Security
- [ ] Review all custom code for SQL injection vulnerabilities
- [ ] Check for hardcoded secrets/credentials (use .env files only)
- [ ] Verify all user inputs are validated and sanitized
- [ ] Test CSRF token generation and validation
- [ ] Verify session timeout works correctly
- [ ] Check password hashing uses bcrypt with proper cost factor
- [ ] Test 2FA implementation (TOTP generation and verification)
- [ ] Verify email verification process works

### Encryption & Keys
- [ ] Generate new encryption keys (never reuse development keys)
- [ ] Verify vault key derivation uses strong parameters
- [ ] Test encrypted data can be decrypted correctly
- [ ] Backup encryption keys in secure location
- [ ] Generate new database credentials
- [ ] Verify SSL/TLS certificates are valid and trusted

### Database
- [ ] Backup production database before deploying
- [ ] Run all database migrations
- [ ] Verify version column exists (for optimistic locking)
- [ ] Create database indexes for performance
- [ ] Set up automated backups
- [ ] Test backup restore process
- [ ] Verify database user permissions are minimal
- [ ] Enable binary logging for replication/recovery

### Files & Configuration
- [ ] Create .env file with production values
- [ ] Set APP_DEBUG=false
- [ ] Set ENVIRONMENT=production
- [ ] Verify .htaccess is in place and correct
- [ ] Check Dockerfile/docker-compose.yml security settings
- [ ] Verify all logs directories exist and are writable
- [ ] Set correct file permissions (644 for files, 755 for directories)

---

## INFRASTRUCTURE SETUP

### Server & Network
- [ ] Configure firewall to allow only 80, 443, 22 (SSH)
- [ ] Run ufw-rules.sh for UFW configuration
- [ ] Verify MySQL only accessible from localhost/Docker network
- [ ] Configure DDoS protection (CloudFlare, AWS Shield)
- [ ] Set up WAF rules
- [ ] Enable VPC/Security Groups (cloud providers)
- [ ] Configure reverse proxy (nginx/Apache)

### SSL/TLS Certificates
- [ ] Run ssl-setup.sh to obtain Let's Encrypt certificate
- [ ] Verify HTTPS is enforced (HTTP redirects to HTTPS)
- [ ] Check SSL Labs score is A or higher
- [ ] Verify certificate auto-renewal is configured
- [ ] Set up certificate renewal monitoring

### Monitoring & Logging
- [ ] Verify error logs are writable (/var/log/vaultly/)
- [ ] Set up centralized logging (ELK, Splunk, CloudWatch)
- [ ] Configure health check monitoring (health endpoint)
- [ ] Set up alerts for:
  - [ ] Failed login attempts
  - [ ] CSRF violations
  - [ ] Database errors
  - [ ] High 5xx error rates
  - [ ] Disk space low
  - [ ] Certificate expiry warnings

### Backups & Disaster Recovery
- [ ] Set up automated backups with backup-script.sh
- [ ] Verify backups are encrypted with GPG
- [ ] Test backup restoration process
- [ ] Document RTO/RPO requirements
- [ ] Set up offsite backup storage (S3, cloud)
- [ ] Verify backup retention policy

---

## DEPLOYMENT

### Code Deployment
- [ ] Deploy all updated files from git
- [ ] Run database migrations
- [ ] Verify all dependencies are installed (composer install)
- [ ] Clear cache if applicable
- [ ] Run smoke tests on new deployment

### Docker (if applicable)
- [ ] Build Docker image with security tags
- [ ] Run container security scan (Trivy, Clair)
- [ ] Verify health checks are working
- [ ] Check resource limits are set
- [ ] Verify volumes are mounted correctly
- [ ] Confirm environment variables are set

### PHP-FPM Configuration
- [ ] Set pm.start_servers = 10
- [ ] Set pm.min_spare_servers = 5
- [ ] Set pm.max_spare_servers = 20
- [ ] Configure process timeout appropriately
- [ ] Enable slowlog for debugging
- [ ] Verify disable_functions is set

---

## POST-DEPLOYMENT VERIFICATION

### Security Headers
- [ ] Verify all security headers present (curl -i https://yourdomain.com)
- [ ] Check X-Frame-Options: DENY
- [ ] Check X-Content-Type-Options: nosniff
- [ ] Check Content-Security-Policy present
- [ ] Check Strict-Transport-Security present
- [ ] Check Permissions-Policy present

### Functionality Tests
- [ ] Register new user account
- [ ] Verify email confirmation works
- [ ] Login with credentials
- [ ] Create vault entry
- [ ] Edit vault entry
- [ ] Delete vault entry
- [ ] Test logout
- [ ] Verify session timeout works
- [ ] Test "Remember me" functionality

### Security Tests
- [ ] Health check returns HTTP 200 (https://yourdomain.com/api/health.php)
- [ ] Test concurrent edits for race condition handling (should return 409)
- [ ] Attempt SQL injection on login (should be blocked)
- [ ] Test CSRF by submitting form from different domain (should be rejected)
- [ ] Verify session cookies have HttpOnly flag
- [ ] Verify session cookies have Secure flag
- [ ] Attempt to access .env file (should be 403/404)
- [ ] Test rate limiting on login (5 attempts per 15 min)
- [ ] Verify passwords are not logged anywhere
- [ ] Test 2FA TOTP generation and verification
- [ ] Verify backup encryption works

### Performance Tests
- [ ] Measure page load time (should be <2s)
- [ ] Check memory usage during concurrent users
- [ ] Monitor database query performance
- [ ] Verify caching headers are correct
- [ ] Test under load (load testing tool)

### Database Tests
- [ ] Verify version column exists and has correct values
- [ ] Test optimistic locking (update same entry from 2 connections)
- [ ] Verify vault_history is being populated
- [ ] Check security_logs has entries for important events
- [ ] Test backup/restore process

### Monitoring Tests
- [ ] Verify health endpoint is accessible
- [ ] Check logs are being written
- [ ] Verify alerts trigger correctly
- [ ] Test backup execution and encryption
- [ ] Verify certificate will auto-renew

---

## ONGOING MAINTENANCE

### Daily
- [ ] Monitor error logs for issues
- [ ] Check disk space
- [ ] Review security logs for suspicious activity
- [ ] Monitor backup completion

### Weekly
- [ ] Review performance metrics
- [ ] Check certificate expiry (should warn 30 days before)
- [ ] Test backup restoration

### Monthly
- [ ] Review security updates for dependencies
- [ ] Audit user accounts and permissions
- [ ] Check for failed logins/CSRF attempts
- [ ] Review database size and optimize if needed
- [ ] Test disaster recovery procedures

### Quarterly
- [ ] Security audit of codebase
- [ ] Penetration testing
- [ ] Review and update security policies
- [ ] Check compliance with regulations (GDPR, etc.)

---

## EMERGENCY PROCEDURES

### Compromised Credentials
1. [ ] Immediately rotate database password
2. [ ] Reset all user sessions
3. [ ] Force password reset for all users
4. [ ] Review security logs for unauthorized access
5. [ ] Check for data exfiltration

### Data Breach
1. [ ] Stop affected services immediately
2. [ ] Isolate compromised systems
3. [ ] Preserve logs and evidence
4. [ ] Notify affected users
5. [ ] Begin incident investigation

### SSL Certificate Expiry
1. [ ] Manually renew: sudo certbot renew --force-renewal
2. [ ] Verify certificate: sudo certbot certificates
3. [ ] Test HTTPS still works
4. [ ] Restart Apache/nginx

### Database Corruption
1. [ ] Stop application immediately
2. [ ] Restore from latest backup
3. [ ] Verify data integrity
4. [ ] Resume service

---

## SIGN-OFF

Deployment Date: _______________
Deployed By: _______________
Reviewed By: _______________
All checks passed: [ ] YES [ ] NO
Issues/Notes: _______________________________________________

