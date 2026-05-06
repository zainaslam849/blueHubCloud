# blueHubCloud

Enterprise call intelligence and reporting platform built with Laravel 11 and Vue 3.

This repository contains:

- A Laravel backend for ingestion, processing, AI enrichment, categorization, and reporting
- An admin SPA served by Laravel Vite assets in resources/js
- A separate dashboard SPA in dashboard for tenant-facing workflows

## Core capabilities

- Multi-tenant PBX call ingestion and synchronization
- AI-assisted transcription, summarization, and categorization pipeline
- Confidence-gated categorization workflows for operational review
- Weekly call reports with export support
- Admin controls for companies, PBX accounts, AI settings, and branding
- Queue-driven processing with Horizon support for production scale

## Technology stack

- Backend: PHP 8.2+, Laravel 11, Horizon, MySQL, Redis
- Frontend (admin): Vue 3 + Vite through Laravel asset pipeline
- Frontend (dashboard): Vue 3 + TypeScript + Vite in dashboard
- Integrations: AWS SDK, AWS Secrets Manager, optional S3 object storage

## Repository layout

- app: Domain logic, services, jobs, controllers, policies, models
- routes: API and web route definitions
- resources: Admin SPA source and Blade assets
- dashboard: Separate Vue 3 + TypeScript dashboard project
- deploy: Production service configs and install helpers
- config: Application and integration configuration

## Local development setup

### Prerequisites

- PHP 8.2+
- Composer 2+
- Node.js 18+
- npm 9+
- MySQL 8+ (or compatible)
- Redis (recommended for queue processing)

### 1) Install backend dependencies

```bash
composer install
```

### 2) Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Update environment variables in .env for:

- Application URL and database connection
- Queue/cache/session drivers
- Redis host/port (if used)
- Scheduler webhook token (SCHEDULER_WEBHOOK_TOKEN)

### 3) Database setup

```bash
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
```

### 4) Install admin frontend dependencies

```bash
npm install
```

### 5) Run local services

In separate terminals:

```bash
php artisan serve
npm run dev
php artisan queue:work
```

For Horizon-based local queue processing:

```bash
php artisan horizon
```

## Dashboard app (dashboard)

The dashboard folder is an independent Vue 3 + TypeScript project.

```bash
cd dashboard
npm install
cp .env.example .env
npm run dev
```

Set VITE_API_BASE_URL in dashboard/.env to point to your Laravel API base URL.

## Build commands

Admin assets (root project):

```bash
npm run build
```

Dashboard assets:

```bash
cd dashboard
npm run build
```

## Queue and scheduler in production

Use Redis + Horizon for queue processing and a system scheduler for scheduled tasks.

### Option A: Supervisor (Horizon)

- Config: [deploy/supervisor/bluehubcloud-horizon.conf](deploy/supervisor/bluehubcloud-horizon.conf)
- Start:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start bluehubcloud-horizon
```

- Auto install:

```bash
sudo APP_PATH=/var/www/blueHubCloud APP_USER=www-data bash deploy/bin/install-supervisor.sh
```

### Option B: systemd (Horizon + Scheduler)

- Horizon unit: [deploy/systemd/bluehubcloud-horizon.service](deploy/systemd/bluehubcloud-horizon.service)
- Scheduler unit: [deploy/systemd/bluehubcloud-scheduler.service](deploy/systemd/bluehubcloud-scheduler.service)
- Scheduler timer: [deploy/systemd/bluehubcloud-scheduler.timer](deploy/systemd/bluehubcloud-scheduler.timer)

Enable and start:

```bash
systemctl enable --now bluehubcloud-horizon
systemctl enable --now bluehubcloud-scheduler.timer
```

Auto install:

```bash
sudo APP_PATH=/var/www/blueHubCloud APP_USER=www-data bash deploy/bin/install-systemd.sh
```

## Security and secrets

- Do not commit .env, credentials, or private keys
- PBX and related credentials are designed to be resolved from AWS Secrets Manager
- Keep production API keys and cloud credentials outside source control
- Restrict cron webhook access using SCHEDULER_WEBHOOK_TOKEN

## API and admin routes

- Admin SPA entry and admin API routes are defined in [routes/web.php](routes/web.php)
- API placeholder file exists in [routes/api.php](routes/api.php)

## Operational notes

- Duplicate call prevention is enforced by unique identifiers during ingestion
- AI categorization uses strict confidence enforcement before persistence workflows
- Branding uploads can use configured storage backends, including S3 when enabled

## Troubleshooting quick checks

```bash
php artisan optimize:clear
php artisan config:clear
php artisan route:list
php artisan queue:failed
```

For frontend build diagnostics:

```bash
npm run build
cd dashboard && npm run build
```

## License

This project is proprietary unless explicitly stated otherwise by the repository owner.
