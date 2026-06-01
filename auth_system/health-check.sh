#!/bin/bash
# Production health check and monitoring script

HEALTHCHECKS=(
    "http://app:9000/ping"
    "http://mysql:3306"
    "http://nginx:80"
)

echo "🏥 Vaultly Health Check"
echo "======================="
echo ""

# App health
echo "📱 App Service:"
docker-compose exec -T app curl -s -f http://localhost:9000/ping && echo "✓ PHP-FPM responding" || echo "✗ PHP-FPM not responding"

# MySQL health
echo ""
echo "🗄️  Database Service:"
docker-compose exec -T mysql mysqladmin ping -h localhost &>/dev/null && echo "✓ MySQL responding" || echo "✗ MySQL not responding"

# Nginx health
echo ""
echo "🌐 Web Server:"
docker-compose exec -T nginx wget -q -O- http://localhost/ &>/dev/null && echo "✓ Nginx responding" || echo "✗ Nginx not responding"

# Container resource usage
echo ""
echo "📊 Resource Usage:"
docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}"

# Logs summary
echo ""
echo "📋 Recent Errors (last 50 lines):"
docker-compose logs --tail=50 2>&1 | grep -i "error\|warning\|exception" || echo "✓ No errors found"

echo ""
echo "✅ Health check complete"
