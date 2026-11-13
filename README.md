

# DotCliq Studio Management System

PHP 8 + MySQL. No Composer, no SSH, no framework: upload, install, run.

## Deploy on Namecheap (cPanel)

1. **Create the database** — cPanel → *MySQL® Databases*: create a database and a user, add the user to the database with *All Privileges*. Note the full names (they carry your cPanel prefix, e.g. `cpuser_dotcliq`).
2. **PHP version** — cPanel → *Select PHP Version*: choose **8.1 or newer**. Make sure `pdo_mysql` and `mbstring` are ticked.
3. **Upload** — cPanel → *File Manager* → open `public_html` (or the sub-domain folder), *Upload* `dotcliq-studio.zip`, then *Extract*. Make sure `.htaccess` is extracted too (File Manager → Settings → *Show Hidden Files*).
4. **Install** — open `https://yourdomain/install.php`, fill in the database details and the admin account, click **Install system**.
5. **Delete `install.php`** from the server when it says installation is complete.
6. **HTTPS** — once SSL is active (cPanel → *SSL/TLS Status* → Run AutoSSL), open `.htaccess` and remove the `#` from the 3 HTTPS redirect lines.
7. Log in → **Settings** (studio details, monthly target, receipt wording) → **Packages** (replace the sample prices) → **Team & Users** (add staff).

## Roles

| Role | Can do |
|---|---|
| Administrator | Everything, including users, settings and the activity log |
| Manager | Sales, payments, clients, bookings, jobs, expenses, equipment, packages, all reports, void/delete |
| Front Desk | Log sales, record payments, clients, bookings, assign jobs. No expenses, reports or profit |
| Photographer / Videographer | Own shoots and jobs only, no money |
| Editor / Retoucher | Own editing jobs only, no money |

## How money is tracked

* **Billed** = value of sales logged (after discount).
* **Collected** = payments actually received (deposits and balances, entered manually: cash, M-Pesa, bank, card).
* **Uncollected balance** = billed − collected.
* **Net profit** = collected − expenses (cash basis).
* No payment gateway is connected: staff record each payment, with the M-Pesa code as a reference.

## Daily flow

Log sale (client, services, deposit) → optionally schedule the shoot and add it to the editing queue → record the balance when the client pays → mark the job delivered. Receipts print or save as PDF from the sale page, and can be sent via WhatsApp.

## Backups

cPanel → *Backup* → download the MySQL database regularly (or use *Backup Wizard*). The database holds everything; the code can be re-uploaded any time.

## Moving or resetting

* Database details live in `config.php` (created by the installer, never overwrite it on updates).
* To update the code later, upload the new files over the old ones but **keep `config.php`**.
* To reinstall from scratch: empty the database, delete `config.php` and `storage/installed.lock`, upload `install.php` again.

## Security notes

Passwords are hashed (bcrypt/argon), every form has CSRF protection, queries are prepared, login attempts are rate-limited, sessions time out after 4 hours idle, and every important action is written to the Activity Log. Use strong, unique passwords for each staff member and deactivate accounts when people leave.
