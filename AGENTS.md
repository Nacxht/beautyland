# AGENTS.md

## Stack

Vanilla PHP 8.1 + MySQL 8.0 (mysqli). No framework, no Composer, no build step, no JS bundler. Served via XAMPP/WAMP/LAMP.

## Database

- **Connection** (`includes/conn.php`): `localhost`, user `root`, no password, database **`beautyland`**
- Schema dump: `beauty_care (1).sql` (note: the dump header says `beauty_care` but the app connects to `beautyland` — create the DB as `beautyland`)
- Import: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS beautyland" && mysql -u root beautyland < "beauty_care (1).sql"`
- Tables: `users`, `pelanggan`, `produk`, `transaksi`, `detail_transaksi`, `pengiriman`, `booking`, `jadwal` (and others in the dump)

## Project layout

```
index.php, login.php, register.php, logout.php   # Public entry points
admin/            # Admin panel (dashboard, products, orders, shipping, consultations, reports)
pelanggan/        # Customer panel (cart, checkout, payment, orders, consultations, profile)
includes/         # Shared: conn.php (DB), sidebar_admin.php, sidebar_user.php
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
- Includes use relative paths: `include '../includes/conn.php'` from subdirectories.

## No tooling

No linter, formatter, tests, or CI. There is nothing to run beyond a local PHP server pointed at the project root.

## Known issues

- Most SQL queries use string interpolation (SQL injection risk). `register.php` uses prepared statements. **Use prepared statements for all new queries.**
- No CSRF protection on forms.
- Uploaded files (product images, payment proofs) are tracked in git (upload ignore rules in `.gitignore` are commented out).
