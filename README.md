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


## Automatic notifications and recurring billing

Processing a voucher captures separate Finance payment-request and RSO voucher-update emails. Recording payment captures the RSO payment-update email. Recipients come from Settings, including custom recipients and notifications-off mode. The scheduler delivers captured messages each minute, tracks delivery, and retries failures without undoing Accounting or Finance actions. Finance prompts are skipped if payment is already complete. Existing requests are not backfilled with email events.

Admins can open any request and use **Recurring billing** to choose monthly, quarterly, half-yearly, or yearly billing, the expected amount, and how many days before the due date to create each new request. Schedules can be paused or resumed. Generation runs hourly. The original due date anchors the cycle so month-end dates do not drift when February is shorter. Each cycle is uniquely identified and will not be generated twice. Missed past cycles are generated too, at most twelve per schedule per run.

Generated UTAK POS requests enter the pending voucher queue. Generated SOA requests start as **Awaiting SOA** with no attachment copied from the preceding month. An admin opens **Attach SOA**, uploads the current statement, and confirms the actual amount before Accounting can process the voucher. Existing records and previous statements are unchanged.

For local Windows automation, run `powershell -ExecutionPolicy Bypass -File scripts/install-automation-schedule.ps1` once from a normal terminal. The task runs `php artisan schedule:run` every minute while the configured Windows user is logged in; MySQL and the network must be available. For a hosted installation, configure the host scheduler to run `php artisan schedule:run` once per minute. No separate queue worker is needed for workflow mail.

Manual commands: `php artisan billing:generate-recurring` and `php artisan notifications:deliver-workflow`. These perform real actions; do not run the delivery command with live credentials unless sending pending emails is intended. RSO recipients must exist or be configured as custom addresses before RSO emails can be captured. Configure `APP_URL` to the deployed application URL so email links work outside the local computer.


## Account management

Admins can open **Users & access** from the sidebar or account menu to create accounts, edit names/emails/roles, reset passwords, and disable or enable accounts. The page supports name/email search, role/status filters, summary counts, and pagination. New or reset passwords require at least 12 characters; share those credentials securely with the user. Existing passwords remain valid until changed.

Disabling an account retains its identity and history, blocks login and password recovery, revokes access, and excludes the account from registered-team reminder recipients. Explicit custom notification addresses remain controlled by notification settings. Sensitive account changes invalidate existing sessions, remembered logins, and outstanding password-reset tokens. Admins cannot disable their own account, change their own role, or remove the last active admin. Self email/password changes require signing in again. Last sign-in tracking begins with the next successful login after this update.


### Duplicate bill detection

All five billing modules check the store, provider/landlord, and billing month before creating a request, processing a voucher, or recording payment. Matching ignores case and repeated whitespace, ignores the amount and due day, includes paid requests, and excludes cancelled requests. Modules are checked separately. The entry forms require provider and billing month; older records without a billing month use their due-date month, and a missing provider is treated as a possible match. API clients may omit these fields for backwards compatibility, in which case the same conservative fallback applies.

The queues flag possible duplicates, and request details and rejected submissions link to matching records. Admins can correct provider/month details on unpaid requests; changes are recorded as comments. Accounting or an admin can cancel an unpaid, already-vouchered duplicate from its details page, retaining its original voucher information and cancellation timestamp. Normal duplicate requests awaiting a voucher can also be cancelled using the existing Accounting action. Paid records cannot be cancelled or relabelled through these actions.

A shared database lock serializes creation, voucher, payment, and duplicate-resolution checks. Recurring schedules inherit their source provider and billing-month offset and skip cycles already entered manually. No existing requests are automatically deleted, changed, or cancelled.


### Store and supplier directory

Open **Stores & suppliers** in the sidebar. All signed-in teams can view the directory; admins can add/edit stores, suppliers and billing accounts or mark them inactive. Store IDs are unique business codes backed by permanent numeric database IDs. A store can have an active RSO user assigned; supplier contacts and per-module account numbers and billing cycles (1, 3, 6 or 12 months) are maintained separately. A used billing account cannot move between stores, suppliers or modules; create another account instead. Account-number changes apply to future bills and do not rewrite the numbers recorded on old bills.

The migration links existing bills by normalized store name and supplier name, generates store codes, and imports known supplier accounts without guessing missing account numbers, cycles or RSO assignments. Review these entries and complete their setup. New bill forms select saved store accounts. Legacy API requests using names remain supported; ambiguous store names require an explicit saved account. Inactive stores, suppliers or accounts cannot be used on new bills and their recurring schedules wait without advancing.

Store history uses store IDs, preserving continuity across name changes and keeping separate same-named stores apart. Existing bill names remain historical snapshots. Duplicate checks use linked store/supplier/account identity, falling back conservatively for missing old details. Saved billing cycles prefill the recurring-schedule form; schedules still require explicit activation. RSO assignment is shown in bills and the directory; email delivery continues to follow the notification recipient settings.


### Returns, corrections and reopening

Each request details page supports a controlled revision workflow:

- Accounting can return pending vouchers to the requester/admin, with a required reason.
- Finance can return a processed voucher to Accounting for a voucher-reference correction, or to the requester/admin for bill corrections.
- Returned requests use **Needs revision**, leave processing/payment queues, and remain visible through list and dashboard status filters. Accounting can escalate a Finance return to the requester/admin when bill details need changing.
- The requester or an admin can correct bill/account details, due date, amount, billing month and the SOA, then provide a correction note and resubmit. The previous voucher is invalidated and Accounting approval is required again. Accounting can correct only the voucher reference on an Accounting-assigned return and resubmit it to Finance.
- Admins may reopen unpaid cancelled requests with a reason; they enter revision and require Accounting approval again. Paid requests cannot be reopened, returned or edited through these actions. The metadata shortcut is restricted to requests still awaiting a statement or voucher.

The correction history records actors, times, reasons and before/after values. Replaced SOAs are retained and downloadable from their revision event. Revisions never change the original creator. Permission and state checks, a shared database lock, and request versions prevent unauthorized transitions or stale submissions. Browser voucher/payment actions send the current version; older API clients may omit it only before the first revision cycle. Revision actions always require a version.

Workflow emails are keyed by review cycle so a corrected voucher can notify Finance/RSO again. Jobs from superseded cycles are suppressed. No historical requests are automatically returned or reopened by the migration.


### Automation health

Admins can open **Automation health** in the sidebar. Monitoring starts when its migration is applied; earlier command runs are not backfilled. A scheduled heartbeat runs every minute and reports whether the application has recently received a scheduler tick. Manual command execution records a run but does not update the scheduler heartbeat. No tick is reported as **Not observed**, a delay beyond three minutes as **Delayed**, and beyond ten minutes as **No recent heartbeat**.

The page shows the last run/success, expected cadence, metrics and errors for the three daily Accounting reminder commands, minute-by-minute workflow email delivery, and hourly recurring generation. All times on the page use Manila time. Task runs include both scheduled and manual console executions. Long-running entries are flagged using each task's overlap timeout plus a five-minute margin; this indicates a possible stalled run rather than proving that its process is still alive.

Accounting reminder failures are persisted separately because a failed SMTP transaction does not create a delivered reminder row. Workflow failures retain their attempts and next retry time in the delivery queue. Recurring failures identify the schedule/source request and do not stop the remaining schedules from generating. Successful retries resolve matching issues. Inactive linked accounts/stores/providers block recurring generation without advancing those schedules; their count is shown separately from schedules ready for generation.

**Queue retry now** is admin-only and brings a waiting failed workflow email forward to the next delivery run. It does not send from the web request, reset attempt counts, or override recipient preferences, request status, or revision-cycle checks. Retry requests record the acting admin. Accounting reminders continue to retry on the next daily reminder run while eligible. Mail-server acceptance is not a guarantee of inbox delivery. The health API exposes sanitized messages, never SMTP passwords, raw exception output, or the existing delivery-error field.

The page refreshes every 30 seconds while visible. The server must still run `php artisan schedule:run` every minute. On Windows, the existing setup script is `scripts/install-automation-schedule.ps1`; it requires MySQL, network access and its Windows user to remain logged in. Viewing the page does not install or activate that task.


### Security audit log

Admins can open **Security audit log** in the sidebar to filter events by category, event, outcome, actor/email/IP/target or request correlation ID, and Manila date range. Each entry preserves the actor name/email/role at the time, timestamp, server-resolved client IP, bounded client-reported user agent, route template, target, and approved before/after values. The inspector and API are read-only; no update/delete endpoints are provided. Eloquent model updates/deletes are also rejected for audit entries. Database administrators can still alter the database: this is application-level append-only protection, not an externally signed or tamper-proof log.

Coverage includes successful/failed/throttled sign-ins, logout, password recovery requests/results, admin user creation/role/email/status/password changes, notification-recipient changes, directory and recurring-schedule configuration, bill changes and revision transitions, voucher processing, payment recording, email retry requests, blocked access, and authenticated private attachment download requests (including historical statements). Download events confirm the server accepted a download request, not that the client received every byte. Opening the audit page is recorded; reading/filtering its records does not recursively add viewing entries. The existing `app:create-user` console command records account provisioning with a console/system actor and no invented client IP.

Mutation audit entries participate in their existing database transactions; rolled-back vouchers/payments/account edits do not leave successful change entries. Authentication and access events are recorded independently. Logging uses explicit field allowlists, with no full request payload, password/hash, reset token, remember token, authorization header, cookie, private attachment path, payment notes, or SMTP credential capture. Route templates are used instead of token-bearing URLs. IPs and browser information are evidence, not proof of a person's identity.

Monitoring starts with this update. Historical events are not fabricated. Direct SQL, ad-hoc console scripts, and uninstrumented external changes are outside this audit scope; scheduled automation already has its separate run/issue monitoring. Include audit tables in protected backups and establish a retention policy appropriate for the business.


### RSO SOA submissions

Only active **RSO** and **Admin** accounts can submit Rental, Electricity and Water SOAs or attach the current statement to those modules' recurring requests. The forms and server endpoints enforce the same restriction. Accounting, Finance and IT cannot create these SOAs; their existing review/payment responsibilities are unchanged. Internet SOA and UTAK POS creation remain admin-only. Returned bill corrections retain the requester/admin ownership rule and also require a currently eligible submission role; a former requester who loses that role cannot bypass it through resubmission. Directory editing, recurring-schedule configuration and reopening cancelled records remain admin-only.


### Keep me signed in

The login checkbox uses a native checked/unchecked form control and sends an explicit boolean. Checked sign-ins issue Laravel's encrypted, HTTP-only remember cookie with a default 30-day lifetime (`AUTH_REMEMBER_MINUTES=43200`). A remembered cookie can restore access after the ordinary session expires or the browser is reopened, including for users whose account session version has advanced. Passwords are not stored in browser storage. Browser privacy settings must permit the site's cookies.

Unchecked sign-ins explicitly expire an older remember cookie. Signing out, disabling an account, changing its password, or changing security-sensitive account details continues to revoke remembered access. Normal session expiration follows `SESSION_EXPIRE_ON_CLOSE` and `SESSION_LIFETIME`; browsers that restore session cookies can retain ordinary sessions according to their own settings.


### Attachment previews

Saved SOAs, payment proofs and earlier SOA versions open in an in-app preview dialog. PDFs use the browser's built-in PDF viewer; PNG/JPEG images have zoom and fit controls. Download remains available, with an authenticated **Open in new tab** option for browsers with limited embedded PDF support. Missing files, expired sessions and unsupported formats show a readable error and retry/download options.

Previews use the existing authenticated, module-scoped file endpoints with `?preview=1`. Stored bytes determine the content type; only PDF, PNG and JPEG may be served inline. Other files remain downloadable but cannot be rendered inline through this feature. Responses are private/no-store, disable MIME sniffing, and restrict framing to the same origin. No public storage URL is introduced. Opening a preview records `attachment.preview_requested` in the security audit log; downloads retain their separate audit event. The browser fetches the file into memory and releases its object URL when the dialog closes. No third-party preview service receives the files.

## Shared corrections, overdue follow-up, and voucher controls

RSOs can correct returned Water, Electricity, and Rental requests across all stores, including requests created by Admin or another RSO. POS and Internet bill corrections remain with Admin; voucher corrections remain with Accounting.

Finance payment dates are validated against today's date in Asia/Manila. Processing a voucher requires a voucher reference. Cancelling a request, including a duplicate, requires a cancellation reason retained in the record and history.

Returned/reopened bills notify the eligible Admin/RSO team or Accounting. Corrected bills notify Accounting; corrected vouchers notify Finance through the existing workflow. Revision emails use the existing minute-by-minute delivery command, respect recipient settings, and skip outdated workflow stages and review cycles.

The scheduler runs billing:send-overdue-reminders daily at 9:00 AM Asia/Manila. Overdue requests awaiting vouchers or Accounting corrections notify Accounting; those awaiting payment notify Finance; those awaiting SOAs or requester corrections notify the eligible Admin/RSO team. New recipient settings support registered teams, custom addresses, or notifications off. A delivery ledger deduplicates each bill, review cycle, stage, recipient, and Philippine calendar day. Failed sends remain retryable; paid/cancelled requests and requests with recorded payments are excluded. SMTP acceptance does not guarantee inbox delivery.

Run php artisan migrate --force and rebuild frontend assets when deploying. The existing every-minute schedule:run cron job also runs the new daily task; no additional cron entry is needed.
