# AGENTS.md

## Stack

Vanilla PHP 8.1 + MySQL 8.0 (mysqli). No framework, no Composer, no build step, no JS bundler. Served via XAMPP/WAMP/LAMP.

## Configuration

- Credentials live in **`.env`** (gitignored). Template: `.env.example`.
- `includes/config.php` parses `.env` and defines constants: `HOSTNAME`, `DBNAME`, `USERNAME`, `PASSWORD`, `BASE_URL`.
- `includes/conn.php` requires `config.php` and creates `$conn` (mysqli) using those constants.
- To set up: `cp .env.example .env` then edit values.

## Database

- **Connection**: configured via `.env` → `includes/config.php` → `includes/conn.php`
- Default: `localhost`, user `root`, no password, database **`beautyland`**
- Schema dump: `beauty_care (1).sql` (note: the dump header says `beauty_care` but the app connects to `beautyland` — create the DB as `beautyland`)
- Import: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS beautyland" && mysql -u root beautyland < "beauty_care (1).sql"`
- Tables: `users`, `pelanggan`, `produk`, `transaksi`, `detail_transaksi`, `pengiriman`, `booking`, `jadwal` (and others in the dump)

## Project layout

```
index.php, login.php, register.php, logout.php   # Public entry points
admin/            # Admin panel (dashboard, products, orders, shipping, consultations, reports)
pelanggan/        # Customer panel (cart, checkout, payment, orders, consultations, profile)
includes/         # Shared: config.php (env), conn.php (DB), sidebar_admin.php, sidebar_user.php
assets/css/       # global.css, sidebar.css
assets/gambar/    # Product images (uploaded)
assets/bukti/     # Payment proof uploads
```

## Auth

- PHP sessions. Login (`login.php`) sets three keys:
  - `$_SESSION['user']` — full user row (used for auth guards)
  - `$_SESSION['role']` — `'admin'` or `'pelanggan'`
  - `$_SESSION['id_pelanggan']` — FK to `pelanggan` table
- Admin guard: `if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin')`
- Customer guard: `if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'pelanggan')` (some files check `$_SESSION['id_pelanggan']` instead — both patterns exist)
- Login routes admins to `admin/dashboard_admin.php`, customers to `pelanggan/dashboard.php`.
- Passwords use **MD5 (no salt)**. New code should use `password_hash()` / `password_verify()`.

## Conventions

- All UI text is in **Bahasa Indonesia**.
- No templating engine — HTML is inline in PHP files with `<style>` blocks (no external CSS framework).
- Fonts: Playfair Display, DM Sans via Google Fonts CDN.
- File naming: snake_case, Indonesian words (e.g. `tambah_produk.php`, `keranjang.php`).
- Form-processing endpoints are prefixed `proses_` (e.g. `proses_checkout.php`).
- All includes use `require_once __DIR__ . '/...'` — always use this pattern for new files.
- All asset URLs (CSS, images in HTML `<link>` / `<img>`) must use `<?= BASE_URL ?>/assets/...` (defined in `config.php`). Local filesystem operations (`move_uploaded_file`, `file_exists`, `unlink`) continue using relative paths (`../assets/...`).
- All navigation links (`<a href="...">`) must use `<?= BASE_URL ?>/...` (e.g. `<?= BASE_URL ?>/admin/produk.php`, `<?= BASE_URL ?>/login.php`).

## No tooling

No linter, formatter, tests, or CI. There is nothing to run beyond a local PHP server pointed at the project root.

## Known issues

- Most SQL queries use string interpolation (SQL injection risk). `register.php` uses prepared statements. **Use prepared statements for all new queries.**
- No CSRF protection on forms.
- Uploaded files (product images, payment proofs) are tracked in git (upload ignore rules in `.gitignore` are commented out).
