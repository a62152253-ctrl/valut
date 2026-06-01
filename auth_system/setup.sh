#!/bin/bash
#
# Vaultly Setup & Installation Script
# Run after `docker-compose up -d`
#

set -e

COLORS='\033[0m'
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'

echo -e "${BLUE}🔐 Vaultly Security Vault - Setup Wizard${COLORS}"
echo "========================================================"
echo ""

# Check if Docker is running
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker is not installed. Please install Docker first.${COLORS}"
    exit 1
fi

# Check if containers are running
echo -e "${BLUE}📋 Checking Docker containers...${COLORS}"
if ! docker ps | grep -q vaultly-mysql; then
    echo -e "${YELLOW}⚠️  MySQL container not running. Starting Docker Compose...${COLORS}"
    docker-compose up -d
    sleep 10
fi

# Wait for MySQL to be ready
echo -e "${BLUE}⏳ Waiting for MySQL to be ready...${COLORS}"
max_attempts=30
attempt=0
while ! docker exec vaultly-mysql mysqladmin ping -h localhost -u vaultly_user -pchange_me_secure_password &> /dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo -e "${RED}❌ MySQL failed to start${COLORS}"
        exit 1
    fi
    echo -n "."
    sleep 1
done
echo -e "\n${GREEN}✅ MySQL is ready${COLORS}"

# Install PHP dependencies
echo -e "${BLUE}📦 Installing PHP dependencies...${COLORS}"
if [ -f "composer.json" ]; then
    if command -v composer &> /dev/null; then
        composer install --no-interaction --no-dev
    else
        echo -e "${YELLOW}⚠️  Composer not found, skipping PHP dependencies${COLORS}"
    fi
fi

# Initialize database tables
echo -e "${BLUE}🗄️  Initializing database...${COLORS}"
docker exec vaultly-app php -r "include 'includes/db.php'; echo 'Database initialized successfully.';"

# Create necessary directories
echo -e "${BLUE}📁 Creating directories...${COLORS}"
mkdir -p uploads logs backups ssl
chmod 755 uploads logs backups
chmod 700 ssl

# Copy environment file
if [ ! -f ".env" ]; then
    echo -e "${BLUE}⚙️  Creating .env file...${COLORS}"
    cp .env.example .env
    echo -e "${YELLOW}⚠️  Please update .env with your SMTP configuration${COLORS}"
fi

# Make backup script executable
if [ -f "backup.sh" ]; then
    chmod +x backup.sh
fi

# Generate self-signed certificate for testing (if SSL directory is empty)
if [ ! -f "ssl/cert.pem" ] || [ ! -f "ssl/key.pem" ]; then
    echo -e "${BLUE}🔒 Generating self-signed SSL certificate...${COLORS}"
    openssl req -x509 -newkey rsa:4096 -keyout ssl/key.pem -out ssl/cert.pem -days 365 -nodes -subj "/CN=vaultly.local"
    echo -e "${GREEN}✅ Certificate generated${COLORS}"
fi

# Create default user (optional)
echo ""
echo -e "${BLUE}👤 Would you like to create a test user? (y/n)${COLORS}"
read -r create_user
if [ "$create_user" = "y" ]; then
    read -p "Email: " email
    read -sp "Password: " password
    echo ""

    # Create user via PHP script
    docker exec vaultly-app php -r "
        include 'includes/db.php';
        \$username = 'testuser';
        \$email = '$email';
        \$password = '$password';

        if (!validatePasswordStrength(\$password)) {
            die('Password does not meet strength requirements');
        }

        \$stmt = \$conn->prepare('INSERT INTO users (username, email, password, email_verified) VALUES (?, ?, ?, 1)');
        if (\$stmt->bind_param('sss', \$username, \$email, hashPassword(\$password))) {
            if (\$stmt->execute()) {
                echo 'User created successfully';
                logSecurityEvent('user_created_manual', null, 'Test user created');
            } else {
                echo 'Error: ' . \$stmt->error;
            }
        }
        \$stmt->close();
    "
fi

echo ""
echo -e "${GREEN}✨ Setup complete!${COLORS}"
echo ""
echo -e "${BLUE}📍 Access your vault at:${COLORS}"
echo "  🌐 https://vaultly.local (HTTPS)"
echo "  🌐 http://localhost (HTTP dev mode)"
echo ""
echo -e "${BLUE}📚 Important URLs:${COLORS}"
echo "  📝 Register: https://vaultly.local/register.php"
echo "  🔓 Login: https://vaultly.local/login.php"
echo "  📊 Dashboard: https://vaultly.local/dashboard.php"
echo ""
echo -e "${YELLOW}⚠️  Production checklist:${COLORS}"
echo "  ☐ Update .env with real SMTP credentials"
echo "  ☐ Generate proper SSL certificate (Let's Encrypt)"
echo "  ☐ Change default database passwords"
echo "  ☐ Enable HTTPS enforcement"
echo "  ☐ Set up automated backups (backup.sh)"
echo "  ☐ Configure firewall rules"
echo "  ☐ Enable 2FA for all users"
echo ""
echo -e "${BLUE}🚀 Next steps:${COLORS}"
echo "  1. Register at: https://vaultly.local/register.php"
echo "  2. Verify your email (check logs)"
echo "  3. Login and create your vault"
echo "  4. Enable 2FA in settings"
echo ""
echo -e "${BLUE}📞 Support:${COLORS}"
echo "  Logs: tail -f logs/app.log"
echo "  Errors: docker-compose logs -f app"
echo ""
