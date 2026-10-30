# Car Rental Management System

Back-office system for a car rental business, with a public booking form.
Plain PHP 8.0+ and MySQL / MariaDB. It needs no internet connection: every
style and script is served by the system itself, so it runs both on web
hosting and on an office network (XAMPP).

Installation, HTTPS and web server rules, the scheduled task, backups, the go-live
checklist and updating: see
[backend/database/INSTALLATION_NOTES.txt](backend/database/INSTALLATION_NOTES.txt).
Before going live, open **Administration > System check** (or run
`php cron/check.php`): it tests the server, configuration, HTTPS, that private
folders are refused by the web server, the database, the scheduled task and backups.

## What it does

- **Rentals**: price per day, loyalty discount and conflict checks are worked out
  by the server. Rentals start and finish by themselves on their dates.
- **Overdue cars are never released automatically**. When a rental ends and the
  car has not come back, the rental is completed but the car shows *Not Returned*
  until staff confirm the return. A not-returned case log keeps a reference for
  reporting to the authorities.
- **Payments**: partial payments, payment methods and history per rental.
  Late interest (default 1% per day of the unpaid rental cost) is recorded day by
  day and stays owed once charged.
- **Reservations**: waitlist, conversion into rentals, and online booking requests
  from the public page (spam and abuse protection, rate limits). Online requests
  are linked to a customer by staff before conversion.
- **Fleet**: cars, makes/models/years, damages, services, fuel logs, documents
  (insurance, inspection) with expiry alerts.
- **Office**: expenses, tasks, staff attendance, notifications (email / WhatsApp
  links).
- **Reports**: rentals, payments and analytics, with PDF export.
- **Administration**: users with roles (Admin, Manager, Staff, Cashier,
  Maintenance), CSV data import, backups, settings.

## Settings (Settings page)

Business name, logo and contact details; currency, number format, timezone and
phone country code (any country); late interest rate; loyalty discount;
public booking on/off; rows per list; how long automatic backups are kept.

## Folders

| Folder | Contents |
|---|---|
| `backend/` | The application (one folder per module) |
| `backend/includes/` | Shared code: security, database, settings, layout, rental rules |
| `backend/database/` | `database.sql` (fresh install) and installation notes |
| `config/` | `config.php` with database and mail details (not in git); `nginx.conf.example` |
| `cron/` | Scheduled task (`run.php`, `run-scheduled.bat`) and `check.php`, blocked from the web |
| `storage/` | Uploaded documents, backups and logs, blocked from the web (not in git) |
| `vendor/` | FPDF / FPDI (PDF reports) and PHPMailer |

## Security in short

Sessions with idle timeout, login throttling, CSRF protection on every form,
signed links for actions, role permissions per page, prepared SQL statements,
uploads stored outside the web folder, security headers, and automatic redirect
to HTTPS when the system address is https://. Web server rules are included for
Apache (`.htaccess`) and IIS (`web.config`), with an example for nginx. Use HTTPS on any
system reachable from the internet.

## Developer notes

- Database changes: bump `CR_SCHEMA_VERSION` in `backend/includes/schema.php` and
  add the migration there; update `database.sql` to match.
- Pages use `cr_layout_start()` / `cr_layout_end()` (`backend/includes/layout.php`);
  styles live in `backend/includes/css/app.css`. The top menu is defined in
  `cr_nav_items()`. The interface uses text labels only (no icons).
- Rental, return and interest rules live in `backend/includes/rental_status.php`.
