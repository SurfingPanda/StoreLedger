# Groundwork

A workspace for rental payments, electricity and water billing, and UTAK POS renewals. This first phase implements authentication. The signed-in workspace identifies the four planned modules; monitoring and billing workflows will follow.

## Stack

- Laravel 12 and MySQL (compatible with XAMPP PHP 8.2)
- React 19, TypeScript, and Vite
- Tailwind CSS 4 and locally owned shadcn/ui components built on Radix primitives

The design uses warm ivory, muted rose, serif accents, and an original storefront illustration. Fonts and artwork are local; the page makes no external font or image requests.

## Run locally

Dependencies, the application key, production assets, and the local `groundwork_monitoring` database have been prepared in this workspace.

1. Start MySQL in XAMPP.
2. Create your account: `php artisan app:create-user`. The command prompts for a name, email, and password. There is no shared default password or public registration.
3. Start Laravel: `composer dev`.
4. Open http://127.0.0.1:8060/login.

Alternatively, serve the existing production assets with `php -S 127.0.0.1:8060 -t public`. For Apache hosting, point the virtual host's document root at this project's `public` directory.

For frontend development, run `npm run dev` in a second terminal while Laravel runs. Rebuild production assets with `npm run build`.

## Fresh installation

Requires PHP 8.2+, Composer, Node.js 20.19+, and MySQL. Enable PHP's PDO MySQL extension; PDO SQLite is used for isolated tests.

1. Run `composer install` and `npm ci`.
2. Copy `.env.example` to `.env` and set your MySQL credentials and `APP_URL`.
3. Create the empty database named in `DB_DATABASE` (default: `groundwork_monitoring`).
4. Run `php artisan key:generate` and `php artisan migrate`.
5. Run `npm run build`, then provision an account with `php artisan app:create-user`.
6. Run `composer dev`.

## Authentication

- Laravel session authentication with session regeneration and CSRF protection.
- Encrypted database sessions and optional persistent sign-in.
- Validation, password visibility, caps-lock feedback, pending states, and useful request errors.
- Rate limits on sign-in and password recovery.
- Password reset links with generic email responses, token expiry, one-time use, and confirmed passwords of at least 12 characters.
- Authenticated workspace and session-invalidating logout.
- Accessible labels, keyboard focus, Radix dialog/checkbox behavior, and responsive layouts.

`MAIL_MAILER=log` is the development default. Reset links are written to `storage/logs/laravel.log`; they are not delivered to an inbox until you configure SMTP or another mail transport in `.env`. Keep this log private.

## Checks

- `php artisan test` runs the authentication suite using an in-memory SQLite database, leaving MySQL data intact.
- `npm run typecheck` checks TypeScript.
- `npm run build` checks TypeScript and builds the Vite assets.

The implementation was checked with the authentication suite and a live HTTP round trip against MySQL, including assets, CSRF-protected sign-in, a remembered session, the protected workspace, and logout. Temporary test accounts were removed.

In this managed Windows environment, Vite's subprocess and Chrome launch were blocked by the sandbox. Production assets were generated using the installed esbuild CLI and Tailwind compiler. A visual browser check remains outstanding. Temporary offline-install and verification helpers live in ignored `.local/`; normal dependency manifests and lock files use public package registries.

## Main files

- `resources/js/pages/auth.tsx` — login and password recovery screens
- `resources/js/components/ui/` — shadcn/ui components
- `resources/css/app.css` — theme and responsive styling
- `app/Http/Controllers/AuthController.php` — session and reset flows
- `app/Console/Commands/CreateUser.php` — account provisioning
- `routes/web.php` — public and authenticated routes
- `tests/Feature/AuthenticationTest.php` — authentication coverage

## Admin dashboard preview

The admin workspace now has a responsive sidebar for Dashboard, Rental, Electricity, Water, and UTAK POS. Each module filters the dashboard view. The dashboard includes outstanding amounts, Accounting and Finance queues, overdue dues, a searchable voucher register, voucher detail timelines, upcoming dues, and category totals. The date-range selector filters by due date.

The dashboard opens with explicitly labeled illustrative records. Use **Show empty workspace** to see the initial empty state. Sample records are held in React and do not create database records or initiate payments. RSO notifications are previews of the planned voucher-processed and payment-completed alerts.

This phase implements the admin interface. IT, Accounting, Finance, and RSO are represented as workflow participants; role permissions, live voucher processing, payment recording, and notification delivery will be implemented with the respective modules.

## BW Superbakeshop theme

The app now follows the supplied BW reference: royal blue, orange, white cards, a dotted login backdrop, and a cream wave footer. The login uses the original `public/images/Full Moymoy 2.png` without altering it. Asset URLs are generated by Laravel so the mascot also loads when the app is served from a subdirectory. The official `public/images/logo.png` is used for the login, sidebar, and browser icon. The same colors are applied to the admin navigation, summaries, workflow, tables, dialogs, and notification preview.


Login motion uses two seamless footer wave layers, a gentle six-second mascot float with a matching shadow, and brief entrance transitions. All decorative animations stop when the device requests reduced motion.

## Live UTAK POS renewal records

UTAK POS now opens the dedicated `/utak-pos` page. Admins can add a Store Name, Renewal Date, and Amount (PHP). Records are persisted in MySQL with a decimal amount, the creating user's ID, and the server-controlled `awaiting_voucher` status. The list supports store search and 15-record pages, ordered by renewal date. Sample dashboard records are separate from this live list.

The migration assigns existing workspace accounts the admin role; future users default to IT. The account-provisioning command explicitly creates admins. IT, Accounting, Finance, and RSO accounts cannot create renewal records through the admin endpoint. Voucher processing, payment recording, and notification delivery remain the next workflow stages.

Run `php artisan migrate` when installing this update on another database. The renewal and authentication suites run with `php artisan test`.

## Accounting queue and renewal reminder emails

Accounting users are redirected to UTAK POS after login and see newly created records with `awaiting_voucher` status. They cannot add admin renewal records. Create an Accounting login using:

```powershell
php artisan app:create-user --role=accounting
```

The registered email entered in this command is the reminder recipient. Every current Accounting user receives an individual email for each eligible renewal.

### Hostinger SMTP activation

Hostinger Email documents `smtp.hostinger.com`, port 465, SSL: https://www.hostinger.com/support/1575756-how-to-get-email-account-configuration-details-for-hostinger-email/

Host, port, and scheme are preset in `.env`. Complete these values locally; do not commit mailbox credentials:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=your-mailbox@your-domain.com
MAIL_PASSWORD="your-mailbox-password"
MAIL_FROM_ADDRESS="your-mailbox@your-domain.com"
MAIL_FROM_NAME="BW Superbakeshop"
```

Use the mailbox password, not the Hostinger account password. Set `APP_URL` to the address Accounting users can actually open. Run `php artisan config:clear` after configuration. Until SMTP credentials are supplied, this checkout keeps `MAIL_MAILER=log`; the reminder command refuses to consume reminders through the log/array transports. Real inbox delivery has not been verified.

### Timing and automation

Laravel schedules `pos:send-renewal-reminders` daily at 09:00 Asia/Manila (UTC+8). It selects pending records due in 15 calendar days. To cover newly entered short-notice records or a missed run, it also picks up unsent pending records from today through the next 15 days; it does not send overdue or already-vouchered reminders. The email includes Store Name, Renewal Date, Amount, record reference, and a sign-in-protected link to the queue.

The ledger records successful SMTP submissions per renewal/date/Accounting user/email. Re-running the command skips successfully submitted mail; failed mail remains eligible for retry. SMTP submission does not guarantee inbox delivery. As with SMTP generally, a process crash after acceptance but before the database commit can lead to a retry.

The Laravel schedule needs an operating-system runner. Windows blocked automatic task registration in this sandbox. To install the local daily task from a normal PowerShell session:

```powershell
.\scripts\install-renewal-schedule.ps1
```

This local task requires MySQL, the computer running, and that Windows user logged in; its 09:00 trigger follows Windows local time (use UTC+8). It checks for an existing task rather than overwriting it.

For a server running continuously, configure its scheduler/cron to invoke the Laravel scheduler every minute, using that server's actual PHP binary and project path:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /path/to/project/storage/logs/scheduler.log 2>&1
```

Use either the Windows daily task or Laravel's server schedule, not both. You can manually run `php artisan pos:send-renewal-reminders` after setup; this sends real reminder emails to eligible Accounting accounts. The implementation tests use fake mail and never send to real recipients.

## Sidebar and notification recipient settings

Use the panel icon beside the top breadcrumb to minimize or expand the desktop sidebar. The choice persists in local browser storage across pages; the mobile navigation remains a separate drawer. Minimized items retain accessible labels and hover titles.

Admin accounts have a **Settings** sidebar item at `/settings`. The page saves independent preferences for Accounting voucher reminders, Finance payment reminders, RSO voucher-processed updates, and RSO payment-processed updates. Each supports registered team addresses (the default), a custom list of up to 20 email addresses, or notifications off. Custom addresses replace role recipients for that event. Settings are stored in MySQL and protected on both page and API routes.

The existing UTAK Accounting reminder command reads these preferences. Its delivery ledger deduplicates by renewal/date/email even when the recipient switches between a role account and a custom address. No mail is sent just by saving settings. SMTP credentials and the OS scheduler must still be enabled as described above.

Finance and RSO recipient preferences are ready for their future workflow handlers; their UI explicitly marks the delivery triggers as pending. This update does not yet implement voucher/payment transitions or send those workflow emails. Settings do not grant a mailbox holder app access; accounts are provisioned separately.
