# Railway production checklist

The React frontend and Laravel API are separate deployments:

- Frontend: `https://teamabai.website`
- API: `https://capstone-project-production-aba8.up.railway.app`

Set the following variables on the **Laravel API Railway service**. Keep every
password and API key in Railway Variables; do not commit them to Git.

```dotenv
APP_NAME=AbaiMarket
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<generated Laravel key>
APP_URL=https://capstone-project-production-aba8.up.railway.app
FRONTEND_URL=https://teamabai.website

DB_CONNECTION=mysql
DB_HOST=<Railway MySQL host>
DB_PORT=<Railway MySQL port>
DB_DATABASE=<Railway MySQL database>
DB_USERNAME=<Railway MySQL user>
DB_PASSWORD=<Railway MySQL password>

MAIL_MAILER=smtp
MAIL_HOST=<provider SMTP host>
MAIL_PORT=587
MAIL_USERNAME=<provider SMTP username>
MAIL_PASSWORD=<provider SMTP password or Gmail App Password>
MAIL_SCHEME=smtp
# Or, for compatibility with existing Railway variables:
# MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<verified sender address>
MAIL_FROM_NAME=AbaiMarket

GOOGLE_CLIENT_ID=<Google OAuth client ID>
GOOGLE_CLIENT_SECRET=<Google OAuth client secret>
GOOGLE_REDIRECT_URI=https://capstone-project-production-aba8.up.railway.app/api/auth/google/callback

PAYMONGO_PUBLIC_KEY=<PayMongo public key>
PAYMONGO_SECRET_KEY=<PayMongo secret key>
PAYMONGO_WEBHOOK_SECRET=<PayMongo webhook secret>
PAYMONGO_ASSET_BASE_URL=https://capstone-project-production-aba8.up.railway.app

GEMINI_API_KEY=<Gemini API key>
GEMINI_MODEL=gemini-2.5-flash
```

Set the following variable when building the **frontend** service:

```dotenv
VITE_API_URL=https://capstone-project-production-aba8.up.railway.app/api
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
   (Resend → Domains). Add the DNS records it shows (SPF/DKIM, optional DMARC)
   at the domain's DNS provider and wait until Resend marks the domain Verified.
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
- PayMongo: register `POST https://capstone-project-production-aba8.up.railway.app/api/paymongo/webhook`.
  The webhook secret is reserved in configuration, but signature validation is
  a separate security improvement and must be implemented before treating the
  webhook as authenticated.

## Post-deploy smoke test

Use a dedicated, authorized test inbox—not a customer address—and verify:

1. Registration and verification-link delivery; the link must use the API
   domain and return to `teamabai.website`.
2. Login after verification.
3. Each existing transactional trigger using non-financial test data where
   possible. Do not make a real PayMongo charge without explicit approval.
4. Railway logs contain no `Transactional email failed to send.` entries.
