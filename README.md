# J'Maire Farm Reservation System

Online booking for J'Maire Farm: guests pick a cottage or room, see the full price, book, and upload their GCash/bank receipt. Staff verify receipts, handle walk-ins and check guests in and out. The owner manages rates and sees income and occupancy reports.

Built with plain **PHP 8.2 + MySQL (XAMPP)** and Bootstrap 5, with Chart.js and FullCalendar for the reports and calendar. Bootstrap, its icons, Chart.js and FullCalendar are included in `assets/`. Only the Figtree font (Google Fonts) and the Flatpickr date picker (CDN) load from the internet; without a connection the site still works with the system font and plain date fields.

## Setup (XAMPP)

1. Clone or copy this repository into `C:\xampp\htdocs\`. Any folder name works; this guide uses `j-maire_farm_rsrv_sys`:
   ```
   cd C:\xampp\htdocs
   git clone <repository-url> j-maire_farm_rsrv_sys
   ```
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open http://localhost/phpmyadmin and **Import** these two files, in this order:
   1. `database/01_jmaire_farm_db.sql` (creates the `jmaire_farm` database and the menu: food, rooms & cottages, activities)
   2. `database/02_reservation_system.sql` (users, accommodations, bookings, payments, settings + demo data)

   If a `jmaire_farm` database already exists from an earlier version, drop it first; these files create their tables from scratch. (`03_admin_controls.sql` is only for upgrading an old database in place; fresh installs don't need it.)
4. Open http://localhost/j-maire_farm_rsrv_sys/ (use your folder name).

The unit photos ship in `uploads/accommodations/`, so cottages and rooms show their pictures straight away.

**Optional: a year of sample data.** To fill the reports and dashboards with 2025 bookings, payments and guests, run this once from the project folder (and `--remove` to take it out again):
```
C:\xampp\php\php.exe -d extension=gd database\seed_demo_2025.php
C:\xampp\php\php.exe database\seed_demo_2025.php --remove
```

### Local settings

`config.php` holds defaults that work on a standard XAMPP install (MySQL user `root`, no password, database `jmaire_farm`). To change something on your computer only, such as a MySQL password, copy `config.local.example.php` to `config.local.php` and set it there. `config.local.php` is ignored by git.

### Demo accounts

| Role | Login | Password |
|---|---|---|
| Admin / owner | `admin` | `admin123` |
| Staff | `staff` | `staff123` |
| Guest | `guest@example.com` | `guest123` |
| Guest | `maria@example.com` | `guest123` |

Change these before showing the system to anyone outside the team. Demo booking dates are relative to the day you import, so the dashboard always has something on it.

## What's where

| Path | What it is |
|---|---|
| `index.php`, `accommodations.php`, `accommodation.php`, `menu.php` | Public pages |
| `book.php` → `booking.php` | Booking form → summary, payment instructions, receipt upload, cancel |
| `my_bookings.php`, `find_booking.php` | Guest's bookings; lookup by code + phone (for walk-ins without an account) |
| `login.php`, `register.php`, `forgot_password.php`, `reset_password.php`, `profile.php` | Accounts |
| `receipt.php` | Printable receipt (Print → Save as PDF) |
| `admin/` | Staff/owner side: dashboard, bookings, walk-in, calendar, guests, reports, accommodations, add-ons, farm menu, settings, users, audit log, email log |
| `includes/booking_service.php` | **All booking rules**: overlap check, price calculation, status flow, expiry, payments |
| `includes/booking_form.php` + `assets/js/booking.js` | Shared booking form (guest + walk-in) with live price preview |
| `api/quote.php`, `api/availability.php` | JSON used by the form and calendars |
| `cron/expire_bookings.php` | Optional scheduled expiry (see below) |

The old root files `dashboard.php`, `menu_list.php`, `menu_edit.php`, `menu_delete.php` now just redirect to `admin/`, so old bookmarks keep working. You can delete them if nobody uses those addresses.

## How the important parts work (plan section 8)

**No double booking.** Dates are stored as a half-open range `[check_in, check_out)`. Overnight rooms: `check_out` is the morning the guest leaves, so one guest can check out the same day the next checks in. Day cottages: `check_out = check_in + 1 day`. Two bookings clash when `a.check_in < b.check_out AND a.check_out > b.check_in`, counting only `pending`, `confirmed` and `checked_in`. `create_booking()` runs the check and the insert in one transaction after `SELECT ... FOR UPDATE` on the accommodation row, so two people clicking Book at the same moment are handled one after the other.

**Price.** `calculate_quote()` computes rate × days/nights, entrance fees (adults/kids) for day cottages, optional swimming, add-ons and food pre-orders. Overnight rooms include entrance, swimming and breakfast. The form's live price calls the same function through `api/quote.php`, so the preview always equals the saved total. Every line is copied into `booking_items`, so later rate changes never alter old bookings.

**Status flow.** pending → confirmed (when verified payments ≥ downpayment) → checked_in → completed (only when fully paid). Pending can also become cancelled, rejected or expired; confirmed can be cancelled. Cancel and reject require a reason.

**Expiry.** Unpaid pending bookings expire after `payment_deadline_hours` (Settings, default 24h) and free their dates. This runs on every page load. Bookings whose receipt is waiting for review don't expire. Rejecting a receipt gives the guest a fresh window to re-upload. For a scheduled job, use Windows Task Scheduler every 15 minutes with program `C:\xampp\php\php.exe` and argument `C:\xampp\htdocs\j-maire_farm_rsrv_sys\cron\expire_bookings.php` (use your folder name).

**Receipts.** Only JPG/PNG/PDF up to 5 MB are accepted. The type is checked from the file content, not the extension. Files get random names in `uploads/receipts/`, which `.htaccess` blocks. They are served only through `receipt_file.php` to staff or the booking's owner.

**Access.** Guests see only their own bookings. `/admin` needs staff. Rates, settings, users, reports and the audit log need admin. Every form has a CSRF token, all SQL uses prepared statements, and output is escaped with `e()`.

**Email.** XAMPP can't send email out of the box, so every email (booking received, confirmed, receipt rejected, cancelled, expired, password reset) is saved to **Admin → Email log**, which is handy for the demo. Set `MAIL_ENABLED` to `true` in `config.local.php` once SMTP is configured.

## Test checklist status (plan section 13)

All of these were run end to end against the demo data: overlap blocked, past date / end-before-start / over-capacity rejected, same-day turnover allowed, server total correct, txt / fake-PNG / oversized receipts rejected, approve → confirmed + email, reject requires a reason and the guest sees it, unpaid booking auto-expires and frees dates, guest can't open another guest's booking or `/admin`, staff can't open users or reports, walk-in + check-out with balance, reschedule recalculates price, CSV export. Still to do on your XAMPP: the **two browsers at the same time** test (needs real MySQL), plus checking that `http://localhost/<your-folder>/uploads/receipts/demo_receipt.png` returns *403 Forbidden*.

## Design

The look ("Seed Packet") lives in `assets/css/app.css`: a forest-green field taken from the logo, white work surfaces on a light-grey ground, and one type family, Figtree. Sun orange is kept for things that need action now (the "Book a visit" button, receipts to check, Review buttons). The logo files in `assets/img/` (`logo-green.png`, `logo-white.png`, `logo-mark.png`) are trimmed copies of the originals in `photos/`. Motion is limited to the home-page hero and a slight photo zoom on hover, and both turn off when the visitor's system asks for reduced motion.
