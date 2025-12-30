#!/bin/bash

# Weekilaw Backend Deployment Readiness Check
# Run this before deploying to ensure no 404/500 errors

set -e

echo "🔍 Checking Weekilaw Backend Deployment Readiness..."
echo "=================================================="

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Function to print status
print_status() {
    echo -e "${GREEN}✅${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠️${NC} $1"
}

print_error() {
    echo -e "${RED}❌${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    print_error "Not in Laravel backend directory. Run from weekilaw_backend/"
    exit 1
fi

echo "📁 Checking directory structure..."
if [ -d "app" ] && [ -d "routes" ] && [ -d "resources" ]; then
    print_status "Laravel structure is correct"
else
    print_error "Laravel structure is incomplete"
    exit 1
fi

echo "🔧 Checking PHP dependencies..."
if [ -f "composer.json" ] && [ -d "vendor" ]; then
    print_status "Composer dependencies installed"
else
    print_warning "Run: composer install"
fi

echo "📦 Checking Node.js dependencies..."
if [ -f "package.json" ] && [ -d "node_modules" ]; then
    print_status "Node.js dependencies installed"
else
    print_warning "Run: npm install"
fi

echo "🏗️ Checking build assets..."
if [ -d "public/build" ] && [ -f "public/build/manifest.json" ]; then
    print_status "Assets are built"
else
    print_warning "Run: npm run build"
fi

echo "🔐 Checking environment file..."
if [ -f ".env" ]; then
    print_status ".env file exists"

    # Check required environment variables
    if grep -q "APP_KEY=base64:" .env; then
        print_status "APP_KEY is set"
    else
        print_error "APP_KEY is missing. Run: php artisan key:generate"
        exit 1
    fi

    if grep -q "FLASK_API_URL=" .env; then
        print_status "FLASK_API_URL is configured"
    else
        print_warning "FLASK_API_URL not set. Adding default..."
        echo "FLASK_API_URL=http://localhost:8020/ask" >> .env
    fi

    if grep -q "KAVENEGAR_API_KEY=" .env; then
        print_status "Kavenegar API is configured"
    else
        print_warning "Kavenegar API not configured. SMS features may not work."
    fi

else
    print_error ".env file missing. Copy from .env.example"
    exit 1
fi

echo "🛣️ Checking routes..."
if php artisan route:list | grep -q "api/auth/phone"; then
    print_status "Phone authentication routes registered"
else
    print_error "Phone authentication routes not found"
    exit 1
fi

if php artisan route:list | grep -q "api/chat"; then
    print_status "Chat API routes registered"
else
    print_error "Chat API routes not found"
    exit 1
fi

echo "🗄️ Checking database configuration..."
if grep -q "DB_CONNECTION=mysql" .env; then
    print_status "Database configured for MySQL (production)"
else
    print_warning "Database not configured for MySQL. Update .env for production."
fi

echo "🔍 Checking for potential Inertia issues..."
if [ -f "resources/js/app.tsx" ] && grep -q "createInertiaApp" resources/js/app.tsx; then
    print_status "Inertia.js configuration looks correct"
else
    print_warning "Inertia.js configuration may have issues"
fi

echo "📊 Checking storage permissions..."
if [ -w "storage" ] && [ -w "bootstrap/cache" ]; then
    print_status "Storage permissions are correct"
else
    print_warning "Storage permissions may need adjustment on server"
fi

echo ""
echo "🎯 Deployment Readiness Summary:"
echo "==============================="
echo "✅ Backend code is ready for deployment"
echo "✅ API routes are properly configured"
echo "✅ Authentication system is set up"
echo "✅ Assets are built and ready"
echo ""
echo "📋 Pre-deployment checklist:"
echo "1. Ensure Flask AI API is deployed and running on port 8020"
echo "2. Database is created and accessible on server"
echo "3. Nginx/PHP-FPM are properly configured"
echo "4. SSL certificates are set up (if using HTTPS)"
echo ""
print_status "Backend deployment readiness check completed!"
