#!/bin/bash

# Weekilaw Backend Deployment Script
# Usage: ./deploy.sh [environment]

set -e

ENVIRONMENT=${1:-production}
PROJECT_ROOT="/var/www/weekilaw-backend"

echo "🚀 Starting backend deployment to $ENVIRONMENT environment..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root or with sudo
if [[ $EUID -eq 0 ]]; then
   print_error "This script should not be run as root"
   exit 1
fi

# Backup current deployment
print_status "Creating backup..."
if [ -d "$PROJECT_ROOT" ]; then
    BACKUP_DIR="/var/www/weekilaw-backend_backup_$(date +%Y%m%d_%H%M%S)"
    sudo cp -r $PROJECT_ROOT $BACKUP_DIR
    print_status "Backup created: $BACKUP_DIR"
fi

# Stop services
print_status "Stopping services..."
sudo systemctl stop nginx 2>/dev/null || true
sudo systemctl stop php8.4-fpm 2>/dev/null || true

# Clean and prepare
print_status "Preparing deployment directory..."
sudo mkdir -p $PROJECT_ROOT
sudo chown -R $USER:$USER $PROJECT_ROOT

# The actual deployment files should be uploaded via CI/CD
# This script assumes files are already in place

cd $PROJECT_ROOT

# Install/update PHP dependencies
print_status "Installing PHP dependencies..."
if [ -f composer.json ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Setup environment
print_status "Setting up environment..."
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        # Update for production
        sed -i 's|APP_ENV=.*|APP_ENV=production|' .env
        sed -i 's|APP_DEBUG=.*|APP_DEBUG=false|' .env
        sed -i 's|DB_CONNECTION=.*|DB_CONNECTION=mysql|' .env
        sed -i 's|DB_HOST=.*|DB_HOST=127.0.0.1|' .env
        sed -i 's|DB_PORT=.*|DB_PORT=3306|' .env
        sed -i 's|DB_DATABASE=.*|DB_DATABASE=weekilaw|' .env
        sed -i 's|DB_USERNAME=.*|DB_USERNAME=weekilaw|' .env
        sed -i 's|DB_PASSWORD=.*|DB_PASSWORD=WeekiLawDb2025|' .env
        echo "FLASK_API_URL=http://localhost:8020/ask" >> .env
    else
        # Create basic .env for production
        cat > .env << EOF
APP_ENV=$ENVIRONMENT
APP_DEBUG=false
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=weekilaw
DB_USERNAME=weekilaw
DB_PASSWORD=WeekiLawDb2025
FLASK_API_URL=http://localhost:8020/ask
LOG_LEVEL=error
EOF
    fi
fi

# Generate application key if needed
if ! grep -q "^APP_KEY=base64:" .env; then
    print_status "Generating application key..."
    php artisan key:generate --no-interaction
fi

# Clear caches
print_status "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Run migrations
print_status "Running database migrations..."
php artisan migrate --force --no-interaction

# Cache configuration for production
if [ "$ENVIRONMENT" = "production" ]; then
    print_status "Caching configuration for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Set proper permissions
print_status "Setting permissions..."
sudo chown -R www-data:www-data .
sudo chown -R $USER:$USER storage bootstrap/cache
sudo chmod -R 755 storage
sudo chmod -R 755 bootstrap/cache

# Create storage link
print_status "Creating storage link..."
php artisan storage:link

# Start services
print_status "Starting services..."
sudo systemctl restart php8.4-fpm
sudo systemctl restart nginx

# Health check
print_status "Running health checks..."
sleep 10

if curl -f http://localhost/api/chat/health > /dev/null 2>&1; then
    print_status "✅ Backend health check passed"
else
    print_warning "⚠️  Backend health check failed"
fi

if curl -f http://localhost:8020/health > /dev/null 2>&1; then
    print_status "✅ Flask API health check passed"
else
    print_warning "⚠️  Flask API health check failed - make sure Flask API is deployed separately"
fi

print_status "🎉 Backend deployment completed successfully!"
print_status "📊 Check logs with: tail -f storage/logs/laravel.log"
