# VSahha Provider Portal — Laravel edition

The portal hospitals, clinics, pharmacies and laboratories use to work with VSahha: check cover,
send claims and pre-authorization requests, follow them to payment, appeal, and message VSahha.
It is the React "VSahha Provider Portal" rebuilt as a server-rendered **Laravel 10** application:
Blade views, plain forms and a small script in `public/js`.

It is a separate application with its own database. It never reads the CRM's database: everything
about a claim, a decision or a payment is read from the CRM on each request.

```
Portal (port 8001, database vsahha_portal)            CRM (port 8000, database vsahha_crm)
   requests  ── /v1, OAuth2 client credentials ──────▶  routes/integration.php
   /webhooks/crm  ◀── signed events ────────────────    integration:webhooks
```

## Run it

Requirements: PHP 8.1+ with `intl` and `gd`, Composer, MySQL 8 (Laragon provides them), and the CRM running.

```bash
composer install
cp .env.example .env            # then set the database and the values below
php artisan key:generate
php artisan migrate --seed      # the demo accounts are created only with PORTAL_DEMO=1
php artisan serve --port=8001
php artisan schedule:work       # background work, expiry alerts, retention; in production, cron
```

In the CRM, run `php artisan migrate` and `php artisan schedule:work` too.

| Portal `.env` | CRM `.env` | Meaning |
|---|---|---|
| `CRM_BASE_URL` | | Where the CRM answers |
| `CRM_CLIENT_ID`, `CRM_CLIENT_SECRET` | the same two | The portal's API client |
| `WEBHOOK_SECRET` | the same | Signs the CRM's events |
| | `PORTAL_WEBHOOK_URL` | `http://localhost:8001/webhooks/crm` |
| `PORTAL_DEMO` | | `1` creates the demo accounts; never in production |

Production starts with no demo account: set `PORTAL_ORG_NAME`, `PORTAL_PROVIDER_IDS` and
`PORTAL_ADMIN_EMAIL`, then `php artisan portal:bootstrap` prints a one-time link for the first administrator.
Set `FILES_KEY` so that documents are encrypted with a key kept outside the data folder, and
`CLAMD_HOST` to scan uploads with ClamAV.

## Demo accounts (password `vsahha2026`)

| Account | Provider | Shows |
|---|---|---|
| `admin@carthagemedical.tn` | PRV-001 | Everything |
| `billing@`, `frontdesk@`, `medical@`, `finance@`, `audit@carthagemedical.tn` | PRV-001 | One role each |
| `billing@genevaimaging.ch` | PRV-002 | Isolation: cannot see Carthage's records |
| `billing@casablanca-polyclinic.ma` | PRV-008 | Suspended: reads, cannot submit |
| `admin@demo-group.test`, `finance@demo-group.test` | PRV-005, PRV-006 | Two branches and the read-only "All branches" view |

Admin and billing accounts confirm with a 6-digit code. No e-mail service is connected, so in demo mode the code is shown on the page.

## Where things are

| Path | What |
|---|---|
| `app/Portal/Crm.php` | The only door to the CRM: token, provider scope, idempotency, errors |
| `app/Portal/Ctx.php`, `Auth.php`, `Vocab.php` | The session, sign-in, roles and capabilities |
| `app/Portal/Intake/` | Smart Intake: rules reader, patient matching, batches, filing into drafts |
| `app/Portal/Copilot.php` | Questions on the branch's own records, with citations and refusals |
| `app/Portal/Alerts.php`, `Deliver.php`, `Jobs.php` | Notifications, the single send seam, retried background work |
| `app/Portal/Files.php`, `Retention.php` | Encrypted documents, scanning, what is kept and for how long |
| `resources/dict/` | French and Arabic. English is the source text |

The rules that matter:

- The provider scope is sent on every CRM call. Another provider's record answers 404, not 403.
- The portal keeps no claim decision and no money. Bank details change only by a request VSahha approves.
- Nothing from Smart Intake reaches VSahha until a person reviews the draft and submits it.
- "All branches" reads. Anything that writes asks for one branch.
- A message with no transport connected is recorded as not sent, never claimed as sent.

## Differences from the React build

- **Not ported:** the Claude and Ollama document readers and the Claude engine of the Copilot. They were opt-in and need a data-processing agreement. The rules reader and the record-based Copilot give the same kind of answer.
- **Not ported:** the service worker and offline drafts on the device. Drafts are saved on the server at each step.
- **Scans and photos:** read by Tesseract in the browser only when the person ticks the box on the upload page, because that fetches the reader and its language files once. Left off, details are entered by hand.
- **PDF preview:** the bytes reach pdf.js as text (`/files/{ref}?as=data`), so a download manager on the computer has no PDF to intercept.
- **PDF text:** read on the server with `smalot/pdfparser`, not in the browser.

## Tests

```bash
php artisan test     # uses the database vsahha_portal_test; the CRM is replaced by a fake
```
