#!/bin/bash
# Production-ready Docker setup for Vaultly

set -e

echo "🚀 Vaultly Docker Setup"
echo "======================="

# Check Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker not found. Please install Docker Desktop."
    exit 1
fi

# Create .env if not exists
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cat > .env << 'EOF'
# Database credentials (CHANGE THESE FOR PRODUCTION)
DB_PASSWORD=vaultly_secure_password_change_me
MYSQL_ROOT_PASSWORD=mysql_root_password_change_me

# Environment
ENVIRONMENT=production
EOF
    echo "✓ .env created. Update credentials in .env before deploying to production."
fi

# Build images
echo "🔨 Building Docker images..."
docker-compose build --no-cache

# Pull latest base images
echo "📦 Pulling latest base images..."
docker-compose pull

# Start services
echo "🚀 Starting services..."
docker-compose up -d --pull always

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL to be ready..."
for i in {1..30}; do
    if docker-compose exec -T mysql mysqladmin ping -h localhost &> /dev/null; then
        echo "✓ MySQL is ready"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "❌ MySQL failed to start"
        exit 1
    fi
    sleep 1
done

# Wait for PHP-FPM to be ready
echo "⏳ Waiting for PHP-FPM to be ready..."
for i in {1..30}; do
    if docker-compose exec -T app curl -f http://localhost:9000/ping &> /dev/null; then
        echo "✓ PHP-FPM is ready"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "❌ PHP-FPM failed to start"
        exit 1
    fi
    sleep 1
done

# Check services
echo ""
echo "✅ Services started successfully!"
echo ""
echo "📊 Service Status:"
docker-compose ps

echo ""
echo "🔗 Access the application:"
echo "   HTTP:  http://localhost"
echo "   HTTPS: https://localhost (if SSL configured)"
echo ""
echo "📋 Useful commands:"
echo "   View logs:        docker-compose logs -f [service]"
echo "   Stop services:    docker-compose down"
echo "   Rebuild images:   docker-compose build --no-cache"
echo "   Database shell:   docker-compose exec mysql mysql -u root -p"
echo ""
