# Car Rental Management System

A web system for running a car rental business: fleet, customers, reservations, rentals, payments, late interest, damages, office work and reports, plus a public online booking form. It began as a three-person team project during my 2025 software development internship at TERNET (Tanzania Education and Research Network). I then extended it on my own with most of the business logic and the security layer, and later upgraded it for production use: configurable settings for any country, a scheduled task, automatic backups and a built-in system check.

It runs fully offline (every style and script is served by the system itself), so it works both on web hosting and on an office network with XAMPP.

## How it works

```
Online booking form ──┐
                      ├──► Reservation ──► availability check ──► Pending / Confirmed ──► converted into a Rental
Staff reservation ────┘                         │
                                                └──► Waitlisted (car busy for those dates)

Rental:  Pending ──► Active (on start date) ──► Completed (after end date)
                                                    │
                     car stays "Not Returned" ◄─────┤  until staff confirm the return
                     + not-returned case log        │
                                                    └──► Payments ──► late interest per day on the unpaid cost
```

**Rules the system enforces**

- **No double-booking.** Before a rental or reservation is saved, `cr_car_unavailable_reason()` checks the car against pending and active rentals, rentals whose car has not come back yet, and confirmed reservations whose dates overlap. A car in maintenance cannot be booked for today. The car row is locked with `SELECT ... FOR UPDATE` while booking, so two staff members cannot book the same car at the same moment.
- **Rentals start and finish by date.** A rental with a future start date is *Pending* and becomes *Active* on its start date. After its end date it is marked *Completed* automatically.
- **Overdue cars are never released automatically.** When a rental ends and the car has not been returned, the car shows *Not Returned* and stays blocked for new bookings, its rental "stretching" to today. A not-returned case (Open, Followed Up, Reported, Resolved, Closed) keeps a reference for reporting to the authorities. Staff release the car by confirming the return, with mileage and notes.
- **Prices are calculated on the server.** The total is price per day × days. Loyalty discount: customers with 3 or more completed rentals get 10% off by default (both values can be changed in Settings).
- **Late interest is recorded day by day.** For every day after the end date, the system stores a charge in `rental_charges`: the unpaid rental cost at the end of that day × the daily rate (1% by default). Existing charges are never changed, so interest that was owed stays owed even after the rental cost is paid.
- **Payments can't overpay.** Partial payments are allowed, but the rental row is locked while a payment is recorded, so a double click or two cashiers can never pay more than the remaining balance.
- **Online requests are checked like staff bookings.** A request for a free car is saved as *Pending*, and one for a busy car as *Waitlisted*. Staff link it to a customer record before converting it into a rental, and the office gets an email for each new request.

## Security design

All protection lives in one file, `backend/includes/security.php`, which every page loads first. On each request it:

1. **Redirects to HTTPS** when the configured system address starts with `https://`.
2. **Starts a hardened session**: custom session name, HttpOnly and SameSite=Lax cookies, and Secure when the site runs over HTTPS.
3. **Logs out idle users** after a configurable timeout (30 minutes by default).
4. **Re-checks the user against the database.** If an account is deactivated, deleted, or has its role or password changed, its sessions end on the next click on every device (through a `session_version` counter).
5. **Sends security headers**: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` (camera, microphone and location blocked), a `Content-Security-Policy`, and HSTS over HTTPS. Logged-in pages are never cached.
6. **Checks the role for the page being opened.** A permission map links each folder or page to the roles allowed to open it. Typing a URL directly is blocked with HTTP 403, not just hidden in the menu.
7. **Protects every form against CSRF.** A 32-byte random token is stored in the session and added to every POST `<form>` through output buffering, so no form can be forgotten. Every POST is rejected unless the token matches, compared with `hash_equals` to avoid timing attacks.
8. **Protects action links** (such as delete) with a separate session token, so a link can't be triggered from another website.

**Roles**

| Role | Can use |
|---|---|
| Admin | Everything, including users, settings, reports, imports, backups and the fleet |
| Manager | Rentals, reservations, customers, payments, maintenance and office pages |
| Cashier | Rentals, reservations, customers and payments |
| Staff | Rentals, reservations and customers |
| Maintenance | Damages and service records |

**Other protections**

- **Login lockout:** 5 failed attempts lock the login for 15 minutes, counted per account and per IP address. `password_verify` always runs, so response time doesn't reveal whether an email exists. The session ID is regenerated after login, and old password hashes are upgraded automatically.
- **Password rules:** at least 10 characters (the maximum is 72, the bcrypt limit), with the same rule on every form.
- **Password reset:** a 32-byte random token is emailed and only its SHA-256 hash is stored. The link expires after 1 hour, and the reply never reveals whether the account exists.
- **Rate limits** stored in the database: password reset (5 per hour per IP, 3 per account), password change (5 per hour), backup creation (3 per hour), backup download (10 per hour), test email (5 per 10 minutes).
- **Public booking form:** a hidden honeypot field, a minimum fill time of 3 seconds, 5 requests per visitor per hour and 15 per day, 30 attempts per hour, and 100 requests per hour across all visitors.
- **File uploads:** the type is checked from the file's content (`finfo`), not its name. Files are limited to 10 MB, renamed to random names and stored outside the web folder.
- **First-run setup:** the "Create Admin Account" page only works while no active Admin exists. No default password ships with the system.
- **Prepared statements** for database queries, and every value shown on a page is escaped with `htmlspecialchars`.
- **Web server rules** stop the config, storage, cron, database and include folders being opened from a browser: `.htaccess` for Apache, `web.config` for IIS, and an nginx example.

## Database design

| Area | Tables |
|---|---|
| Fleet | `cars`, `makes`, `models`, `car_years` |
| Customers and bookings | `customers`, `reservations`, `rentals` |
| Money | `payment`, `payment_method`, `rental_charges` (late interest), `expenses`, `fuel_logs` |
| After the rental | `car_damages`, `car_service`, `not_returned_cases` |
| Office | `users`, `roles`, `staff_tasks`, `attendance`, `documents`, `notifications` |
| System | `settings`, `rate_limits` |

Foreign keys link rentals and reservations to `cars` and `customers`, payments, charges and damages to `rentals`, and users to `roles`.

The database upgrades itself: `backend/includes/schema.php` holds numbered migrations (`CR_SCHEMA_VERSION`), and they run automatically on the first page visit after an update, without deleting existing data.

## Scheduled task

`cron/run.php` runs every hour (Windows Task Scheduler through `cron/run-scheduled.bat`, or cron on Linux) and can only be started from the command line. It:

1. Updates rental and car statuses and records late interest.
2. Makes the daily database backup and deletes automatic backups older than 30 days (configurable).
3. Clears old rate-limit records.
4. Rotates the error log when it passes 10 MB.

The pages also update statuses themselves, so the system stays correct even if the task stops. **Administration > System check** (or `php cron/check.php`) warns when the task hasn't run.

## Settings

The Admin can change everything below without touching code:

- **Business:** name, logo and contact details.
- **Region:** choosing a country fills in its currency, timezone and phone code. You can also set each one separately, along with number format and currency position.
- **Rules:** loyalty discount and the number of rentals needed, late interest rate, and public booking on or off.
- **Display and backups:** rows per list, and how long automatic backups are kept.

## Project structure

```
backend/authentication/     Login, logout, first-run setup, password reset
backend/includes/           security.php (security layer), rental_status.php (rental, return and interest rules),
                            app.php (pricing helpers), schema.php (migrations), settings.php, layout.php, uploads.php
backend/reservations/       Reservations, waitlist and the public online booking form
backend/rentals/            Rentals, returns, not-returned cases, printable contract (PDF)
backend/paying/             Payments, payment history and late interest
backend/cars/               Fleet, makes, models and years
backend/customers/          Customer records
backend/maintainance_and_repair/  Damage reports and service records
backend/documents/ expenses/ fuel/ tasks/ attendance/ notifications/   Office pages
backend/report/             Analytics and PDF reports
backend/backup/ import/ settings/ users/   Admin areas (backups, CSV import, settings, user accounts)
backend/database/           database.sql (fresh install) and INSTALLATION_NOTES.txt
config/                     config.example.php (copy to config.php, not in git), nginx.conf.example
cron/                       Scheduled task and system check (blocked from the web)
storage/                    Uploads, backups and logs (blocked from the web, not in git)
vendor/                     FPDF and FPDI (PDF generation), PHPMailer (email)
```

## Tech stack

PHP 8 (mysqli) · MySQL / MariaDB · HTML · CSS · JavaScript · FPDF/FPDI · PHPMailer · Apache, IIS or nginx (XAMPP for local use)

## Setup

1. Copy the project to the web server (for XAMPP: `C:\xampp\htdocs\car_rental`).
2. Create an empty MySQL/MariaDB database and a database user, then import `backend/database/database.sql` into it.
3. Copy `config/config.example.php` to `config/config.php` and fill in the database details, the system address (`app.url`) and, optionally, SMTP details for password-reset emails.
4. Open the system in a browser. The first visit opens **Create Admin Account**, where you create the main administrator.
5. In **Settings**, choose your country and set the business details, then create staff accounts under **Users**.
6. Set up the scheduled task, then open **Administration > System check** and fix anything it marks.

The full guide, covering HTTPS, web server rules, backups, the go-live checklist and updating, is in [backend/database/INSTALLATION_NOTES.txt](backend/database/INSTALLATION_NOTES.txt).

> **Portfolio note.** This public copy contains no customer data, database backups, uploaded documents or real configuration. The PDF letterhead is a neutral placeholder.

## Author

Robert Baraka Kunenga, Computer Engineering, OSTIM Technical University · [github.com/robert6111](https://github.com/robert6111)
