# WIP & BOM Monitoring System

CodeIgniter 3 application for monitoring Work-In-Process (WIP) vehicles and managing
the Master Bill of Material (BOM), built for KAP1/KAP2 production tracking.

## Features

- **Login** — session based authentication, password hashed with `password_hash()`.
- **Dashboard** — quick summary of BOM records, account count, and recent upload history.
- **Master BOM** — server-side DataTable listing, Excel template download, and Excel
  upload (append or replace). Each row's `part_number` is generated automatically
  from `Component` by stripping a trailing `-00`.
- **Master WIP** — `KAP 1` and `KAP 2` sub-menus, each with per-shop tabs
  (Welding / Toso / Assy) and a Table/Card view toggle. Data is fetched live from the
  KAP1 (`sv-web-kap`) and KAP2 (`Web_AndonPCD`) endpoints documented in `docs/`.
- **Akun** — full CRUD for user accounts (admin only).
- Dark UI (default), DataTables with 25/50/100 pagination, responsive layout, and search
  enabled everywhere.

## Requirements

- PHP >= 7.4 (tested on 7.4 and 8.3+) with `mysqli`, `curl`, `zip`, `gd` extensions
- MySQL / MariaDB
- Composer

## Setup

1. Install dependencies (make sure `composer`/`php` on your PATH resolves to a
   PHP >= 7.4 binary — on a multi-PHP-version Laragon setup, either switch the
   active CLI version first or run composer explicitly with the right binary,
   e.g. `path\to\php74\php.exe path\to\composer.phar install`):
   ```bash
   composer install
   ```
2. Create the database and seed the default admin account:
   ```bash
   mysql -u root < database/schema.sql
   ```
   This creates the `wip_sto` database and an `admin` / `admin123` account.
3. Configure the database connection in `application/config/database.php`
   (defaults to `root` with no password, database `wip_sto`).
4. Configure the WIP data source endpoints in `application/config/wip_api.php`
   if they differ from the documented defaults (see `docs/get_data_wip_kap1.md`
   and `docs/get_data_wip_kap2.md`). If KAP2 requires an authenticated session,
   set `kap2_cookie` to a valid `Cookie` header value.
5. Point your web server (or Laragon virtual host) to this folder and set
   `base_url` in `application/config/config.php` accordingly.
6. Login with `admin` / `admin123` and change the password via the **Akun** menu.

## Excel upload template (Master BOM)

The template (downloadable from the Master BOM page) uses these columns:

| Material | Suffix | Component | Material Description | Qty | Uom | Shop Code |
|---|---|---|---|---|---|---|

`part_number` is derived automatically on upload (Component minus a trailing `-00`).

## Project structure

Standard CodeIgniter 3 layout (`application/`, `system/`), plus:

- `assets/` — app CSS/JS and vendored front-end libraries (Bootstrap 5, DataTables,
  SweetAlert2, Bootstrap Icons) — no CDN dependency at runtime.
- `database/schema.sql` — database schema + default admin seed.
- `docs/` — WIP source endpoint documentation.
