# Tan90 ERP — setup for whoever receives this ZIP

This is the merged GRN + Master Data + BOM/Recipe/Costing app, with demo
logins for all three modules already seeded. **A database dump with all
that demo data is included** at `database/dumps/tan90_demo_seed.sql` —
import it and you get every demo login immediately, no need to run
migrations or seeders yourself.

## Requirements

- Docker Desktop (this app needs PHP 8.3+; if you don't already have that
  installed locally, Docker is the fastest path — the project ships its
  own PHP 8.5 + MySQL 8.4 setup).

## Setup

1. Unzip this folder anywhere.
2. Rename `.env.package` to `.env` (the real `.env` was intentionally left
   out of this ZIP — see "What's not included" below).
3. From the project folder:
   ```
   docker compose up -d
   ```
   First run builds the PHP image — a few minutes. After that, `docker compose up -d` is fast.
4. `.env`'s `APP_KEY` is deliberately left blank — every install should
   generate its own rather than reuse one from a template:
   ```
   docker compose exec laravel.test php artisan key:generate
   ```
5. Import the demo data:
   ```
   docker compose exec -T mysql mysql -u sail -ppassword tan90_mod1 < database/dumps/tan90_demo_seed.sql
   ```
6. Open **http://localhost:8000** — the login page lists every role across
   all three modules. Click any tile to log straight in, or use
   `demo123` as the password for any of the emails below.

## Demo logins (all password `demo123`)

**GRN (Inward to GRN Control Tower):** guard@tan90.test, vendor@tan90.test,
storeexec@tan90.test, qc@tan90.test, storemanager@tan90.test,
finance@tan90.test, admin@tan90.test

**Master Data & Configuration:** admin@tan90.demo, masterdata@tan90.demo,
plant@tan90.demo, auditor@tan90.demo, qc@tan90.demo, finance@tan90.demo,
procurement@tan90.demo

**BOM, Recipe & Costing:** admin@tan90.demo (same account as Master Data's),
rd@tan90.demo, formula@tan90.demo, costing@tan90.demo, production@tan90.demo,
qa@tan90.demo, plant-brc@tan90.demo, auditor-brc@tan90.demo

## What's not included

- The real `.env` — the ZIP's `.env.package` has working local DB
  credentials but blanks out the Zoho CRM API keys (those are tied to the
  original account; GRN, Master Data, and BOM all work fully without them —
  only live Zoho PO sync would need them refilled).
- `.git` history and `node_modules` — not needed to run the app.

## If you'd rather not use Docker

The app itself needs PHP 8.3+ (not older). If you already have that
installed, you can run it directly instead:
```
composer install
php artisan key:generate
# point DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD in .env at your own MySQL
php artisan migrate
mysql -u <user> -p <database> < database/dumps/tan90_demo_seed.sql
php artisan serve
```
