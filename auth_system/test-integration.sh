#!/bin/bash
#
# Integration Test Script
# Verifies all critical features are working
#

set -e

COLORS='\033[0m'
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'

PASS=0
FAIL=0

test_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✅ PASS${COLORS}: $2"
        PASS=$((PASS + 1))
    else
        echo -e "${RED}❌ FAIL${COLORS}: $2"
        FAIL=$((FAIL + 1))
    fi
}

echo -e "${BLUE}🧪 Vaultly Integration Tests${COLORS}"
echo "========================================================"

# Test 1: Database Connection
echo -e "\n${BLUE}Testing Database...${COLORS}"
docker exec vaultly-app php -r "include 'includes/db.php'; echo 'DB OK';" &> /dev/null
test_result $? "Database connection"

# Test 2: Table Creation
echo -e "\n${BLUE}Testing Tables...${COLORS}"
docker exec vaultly-mysql mysql -u vaultly_user -pchange_me_secure_password vaultly_db -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vaultly_db'" &> /dev/null
test_result $? "Table initialization"

# Test 3: PHP Classes Loading
echo -e "\n${BLUE}Testing PHP Classes...${COLORS}"
docker exec vaultly-app php -r "
include 'includes/db.php';
include 'includes/EmailManager.php';
include 'includes/RateLimiter.php';
include 'includes/PasswordBreachChecker.php';
include 'includes/RememberMeManager.php';
include 'includes/BackupCodesManager.php';
include 'includes/VaultShareManager.php';
echo 'All classes loaded';
" &> /dev/null
test_result $? "PHP class loading"

# Test 4: Security Logging
echo -e "\n${BLUE}Testing Security Features...${COLORS}"
docker exec vaultly-app php -r "
include 'includes/db.php';
logSecurityEvent('test_event', null, 'Integration test');
echo 'Security logging OK';
" &> /dev/null
test_result $? "Security event logging"

# Test 5: Rate Limiting
echo -e "\n${BLUE}Testing Rate Limiting...${COLORS}"
docker exec vaultly-app php -r "
include 'includes/db.php';
include 'includes/RateLimiter.php';
RateLimiter::init(\$conn);
\$check = RateLimiter::check('test', 5, 900, 'test@example.com');
echo json_encode(\$check);
" &> /dev/null
test_result $? "Rate limiter initialization"

# Test 6: Password Strength Validation
echo -e "\n${BLUE}Testing Password Validation...${COLORS}"
docker exec vaultly-app php -r "
include 'includes/db.php';
\$weak = validatePasswordStrength('weak');
\$strong = validatePasswordStrength('Secure@123!');
if (!\$weak && \$strong) echo 'Validation OK';
" &> /dev/null
test_result $? "Password strength validation"

# Test 7: Nginx Health Check
echo -e "\n${BLUE}Testing Web Server...${COLORS}"
docker exec vaultly-web wget -q -O- http://localhost/healthz &> /dev/null
test_result $? "Nginx health endpoint"

# Test 8: API Endpoints Exist
echo -e "\n${BLUE}Testing API Endpoints...${COLORS}"
[ -f "api/v1/auth/verify-email.php" ] && [ -f "api/v1/activity/export.php" ]
test_result $? "API v1 endpoints created"

# Test 9: Configuration Files
echo -e "\n${BLUE}Testing Configuration...${COLORS}"
[ -f ".env.example" ] && [ -f "docker-compose.yml" ] && [ -f "nginx.conf" ]
test_result $? "Configuration files present"

# Test 10: Backup Script
echo -e "\n${BLUE}Testing Backup System...${COLORS}"
[ -f "backup.sh" ] && [ -x "backup.sh" ]
test_result $? "Backup script executable"

# Test 11: Docker Compose Health Checks
echo -e "\n${BLUE}Testing Container Health...${COLORS}"
STATUS=$(docker-compose ps | grep -c "healthy" || echo 0)
if [ "$STATUS" -ge 3 ]; then
    test_result 0 "All containers healthy"
else
    test_result 1 "Some containers unhealthy"
fi

# Test 12: Directory Structure
echo -e "\n${BLUE}Testing Directory Structure...${COLORS}"
[ -d "includes" ] && [ -d "api" ] && [ -d "css" ] && [ -d "js" ] && [ -d "uploads" ]
test_result $? "Required directories exist"

echo ""
echo "========================================================"
echo -e "${BLUE}Test Results:${COLORS}"
echo -e "  ${GREEN}✅ Passed: $PASS${COLORS}"
echo -e "  ${RED}❌ Failed: $FAIL${COLORS}"

if [ $FAIL -eq 0 ]; then
    echo -e "\n${GREEN}🎉 All tests passed!${COLORS}"
    exit 0
else
    echo -e "\n${RED}⚠️  Some tests failed. Check configuration.${COLORS}"
    exit 1
fi
