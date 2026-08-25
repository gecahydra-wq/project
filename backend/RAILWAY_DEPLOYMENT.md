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
5. Configure persistent object storage before relying on user-uploaded media;
   Railway container storage and logs are not durable.

## SMTP and sender verification

`SafeMailer` intentionally catches mail transport exceptions so successful
orders and payouts are never rolled back because an email failed. Inspect the
Laravel/Railway logs for `Transactional email failed to send.` after a test.

For Gmail SMTP, use a verified Gmail/Workspace account with 2-Step Verification
and a Gmail App Password. The `MAIL_FROM_ADDRESS` must be an address the SMTP
account is allowed to send as. If Railway or the chosen provider blocks SMTP,
do not attempt a workaround: configure an HTTPS transactional provider such as
Resend and set `MAIL_MAILER=resend` plus `RESEND_API_KEY` after installing its
official PHP transport dependency in an approved follow-up.

## Provider-console configuration

- Google Cloud: register the exact `GOOGLE_REDIRECT_URI` above. The app uses
  Socialite `stateless()` with `prompt=select_account`, so users can choose a
  different Google account.
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
