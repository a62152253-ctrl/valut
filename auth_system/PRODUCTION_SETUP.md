# 🔐 Vaultly Production Deployment Guide

## CRITICAL SETUP (Before Going Live)

### 1. Email Configuration (SMTP)

Edit `.env`:
```bash
SMTP_HOST=smtp.gmail.com              # Gmail
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-specific-password  # NOT your Gmail password!
MAIL_FROM=noreply@vaultly.com
MAIL_FROM_NAME=Your Company Vault
```

**Gmail Setup:**
1. Enable 2FA on Gmail account
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Use that password in SMTP_PASS

**SendGrid Setup:**
```bash
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USER=apikey
SMTP_PASS=SG.xxxxxxxxxxxx
```

**MailerSend / Other Providers:**
Adjust SMTP_HOST and credentials as needed.

---

### 2. HTTPS & SSL Certificate

#### Option A: Self-Signed (Testing Only)
```bash
openssl req -x509 -newkey rsa:4096 \
  -keyout ssl/key.pem -out ssl/cert.pem \
  -days 365 -nodes \
  -subj "/CN=vaultly.yourdomain.com"
```

#### Option B: Let's Encrypt (Recommended for Production)
```bash
# Using Certbot
certbot certonly --standalone -d vaultly.yourdomain.com
cp /etc/letsencrypt/live/vaultly.yourdomain.com/fullchain.pem ssl/cert.pem
cp /etc/letsencrypt/live/vaultly.yourdomain.com/privkey.pem ssl/key.pem
```

---

### 3. Database Secrets

**Never** put passwords in docker-compose.yml for production!

#### Option A: Docker Secrets (Swarm)
```bash
echo "secure_password_12345" | docker secret create db_password -
echo "smtp_password_xyz" | docker secret create smtp_password -
```

Then in `.env`:
```bash
DB_PASS_FILE=/run/secrets/db_password
SMTP_PASS_FILE=/run/secrets/smtp_password
```

#### Option B: Docker BuildKit Secrets
```bash
docker build --secret db_pass=<(echo 'password') .
```

#### Option C: Environment Variables (Outside Compose)
```bash
export DB_PASS="secure_password_12345"
export SMTP_PASS="smtp_password_xyz"
docker-compose up
```

---

### 4. Automated Backups

#### Daily Backup with Cron
```bash
# Add to crontab -e
0 2 * * * /path/to/vaultly/backup.sh /path/to/backups
```

#### Or using Docker Service (included in compose)
Already configured - runs daily, auto-cleanup after 30 days.

#### Upload to S3
```bash
export BACKUP_S3_BUCKET=my-vaultly-backups
export AWS_ACCESS_KEY_ID=xxxxx
export AWS_SECRET_ACCESS_KEY=xxxxx
./backup.sh
```

---

### 5. Rate Limiting Configuration

Edit `.env`:
```bash
RATE_LIMIT_ENABLED=true
RATE_LIMIT_PASSWORD_RESET=5/900        # 5 attempts per 15 minutes
RATE_LIMIT_LOGIN=10/900                # 10 attempts per 15 minutes
RATE_LIMIT_REGISTER=5/3600             # 5 registrations per hour
```

---

### 6. 2FA / TOTP Setup

Edit `.env`:
```bash
TOTP_ENABLED=true
TOTP_ISSUER=Your Company
```

Users will see "Your Company (vaultly.yourdomain.com)" in authenticator apps.

---

### 7. WebAuthn / Passkeys

Edit `.env`:
```bash
WEBAUTHN_ENABLED=true
WEBAUTHN_RP_ID=vaultly.yourdomain.com
WEBAUTHN_ORIGIN=https://vaultly.yourdomain.com
```

---

### 8. CAPTCHA (Optional)

Sign up for free at: https://www.hcaptcha.com

Edit `.env`:
```bash
CAPTCHA_ENABLED=true
CAPTCHA_SITE_KEY=xxxx_your_site_key_xxxx
CAPTCHA_SECRET=xxxx_your_secret_xxxx
```

---

## Full Production docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    image: vaultly:latest
    restart: always
    environment:
      DB_HOST: mysql
      DB_USER: vaultly_user
      DB_PASS_FILE: /run/secrets/db_password
      SMTP_PASS_FILE: /run/secrets/smtp_password
      FORCE_HTTPS: "true"
      APP_URL: https://vaultly.yourdomain.com
    secrets:
      - db_password
      - smtp_password
    depends_on:
      mysql:
        condition: service_healthy
    networks:
      - vaultly

  mysql:
    image: mysql:8.0-alpine
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD_FILE: /run/secrets/mysql_root_pass
      MYSQL_PASSWORD_FILE: /run/secrets/db_password
    secrets:
      - db_password
      - mysql_root_pass
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - vaultly

  nginx:
    image: nginx:alpine
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro

secrets:
  db_password:
    external: true
  mysql_root_pass:
    external: true
  smtp_password:
    external: true

networks:
  vaultly:
    driver: overlay

volumes:
  mysql_data:
```

---

## Security Checklist

- [ ] HTTPS certificate installed (Let's Encrypt or valid CA)
- [ ] Database passwords in secrets management
- [ ] SMTP credentials configured (emails working)
- [ ] Automated daily backups running
- [ ] Backup stored off-server (S3, Azure, etc)
- [ ] Firewall configured (only 80, 443 open)
- [ ] Database access restricted to app container only
- [ ] Logs rotated (prevent disk full)
- [ ] Monitoring alerts set up
- [ ] Security headers verified (HSTS, CSP, X-Frame-Options)
- [ ] Rate limiting enabled
- [ ] 2FA recommended/enforced for users
- [ ] IP whitelist configured (if applicable)
- [ ] WAF configured (CloudFlare, AWS WAF, etc)
- [ ] DDoS protection enabled

---

## Monitoring & Maintenance

### Health Check
```bash
curl -I https://vaultly.yourdomain.com/healthz
```

### View Logs
```bash
docker-compose logs -f app
docker-compose logs -f mysql
```

### Manual Backup
```bash
./backup.sh ./backups
ls -lah backups/
```

### Database Restore
```bash
gzip -dc backups/vaultly-backup-20240101_020000.sql.gz | \
  mysql -u vaultly_user -p vaultly_db
```

### Update Security Logs
```bash
docker exec vaultly-mysql mysql -u vaultly_user -p vaultly_db -e \
  "SELECT event_type, COUNT(*) FROM security_logs GROUP BY event_type LIMIT 10;"
```

---

## Support & Resources

- **Official Docs:** https://docs.vaultly.local
- **Security Advisories:** https://github.com/vaultly/vaultly/security
- **Community Forum:** https://forum.vaultly.community
- **Report Vulnerability:** security@vaultly.com
