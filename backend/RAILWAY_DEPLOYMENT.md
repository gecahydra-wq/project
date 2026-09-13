# Railway production checklist

The React frontend and Laravel API are separate deployments:

- Frontend: `https://teamabai.website` (Railway project `nurturing-liberation`)
- API: `https://api.teamabai.website` (Railway project `shimmering-charm`, service
  `capstone-project`). The generated `https://capstone-project-production-aba8.up.railway.app`
  domain is kept attached: listing photos uploaded before the switch store that
  origin in `listing_media.url`, and removing it would break them.

The API lives on a subdomain of the frontend's domain so Google's sign-in screen
shows `teamabai.website` instead of a Railway hostname. Namecheap DNS has a
CNAME `api` → the target Railway shows under the backend's Settings → Networking
→ Custom Domain (plus its `_railway-verify.api` TXT record if requested).

Set the following variables on the **Laravel API Railway service**. Keep every
password and API key in Railway Variables; do not commit them to Git.

```dotenv
APP_NAME=AbaiMarket
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<generated Laravel key>
APP_URL=https://api.teamabai.website
FRONTEND_URL=https://teamabai.website

DB_CONNECTION=mysql
DB_HOST=<Railway MySQL host>
DB_PORT=<Railway MySQL port>
DB_DATABASE=<Railway MySQL database>
DB_USERNAME=<Railway MySQL user>
DB_PASSWORD=<Railway MySQL password>

# Railway blocks outbound SMTP -- production mail goes through Resend (HTTPS).
MAIL_MAILER=resend
RESEND_API_KEY=<Resend API key, re_...>
MAIL_FROM_ADDRESS=no-reply@teamabai.website
MAIL_FROM_NAME=AbaiMarket

GOOGLE_CLIENT_ID=<Google OAuth client ID>
GOOGLE_CLIENT_SECRET=<Google OAuth client secret>
GOOGLE_REDIRECT_URI=https://api.teamabai.website/api/auth/google/callback

PAYMONGO_PUBLIC_KEY=<PayMongo public key>
PAYMONGO_SECRET_KEY=<PayMongo secret key>
PAYMONGO_WEBHOOK_SECRET=<PayMongo webhook secret>
PAYMONGO_ASSET_BASE_URL=https://api.teamabai.website

GEMINI_API_KEY=<Gemini API key>
GEMINI_MODEL=gemini-2.5-flash
```

Set the following variable when building the **frontend** service:

```dotenv
VITE_API_URL=https://api.teamabai.website/api
```

## Railway service settings

1. Point the API service root at `backend` when using `backend/Dockerfile`.
   The bundled `start.sh` also supports a repository-root service root.
2. Use `sh start.sh` as the API start command if the Docker command is
   overridden. It clears and rebuilds Laravel configuration cache at startup,
   so changed Railway variables take effect on the next deployment.
3. Run `php artisan migrate --force` as a one-off release/deployment command
   for schema changes. Never run `migrate:fresh` in production.
4. Configure an external scheduler only if scheduled announcements are used:
   `php artisan schedule:run` every minute.
5. **Attach a volume mounted at exactly `/app/storage/app/public`.** Uploads go
   through `ImageUploader` onto the `public` disk (`storage/app/public`), and
   the container filesystem is rebuilt on every deployment — anything not on a
   volume is destroyed. Re-running `storage:link` does not help: `start.sh`
   already runs it on every boot (with `--force`, so a stale symlink is
   replaced), and it only recreates the *link*, never the files. Do **not**
   mount at `/app/storage`; the volume starts empty and would hide
   `storage/framework/{cache,sessions,views}` and `storage/logs`, breaking the
   app. Railway logs are still not durable. Object storage (S3/R2) remains the
   more robust option, since a volume ties the service to one region.
6. **Keep the listening port and the public domain's target port in agreement.**
   `start.sh` serves on `${PORT:-8080}`. Set a service variable `PORT=8080` and
   set the public domain's target port to `8080` under Settings → Networking.

## Troubleshooting: 502 "Application failed to respond"

A 502 carrying the header `x-railway-fallback: true` means Railway's edge has
**no reachable container** — the request never entered PHP, so the application
code and the database are not implicated. Read the deployment log before
changing anything:

- **Build succeeded, `[1/1] Healthcheck succeeded!`, log shows
  `Server running on [http://0.0.0.0:8080]`, but the public URL still 502s** →
  this is a **port mismatch**, and it is the most likely cause. The healthcheck
  does not use the public domain's target port, so it passes while every real
  request hits a port nothing listens on. Fix it in Settings → Networking as in
  item 6 above; no redeploy is needed. Beware that `railway up` uploads the
  working tree rather than a commit, so an experimental `EXPOSE`/`--port` value
  can pin the domain's port and outlive the code that introduced it.
- **Repeated `Starting Container` lines** → the process is crash-looping. The
  service uses `restartPolicyType = "ALWAYS"` (not `ON_FAILURE`, which exhausts
  its retry budget and then parks the service on a permanent 502 until someone
  redeploys manually).
- **A genuine database problem** appears as a connection exception in the log,
  not as a fallback 502. Confirm `DB_*` point at the database service and not
  at `127.0.0.1` — a local `.env` pasted into Railway is the usual cause.
  Prefer `DB_URL=${{<MySQL service>.MYSQL_URL}}`, which `config/database.php`
  already reads, so host and credentials track the service over the private
  network instead of being copied by hand.

## SMTP and sender verification

`SafeMailer` intentionally catches mail transport exceptions so successful
orders and payouts are never rolled back because an email failed. Inspect the
Laravel/Railway logs for `Transactional email failed to send.` after a test.

**Railway blocks outbound SMTP on this plan.** Verified 2026-09-13: from inside
the API container, `smtp.gmail.com` ports 587 and 465 both time out, and
`storage/logs/laravel.log` shows `Connection could not be established with host
"smtp.gmail.com:587"`. Gmail SMTP therefore only works locally. Production sends
through **Resend over HTTPS** (the `resend/resend-php` package is installed):

1. Create a Resend account and add the domain `teamabai.website`
   (Resend → Domains). Add the DNS records it shows at the domain's DNS provider
   and wait until Resend marks the domain Verified (done 2026-09-13, region
   Tokyo `ap-northeast-1`). The domain is registered at **Namecheap**, so the
   records live in Namecheap → Domain List → Manage → **Advanced DNS → Host
   Records**. Type only the short host (Namecheap appends the domain):

   | Type  | Host                | Value                                   |
   |-------|---------------------|-----------------------------------------|
   | TXT   | `resend._domainkey` | the DKIM `p=MIGf...` value from Resend (copy button) |
   | CNAME | `rsend`             | `rsend-apne1.forge.rmta.net`            |
   | CNAME | `send`              | `send.forge.rmta.net`                   |
   | TXT   | `_dmarc` (optional) | `v=DMARC1; p=none;`                     |

   Resend's current setup uses these CNAMEs for SPF — no MX record and no
   Namecheap "Mail Settings" change is needed. Do not touch the existing `@`
   CNAME to Railway or the `_railway-verify` TXT record.
2. Create an API key (Resend → API Keys, "Sending access").
3. In Railway → `capstone-project` → Variables, set:
   ```dotenv
   MAIL_MAILER=resend
   RESEND_API_KEY=<the re_... key>
   MAIL_FROM_ADDRESS=no-reply@teamabai.website
   MAIL_FROM_NAME=AbaiMarket
   ```
   Leave the `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` variables
   in place or remove them — the Resend mailer ignores them. Railway redeploys
   and `start.sh` rebuilds the config cache.
4. Keep the local `.env` on Gmail SMTP (`MAIL_MAILER=smtp`); nothing changes
   offline.

Until the domain is verified, Resend only delivers to the account owner's own
address (sender `onboarding@resend.dev`), which is enough for a smoke test but
not for real users.

**Variables alone do not install the package.** `resend/resend-php` arrives only
with a build from a pushed commit. If `MAIL_MAILER=resend` is set on an older
build, every email fails with `Class "Resend" not found` in the container log.

**Deliverability.** A brand-new sending domain has no reputation, so the first
emails often land in spam. Add the `_dmarc` record, mark test emails "Not spam",
and delivery improves as the domain sends normally. This is expected, not a
configuration error.

**Debugging delivery.** `LOG_STACK=single` writes `SafeMailer` failures to
`/app/storage/logs/laravel.log`, which `railway logs` does not show. Read it with
`railway ssh --service capstone-project -- tail -n 20 /app/storage/logs/laravel.log`
and check Resend → Emails for per-message status.

`MAIL_TIMEOUT` (default 10 seconds) caps how long an SMTP connection may hang,
so a blocked mail server can no longer stall a request for a minute.

For Gmail SMTP locally, use a verified Gmail/Workspace account with 2-Step
Verification and a Gmail App Password. The `MAIL_FROM_ADDRESS` must be an address
the SMTP account is allowed to send as.

## Provider-console configuration

- Google Cloud: register the exact `GOOGLE_REDIRECT_URI` above. The app uses
  Socialite `stateless()` with `prompt=select_account`, so users can choose a
  different Google account. **Authorized redirect URIs is a list — add, never
  replace.** Keep the production callback alongside the local ones
  (`http://127.0.0.1:8000/api/auth/google/callback` and the `localhost`
  variant, which Google treats as a distinct entry) so the same OAuth client
  serves both environments; replacing one with the other is what produces
  `Error 400: redirect_uri_mismatch`.
- Google Cloud: also add `teamabai.website` under OAuth consent screen →
  Branding → Authorized domains, with home page `https://teamabai.website`.
- PayMongo: the webhook may stay on
  `POST https://capstone-project-production-aba8.up.railway.app/api/paymongo/webhook`
  (that domain remains attached) or move to
  `POST https://api.teamabai.website/api/paymongo/webhook`. A new PayMongo
  webhook has a new secret — update `PAYMONGO_WEBHOOK_SECRET` if you move it.
  The webhook secret is reserved in configuration, but signature validation is
  a separate security improvement and must be implemented before treating the
  webhook as authenticated.

## Post-deploy smoke test

Use a dedicated, authorized test inbox—not a customer address—and verify:

1. Registration, verification-link and **Forgot Password** delivery (check the
   spam folder too); the verification link must use the API domain and return to
   `teamabai.website`, and the reset link must open `teamabai.website/reset-password`.
2. Login after verification.
3. Each existing transactional trigger using non-financial test data where
   possible. Do not make a real PayMongo charge without explicit approval.
4. `/app/storage/logs/laravel.log` (via `railway ssh`) contains no
   `Transactional email failed to send.` / `Transactional notification failed`
   entries, and Resend → Emails shows the messages as delivered.
