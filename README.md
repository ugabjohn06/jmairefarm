# J'Maire Farm Online Reservation and Management System

A web-based reservation and management system for **J'Maire Farm**, a farm resort in Brgy. Sibaguan, Lambunao, Iloilo. Guests can see which cottages and rooms are free, book online, and upload their downpayment receipt. The front desk confirms payments, records walk-ins and checks guests in and out. The owner sets rates and rules and reads income and occupancy reports.

This was built as a student project. The rates, bookings and accounts included are demo data.

**Documents:** [Demo Video (Google Drive)](https://drive.google.com/file/d/1D9RCR5dg2RLgoo5CLLTj3GF3LLRMPL8C/view?usp=sharing) · [User Manual (PDF)](J%27Maire%20Farm%20System%20User%20Manual.pdf) · [Presentation (PDF)](J%27Maire%20Farm%20System%20Presentation.pdf)

## Purpose

The system keeps every booking, payment and unit in one place. It checks availability automatically so a cottage or room is never promised twice, shows the front desk exactly which downpayments still need checking, and gives the owner an up-to-date view of income and occupancy.

## Features

**For guests (public website)**
- Browse day cottages and overnight rooms with photos, capacity and rates, and check which dates are free
- Book online with a live price summary (unit, entrance fees, swimming, add-ons and food pre-orders)
- Pay a 50% downpayment by GCash or bank transfer and upload the receipt
- View, cancel and print bookings from "My bookings"
- Look up a walk-in or phone booking with the booking code and mobile number

**For front desk staff**
- Dashboard with today's arrivals, departures and receipts waiting for review
- Approve or reject payment receipts
- Create walk-in bookings, check guests in and out, and record the balance paid
- Reschedule, cancel or reject bookings, with a reason recorded
- Calendar of every unit, guest history and a log of all emails sent

**For the owner (admin)**
- Reports on income, bookings and occupancy by month, unit and payment method, with CSV export
- Manage cottages and rooms (photos, rates, capacity), add-ons with daily stock, and the farm menu
- Settings for contact details, downpayment percentage, payment deadline, fees and payment accounts
- Staff accounts and roles, and an audit log of every important action

## How it works

- **No double booking.** A unit can have only one active booking for any day. The availability check and the save happen together, so two guests clicking "Book" at the same moment cannot both get the same date.
- **Prices are calculated on the server.** The price summary on the form and the saved total come from the same calculation. Each booking keeps a copy of its prices, so later rate changes don't affect bookings already made.
- **Booking status.** Pending → Confirmed (downpayment verified) → Checked in → Completed (fully paid). A pending booking that gets no receipt within the payment deadline (24 hours by default) expires and frees the date.
- **Security.** Passwords are hashed, every form is protected against cross-site request forgery, all database queries use prepared statements, and uploaded receipts are checked by file content and can only be viewed by staff or the guest who uploaded them.

## Built with

- PHP 8.2 and MySQL / MariaDB (XAMPP)
- Bootstrap 5 and Bootstrap Icons
- Chart.js (reports) and FullCalendar (calendar)
- Flatpickr (date picker) and the Figtree font from Google Fonts

## Running it locally

**Requirements:** Windows with [XAMPP](https://www.apachefriends.org/) (PHP 8.2) and a modern browser. An internet connection is needed only for the font and the date picker.

1. Clone the repository into XAMPP's `htdocs` folder:
   ```
   cd C:\xampp\htdocs
   git clone https://github.com/ugabjohn06/jmairefarm.git
   ```
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Go to http://localhost/phpmyadmin, open the **Import** tab, and import these two files in order:
   1. `database/01_jmaire_farm_db.sql`
   2. `database/02_reservation_system.sql`
4. Open http://localhost/jmairefarm/

If MySQL on your computer has a password, copy `config.local.example.php` to `config.local.php` and set `DB_PASS` there.

**Optional sample data:** to fill the reports with a full year of 2025 bookings, run this from the project folder:
```
C:\xampp\php\php.exe -d extension=gd database\seed_demo_2025.php
```
Add `--remove` to take the sample data out again.

### Demo accounts

| Role | Log in with | Password |
|---|---|---|
| Owner (admin) | `admin` | `admin123` |
| Front desk (staff) | `staff` | `staff123` |
| Guest | `guest@example.com` | `guest123` |

Staff and the owner sign in through the same login page and are taken to the admin panel.

## Project structure

| Folder / file | Contents |
|---|---|
| `index.php`, `accommodations.php`, `menu.php` | Public website |
| `book.php`, `booking.php`, `my_bookings.php`, `find_booking.php` | Guest booking, payment and booking history |
| `admin/` | Staff and owner panel |
| `includes/booking_service.php` | Booking rules: availability, pricing, status changes, payments |
| `api/` | Live price and availability lookups used by the booking form |
| `database/` | SQL files and the optional sample-data script |
| `assets/` | Styles, scripts, images and bundled libraries |
| `uploads/` | Unit photos and uploaded receipts |

## Development team

- Brent Dela Cruz
- James Dian Bornales
- Ethan Tyler Razalan
- Vin Rechnell Sandig
- Gabriel John Uy
