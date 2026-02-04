# Laravel Backend Deployment Guide (Weekilaw)

This guide walks you through deploying the Weekilaw Laravel backend to a Linux server (Ubuntu/Debian). The app uses **PHP 8.2+**, **Laravel 12**, **MongoDB**, **Nginx**, and optional **Redis**.

**Source:** [github.com/nightpgmr/weekilaw_backend](https://github.com/nightpgmr/weekilaw_backend) — branch **`Sajjad`**.

---

## Table of Contents

1. [Server Requirements](#1-server-requirements)
2. [Initial Server Setup](#2-initial-server-setup)
3. [Install PHP and Extensions](#3-install-php-and-extensions)
4. [Install Nginx and PHP-FPM](#4-install-nginx-and-php-fpm)
5. [Install Composer and Node.js](#5-install-composer-and-nodejs)
6. [MongoDB Connection](#6-mongodb-connection)
7. [Deploy Application Code](#7-deploy-application-code)
8. [Environment Configuration](#8-environment-configuration)
9. [Permissions and Storage](#9-permissions-and-storage)
10. [Nginx Configuration](#10-nginx-configuration)
11. [SSL with Let's Encrypt](#11-ssl-with-lets-encrypt)
12. [Post-Deploy Optimization](#12-post-deploy-optimization)
13. [Optional: Queue Workers & Cron](#13-optional-queue-workers--cron)
14. [Using the Deploy Script](#14-using-the-deploy-script)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Server Requirements

- **OS**: Ubuntu 22.04 LTS or 24.04 LTS (or Debian equivalent)
- **PHP**: 8.2 or 8.4 with required extensions
- **Web server**: Nginx (or Apache with `mod_rewrite`)
- **Database**: MongoDB (remote or local). Laravel also uses SQLite/MySQL for sessions, cache, etc.
- **Composer**: 2.x
- **Node.js**: 18+ (for building frontend assets; optional on server if you build locally)

Required PHP extensions: `bcmath`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `mongodb`, `openssl`, `pdo`, `pdo_sqlite` (or `pdo_mysql`), `tokenizer`, `xml`, `zip`.

---

## 2. Initial Server Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Create a deploy user (optional but recommended)
sudo adduser deploy
sudo usermod -aG www-data deploy
```

Ensure your server has a **fixed IP** or **domain** pointing to it (e.g. `panel.weekilaw.com` or `weekilaw.com`).

---

## 3. Install PHP and Extensions

**Ubuntu 22.04 / 24.04:**

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# PHP 8.4 and required extensions (including MongoDB)
sudo apt install -y php8.4-fpm php8.4-cli php8.4-common php8.4-mysql php8.4-sqlite3 \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd

# MongoDB PHP driver (required for this project)
sudo apt install -y php8.4-mongodb || sudo pecl install mongodb
# If using pecl, add extension=mongodb.so to a .ini file in /etc/php/8.4/mods-available/
```

Verify:

```bash
php -v
php -m | grep -E 'mongodb|pdo|curl|mbstring|xml'
```

---

## 4. Install Nginx and PHP-FPM

```bash
sudo apt install -y nginx
sudo systemctl enable nginx
sudo systemctl enable php8.4-fpm
```

PHP-FPM will listen on a socket (e.g. `unix:/run/php/php8.4-fpm.sock`). We'll use it in the Nginx config below.

---

## 5. Install Composer and Node.js

```bash
# Composer
cd /tmp
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version

# Node.js (for building assets)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

---

## 6. MongoDB Connection

This Laravel app uses **MongoDB** for users and payment-related data. You can use:

- **Remote MongoDB** (e.g. your existing `130.185.75.96`) — no MongoDB install on the app server.
- **Local MongoDB** (optional):

```bash
sudo apt install -y mongodb
# Or use official MongoDB repo for a specific version
```

In `.env` you only need:

- `MONGODB_URI=mongodb://user:pass@host:27017/?authSource=admin`
- `MONGODB_DATABASE=test`
- `MONGODB_COLLECTION=users`

Ensure the server can reach the MongoDB host (firewall/security group allows port 27017 if remote).

---

## 7. Deploy Application Code

**Option A: Git (recommended)**

Repository: [nightpgmr/weekilaw_backend](https://github.com/nightpgmr/weekilaw_backend) — use branch **`Sajjad`**.

```bash
sudo mkdir -p /var/www/weekilaw-backend
sudo chown $USER:www-data /var/www/weekilaw-backend
cd /var/www/weekilaw-backend

# Clone the backend repo and checkout the Sajjad branch
git clone -b Sajjad https://github.com/nightpgmr/weekilaw_backend.git .
```

**Option B: Upload archive (e.g. from CI/CD)**

```bash
cd /var/www/weekilaw-backend
# Upload backend.tar.gz here, then:
tar -xzf backend.tar.gz
```

**Install dependencies and build assets:**

```bash
cd /var/www/weekilaw-backend

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
```

---

## 8. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` for **production**:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.weekilaw.com
FRONTEND_URL=https://weekilaw.com

# Use SQLite for simplicity, or MySQL (see deploy.sh for MySQL vars)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/weekilaw-backend/database/database.sqlite

# Or MySQL:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=weekilaw
# DB_USERNAME=weekilaw
# DB_PASSWORD=your_secure_password

# MongoDB (required for users and payments)
MONGODB_URI=mongodb://USER:PASS@HOST:27017/?authSource=admin
MONGODB_DATABASE=test
MONGODB_COLLECTION=users

# Kavenegar (SMS/OTP)
KAVENEGAR_API_KEY=your_key
KAVENEGAR_TEMPLATE=weekilaw
OTP_DEV_CODE=12345

# Google OAuth
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://panel.weekilaw.com/api/auth/google/callback

# Saman (SEP) Payment
SEP_MERCHANT_ID=15328639
SEP_TERMINAL_ID=15328639
SEP_CALLBACK_URL=https://weekilaw.com/api/payment/payment-listener
SEP_BASE_URL=https://weekilaw.com
SEP_VERIFY_URL=https://weekilaw.com/api/payment/verify-callback
SEP_WEB_APP_URL=https://weekilaw.com

# Flask API (if used)
FLASK_API_URL=http://your-flask-host:5010/chat
```

**Important:** Do not commit `.env`. Keep secrets in GitHub Actions secrets or a secure vault and inject them during deploy.

---

## 9. Permissions and Storage

```bash
cd /var/www/weekilaw-backend

# Create SQLite DB file if using SQLite
touch database/database.sqlite

# Directories writable by web server
sudo chown -R www-data:www-data /var/www/weekilaw-backend
sudo chmod -R 755 storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache   # so Laravel can write

php artisan storage:link
```

If you run artisan as `www-data`:

```bash
sudo -u www-data php artisan storage:link
```

---

## 10. Nginx Configuration

Create a site config (e.g. `weekilaw-backend`):

```bash
sudo nano /etc/nginx/sites-available/weekilaw-backend
```

Paste (replace `panel.weekilaw.com` and paths if different):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name panel.weekilaw.com;
    root /var/www/weekilaw-backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }
}
```

Enable and test:

```bash
sudo ln -s /etc/nginx/sites-available/weekilaw-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 11. SSL with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d panel.weekilaw.com
```

Certbot will add HTTPS and redirect HTTP to HTTPS. Renewal is automatic via cron.

---

## 12. Post-Deploy Optimization

Run once after deploy (and after any code/config change):

```bash
cd /var/www/weekilaw-backend
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear

# Migrations (for SQLite/MySQL; MongoDB does not use Laravel migrations)
sudo -u www-data php artisan migrate --force

# Production caches
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

---

## 13. Optional: Queue Workers & Cron

If you use queues:

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/weekilaw-worker.conf
```

Example:

```ini
[program:weekilaw-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/weekilaw-backend/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/weekilaw-backend/storage/logs/worker.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start weekilaw-worker
```

**Cron** (for scheduler):

```bash
sudo crontab -u www-data -e
```

Add:

```
* * * * * cd /var/www/weekilaw-backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 14. Using the Deploy Script

The repo includes `deploy.sh` for a manual deploy on the server. It assumes:

- Project root: `/var/www/weekilaw-backend`
- Nginx and PHP 8.4-FPM installed
- You are not root (run as deploy user or your user)

**Usage:**

```bash
cd /var/www/weekilaw-backend
chmod +x deploy.sh
./deploy.sh production
```

The script will:

- Create a backup of the current app
- Stop Nginx and PHP-FPM
- Run `composer install --no-dev`
- Create/update `.env` from `.env.example` (customize the script for your real DB and URLs)
- Generate `APP_KEY` if missing
- Clear and recache config/route/view
- Run migrations
- Set permissions for `www-data`
- Create storage link
- Restart PHP-FPM and Nginx

**Note:** The script is tuned for MySQL in a few places; if you use SQLite or different DB, edit the `sed` lines in `deploy.sh` or maintain `.env` outside the script (e.g. via CI/CD secrets).

---

## 15. Troubleshooting

| Issue | What to check |
|-------|----------------|
| 500 Internal Server Error | `storage/logs/laravel.log`, Nginx error log, `APP_DEBUG=true` temporarily (then turn off). Permissions on `storage` and `bootstrap/cache`. |
| MongoDB connection failed | `MONGODB_URI`, firewall to MongoDB host, PHP extension `mongodb` installed (`php -m \| grep mongodb`). |
| Payment callback not reached | `SEP_CALLBACK_URL` must be exactly the URL Saman calls (e.g. `https://weekilaw.com/api/payment/payment-listener`). Nginx and SSL must be correct. |
| Permission denied (storage) | `sudo chown -R www-data:www-data storage bootstrap/cache` and `chmod -R 775 storage bootstrap/cache`. |
| PHP version / extension | `php -v`, `php -m`. Install missing extensions (e.g. `php8.4-mongodb`, `php8.4-xml`). |
| Nginx 502 Bad Gateway | PHP-FPM running: `sudo systemctl status php8.4-fpm`. Socket path in Nginx matches `php8.4-fpm` config. |

**Useful commands:**

```bash
# Laravel logs
tail -f /var/www/weekilaw-backend/storage/logs/laravel.log

# Nginx
sudo nginx -t
sudo systemctl status nginx

# PHP-FPM
sudo systemctl status php8.4-fpm
```

---

## Quick Checklist

- [ ] Server updated, PHP 8.2+ and extensions (including `mongodb`) installed
- [ ] Nginx and PHP-FPM installed and enabled
- [ ] Code in `/var/www/weekilaw-backend`, `composer install --no-dev`, `npm run build`
- [ ] `.env` created and production values set (DB, MongoDB, Kavenegar, Google, SEP URLs)
- [ ] `APP_KEY` generated, `php artisan storage:link`
- [ ] Permissions: `www-data` owns files, `storage` and `bootstrap/cache` writable
- [ ] Nginx site enabled, `root` points to `.../public`, PHP-FPM socket correct
- [ ] SSL configured (e.g. Certbot)
- [ ] `config:cache`, `route:cache`, `view:cache` run
- [ ] Migrations run; MongoDB reachable
- [ ] Health/API endpoint tested (e.g. `/api/chat/health` or `/api/health`)

After this, your Laravel backend is deployed and ready to serve the frontend and payment callbacks.
