# AGENTS.md

## Stack

Vanilla PHP 8 + MySQL 8 (mysqli). No framework, no Composer, no build step, no JS bundler. Served via XAMPP/WAMP/LAMP.

## Database

- Name: `beauty_care` — schema defined in `beauty_care (1).sql`
- Connection: `includes/conn.php` — `localhost`, user `root`, no password
- Import schema: `mysql -u root beauty_care < "beauty_care (1).sql"`

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

- PHP sessions. Role stored in `$_SESSION['role']`: `admin` or `pelanggan`.
- Login routes admins to `admin/dashboard_admin.php`, customers to `pelanggan/dashboard.php`.
- Passwords use MD5 (no salt). New code should use `password_hash()` / `password_verify()`.

## Conventions

- All UI text is in **Bahasa Indonesia**.
- No templating engine — HTML is inline in PHP files.
- Fonts: Poppins, Playfair Display, DM Sans via Google Fonts CDN.
- File naming: snake_case, Indonesian words (e.g. `tambah_produk.php`, `keranjang.php`).
- Processing endpoints are prefixed `proses_` (e.g. `proses_checkout.php`).

## No tooling

No linter, formatter, tests, CI, Docker, or `.gitignore`. There is nothing to run beyond a local PHP server pointed at the project root.

## Known issues

- SQL queries use string interpolation — vulnerable to SQL injection. Use prepared statements for any new queries.
- No CSRF protection on forms.
- Uploaded files (images, payment proofs) are committed to git.
