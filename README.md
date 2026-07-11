# FishMarket — Fish Fingerlings Web-Based Marketplace

FishMarket is a production-grade, role-based marketplace that connects local **fingerling buyers (fish farmers)** with **sellers (hatcheries)**, under the oversight of **LGU (Local Government Unit) Admins** and a **Super Admin**. It provides listing governance, escrow-style payments via PayMongo, an automated revenue-sharing model, transactional email, a database-driven Google Gemini AI assistant, and full analytics/audit tooling for every role.

Built as a BSIT capstone project, but engineered and maintained as a real production application.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Installation](#installation)
- [Environment Variables](#environment-variables)
- [Database Setup](#database-setup)
- [Running the Backend](#running-the-backend)
- [Running the Frontend](#running-the-frontend)
- [Building for Production](#building-for-production)
- [User Roles](#user-roles)
- [Payment Workflow](#payment-workflow)
- [Revenue Sharing](#revenue-sharing)
- [AI Assistant](#ai-assistant)
- [Email System](#email-system)
- [Folder Structure](#folder-structure)
- [Deployment Notes](#deployment-notes)
- [Future Improvements](#future-improvements)

---

## Project Overview

FishMarket digitizes the fingerling supply chain for a cluster of coastal municipalities. The platform is intentionally **multi-tenant by role and municipality**:

- Sellers list fingerling stock; buyers order and pay online.
- Every listing and seller is **governed by the LGU** for the seller's municipality (verification, approval, moderation).
- Payments are held in **escrow** and only released after the LGU verifies the completed transaction, protecting buyers and keeping local government in the loop.
- Revenue is **automatically split** between the seller and the LGU, and the platform earns a fee only when a seller cashes out.
- A **Super Admin** oversees the whole platform: LGU accounts, payouts, moderation, announcements, reports, and audit trails.

The system emphasizes correctness of money movement, strict role permissions, a complete audit trail, and a single, consistent source of truth for every financial figure (wallet page, dashboards, reports, and the AI assistant all agree).

---

## Features

**Authentication & Accounts**
- Email registration with email verification (self-service for buyers and sellers)
- Google OAuth sign-in (Laravel Socialite)
- Role-based authentication with Laravel Sanctum tokens
- Password change; profile picture management for every role

**Marketplace**
- Fingerling listings with multi-photo/video media galleries and a lightbox
- Species / municipality / price / search filtering
- Seller profiles with a "Farm Posts" social feed (likes + comments), ratings and reviews
- Buyer ↔ Seller two-way feedback (buyers review sellers; sellers rate buyers)
- Direct messaging between all roles
- In-app notifications

**Orders & Payments**
- Unified Order Numbers (`ORD-####` presentation; `FG-XXXXXX` internal reference)
- PayMongo Checkout (with an automatic demo fallback when keys are absent)
- Escrow-style payment holding until LGU verification
- Order lifecycle tracking with a visual timeline and global order lookup

**Wallets, Revenue & Payouts**
- Seller Wallet and LGU Wallet with Available / Pending / Processing / Withdrawn balances
- Automated revenue distribution at LGU approval
- Seller payout requests (`PAY-##`) and LGU payout requests (`LGU-##`), released by the Super Admin
- Platform payout fee accounting

**Governance & Moderation**
- LGU: seller verification, listing approval/rejection/archival, seller suspension, earnings verification (approve / hold / reject)
- Super Admin: platform-wide suspension of buyers/sellers/LGU admins, listing management, review/rating removal
- Global Activity Log / audit trail and a dedicated Moderation Log

**Analytics & Reporting**
- Buyer, Seller, LGU, and Super Admin (executive) analytics dashboards
- Revenue, orders, listings, sellers, and moderation reports
- PDF and Excel report exports

**Platform**
- Global announcement system with scheduled publishing
- Role-aware, database-driven Gemini AI assistant (English / Filipino / Cebuano)

---

## Tech Stack

| Layer | Technologies |
| --- | --- |
| **Backend** | Laravel 12, PHP 8.2+, Laravel Sanctum (API auth), Laravel Socialite (Google OAuth), barryvdh/laravel-dompdf (PDF), PhpSpreadsheet (Excel) |
| **Frontend** | React 19, Vite 8, React Router 7, TanStack React Query 5, Axios, React Hook Form 7, Recharts 3, lucide-react, Tailwind CSS 4 (build) + a custom design-token CSS system |
| **Database** | MySQL (production) / SQLite (local default & test) via Eloquent migrations |
| **Payments** | PayMongo Checkout API (demo fallback when unconfigured) |
| **AI** | Google Gemini API (local knowledge-base fallback when unconfigured) |
| **Email** | SMTP (any provider; `log` driver fallback for local dev) |
| **Auth (social)** | Google OAuth 2.0 |

> **Styling note:** Tailwind CSS 4 is installed, but the shipped UI is driven primarily by a hand-authored, token-based design system in `frontend/src/App.css`. See the Developer Guide for details.

---

## Installation

### Prerequisites

- PHP **8.2+** with the extensions Laravel requires, plus `zip` (used by Excel export) and either `pdo_sqlite` (local) or `pdo_mysql` (production)
- Composer
- Node.js **18+** and npm
- MySQL 8+ (optional locally; SQLite works out of the box)

### Clone & install

```bash
git clone <your-repo-url> fishmarket
cd fishmarket

# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Frontend
cd ../frontend
npm install
```

---

## Environment Variables

All backend configuration lives in `backend/.env`. Copy from `.env.example` and fill in what you need — every integration degrades gracefully when its keys are blank (PayMongo → demo mode, Gemini → local fallback, mail → `log` driver).

### Core

| Variable | Description |
| --- | --- |
| `APP_KEY` | Generated by `php artisan key:generate` |
| `APP_URL` | Backend base URL (e.g. `http://127.0.0.1:8000`) |
| `FRONTEND_URL` | SPA base URL, used for OAuth/email redirects (e.g. `http://127.0.0.1:5173`) |

### Database

| Variable | Description |
| --- | --- |
| `DB_CONNECTION` | `sqlite` (default) or `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL connection (uncomment when using MySQL) |

### Email (SMTP)

| Variable | Description |
| --- | --- |
| `MAIL_MAILER` | `smtp` for real mail, `log` to write emails to the log |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | SMTP credentials |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity |

### PayMongo

| Variable | Description |
| --- | --- |
| `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY` | PayMongo API keys (blank ⇒ demo checkout) |
| `PAYMONGO_WEBHOOK_SECRET` | Webhook signature secret |

### Google Gemini AI

| Variable | Description |
| --- | --- |
| `GEMINI_API_KEY` | Gemini API key (blank ⇒ local knowledge-base fallback) |
| `GEMINI_MODEL` | Model id (default `gemini-2.0-flash`) |

### Google OAuth

| Variable | Description |
| --- | --- |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | OAuth client credentials |
| `GOOGLE_REDIRECT_URI` | Must match your backend callback, e.g. `http://127.0.0.1:8000/api/auth/google/callback` |

### Frontend

The frontend reads a single optional variable (create `frontend/.env` if you need to override it):

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | API base URL. Defaults to `http://127.0.0.1:8000/api` if unset |

---

## Database Setup

The default configuration uses **SQLite**, so no database server is required for local development.

```bash
cd backend
php artisan migrate:fresh --seed
```

To use **MySQL**, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`, create the database, then run the same command.

> ⚠️ `migrate:fresh --seed` drops and rebuilds all tables. Do **not** run it against a database with real data — use `php artisan migrate` in production.

### Seeded accounts

A fresh seed creates a clean environment with only the two administrator accounts below (municipalities are seeded too). There are **no** seeded buyers or sellers — those register through the app, and the marketplace starts with zero listings.

| Role | Email | Password |
| --- | --- | --- |
| Super Admin | `superadmin@gmail.com` | `admin2026` |
| LGU Admin | `lgu@gmail.com` | `admin2026` |

> Change these credentials before any public deployment.

---

## Running the Backend

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

The API is served under `http://127.0.0.1:8000/api`.

**Scheduler (optional, for announcements):** the app schedules `announcements:publish` every five minutes. In production, add the Laravel scheduler to cron:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, the repo also includes `start-backend.cmd`.

---

## Running the Frontend

```bash
cd frontend
npm run dev
```

The SPA runs on `http://127.0.0.1:5173` (or `localhost:5173`). A single login page serves every role. On Windows, `start-frontend.cmd` is provided.

---

## Building for Production

**Frontend:**

```bash
cd frontend
npm run build      # outputs to frontend/dist
npm run preview    # optional: preview the production build locally
```

**Backend:**

```bash
cd backend
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan migrate --force
```

**Quality gates used in this project:**

```bash
# Frontend
cd frontend && npm run lint && npm run build
# Backend
cd backend && php artisan test
```

---

## User Roles

There are four roles. Every endpoint and screen enforces role permissions.

### Buyer
Fish farmers who purchase fingerlings.
- Browse and search the marketplace; view seller profiles, farm posts, ratings, and reviews
- Place and pay for orders (PayMongo)
- Track orders and look them up by order number
- Review and rate sellers after completed orders
- Like and comment on seller farm posts
- Message sellers; receive notifications
- Personal purchase analytics
- A suspended buyer can still log in and browse but **cannot** order, pay, message, or review.

### Seller (Hatchery)
- Create and manage listings (with photos/videos), pending LGU approval
- Manage a public seller profile and a "Farm Posts" social feed
- Fulfil orders through the delivery lifecycle
- Seller Wallet: view balances, request payouts
- Rate buyers after completed orders (so other sellers can gauge legitimacy)
- View the marketplace read-only (cannot purchase from other sellers)
- Analytics, messaging, notifications
- A suspended seller is logged out and blocked from listing, receiving orders, and withdrawing.

### LGU Admin
Scoped strictly to their own municipality.
- Verify sellers; approve / reject / archive listings
- Verify completed transactions: approve, hold, or reject seller earnings (this is what releases money)
- Suspend/reinstate sellers in their municipality
- LGU Wallet and payout requests
- Municipality reports, analytics, activity log, reviews & ratings (with moderation)

### Super Admin
Platform-wide authority.
- Executive dashboard (today's orders/revenue, pending queues, top performers, recent activity)
- Provision and manage LGU Admin accounts and municipalities
- Approve/release seller and LGU payouts
- Suspend/reinstate buyers, sellers, and LGU admins
- Global listing management, review/rating moderation
- Announcements, global activity & moderation logs, platform reports and exports

---

## Payment Workflow

FishMarket uses an **escrow-style** flow so a seller is never paid before the transaction is verified by the LGU.

```
Buyer places order
        │  (stock reserved)
        ▼
Buyer pays via PayMongo  ──►  funds marked "paid_held" (escrow)
        │
        ▼
Seller delivers  ──►  order marked "completed"
        │
        ▼
LGU verifies the transaction  ──►  approves earnings
        │            (creates an immutable Settlement; splits revenue)
        ▼
Seller Available Balance increases;  LGU revenue recognized
        │
        ▼
Seller requests payout (PAY-##)  ──►  Super Admin approves & marks Paid
LGU requests payout   (LGU-##)   ──►  Super Admin approves & marks Paid
```

- **Demo mode:** if PayMongo keys are blank, checkout returns a demo session that immediately marks the payment held, so the full flow can be exercised end-to-end without real keys.
- **Idempotency:** the PayMongo webhook and the buyer's post-redirect confirmation can both arrive; only the first transition captures the payment and sends receipts.

---

## Revenue Sharing

The split is **fixed in code** (not a runtime setting) and happens in two separately-timed stages:

**1. At LGU approval / settlement** — the gross order amount is divided:

| Party | Share |
| --- | --- |
| **Seller** | **96%** |
| **LGU (municipality)** | **4%** |
| Platform | 0% at this stage |

The seller share is rounded to the centavo first; the LGU absorbs any rounding remainder so the two always sum to exactly the gross amount.

**2. At withdrawal** — the platform earns a **6% payout fee** on the amount a seller withdraws. This fee is frozen onto the withdrawal request when it is created and only becomes realized **Platform Revenue** once the Super Admin marks that withdrawal **Paid**. A settled-but-unwithdrawn order contributes nothing to platform revenue.

> Source of truth: `App\Support\CommissionCalculator`, `App\Support\SellerWallet`, `App\Support\LguWallet`, and `App\Support\RevenueReport`.

---

## AI Assistant

A single, role-aware Gemini assistant is available on every page.

- **Database-driven:** marketplace questions are answered from live database facts first (`AiDataQueryResolver`), then ranked recommendations (`AiRecommendationEngine`), then the app's own scripted knowledge base (`AiIntentClassifier`) — Gemini is only asked to phrase real, grounded context, never to invent FishMarket facts.
- **Role-scoped:** a buyer, seller, LGU admin, and super admin each get answers scoped to what they're allowed to see (e.g. a seller's wallet answer is computed by the exact same rules as the wallet page).
- **Multilingual:** automatically detects and replies in **English, Filipino, or Cebuano**.
- **On-topic only:** off-topic questions get a polite refusal before ever reaching the model.
- **Graceful fallback:** when `GEMINI_API_KEY` is blank or the API is unreachable, the app serves its own scripted answer.
- **Privacy:** only aggregate usage metadata is retained for analytics — never message contents beyond the user's own chat history.

---

## Email System

Transactional email is sent through Laravel Mailables via a `SafeMailer` wrapper, so a broken mail transport never turns a successful action into a 500 error. Set `MAIL_MAILER=log` locally to capture emails in the log instead of sending them.

Implemented emails include:
- Email Verification
- Payment Receipt, New Order Received, Order Confirmed, Order Delivered
- Listing Approved / Rejected
- Seller Earnings Approved, Seller Payout Released
- LGU Payout Approved / Released
- Account Suspended / Reinstated

---

## Folder Structure

```
fishmarket/
├── backend/                      # Laravel 12 API
│   ├── app/
│   │   ├── Console/Commands/     # Scheduled commands (announcement publishing)
│   │   ├── Http/
│   │   │   ├── Controllers/Api/  # REST controllers (one per domain/role)
│   │   │   └── Middleware/        # EnsureRole (role gate), DemoAuth
│   │   ├── Mail/                  # Transactional Mailables
│   │   ├── Models/                # Eloquent models
│   │   ├── Observers/             # UserActivityObserver (registration logging)
│   │   ├── Providers/             # AppServiceProvider
│   │   ├── Services/              # GeminiService, PayMongoService
│   │   └── Support/               # Domain "service layer" (wallets, revenue, AI, moderation…)
│   ├── config/                   # services.php holds PayMongo/Gemini/Google keys
│   ├── database/migrations/      # Schema (33 migrations)
│   ├── resources/views/          # Blade (email templates, PayMongo/verification return page)
│   ├── routes/
│   │   ├── api.php               # All API routes, grouped by role middleware
│   │   └── console.php           # Scheduler definitions
│   └── tests/Feature/            # Feature test suite
├── frontend/                     # React 19 + Vite SPA
│   ├── src/
│   │   ├── App.jsx               # The application (routing + all screens/components)
│   │   ├── App.css               # Design-token based styling system
│   │   └── main.jsx              # Entry point
│   └── dist/                     # Production build output
├── start-backend.cmd             # Windows helper
└── start-frontend.cmd            # Windows helper
```

> See **[DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)** for a deep dive into each layer.

---

## Deployment Notes

- **Environment:** set `APP_ENV=production`, `APP_DEBUG=false`, a strong `APP_KEY`, and correct `APP_URL` / `FRONTEND_URL`.
- **Database:** use MySQL in production; run `php artisan migrate --force` (never `migrate:fresh` against production data).
- **Storage:** run `php artisan storage:link` so uploaded media (`storage/app/public`) is served from `public/storage`. For scale, switch `FILESYSTEM_DISK` to S3.
- **Caching:** `php artisan config:cache` and `route:cache` after each deploy.
- **Scheduler & queues:** add `schedule:run` to cron (for announcements). `QUEUE_CONNECTION` defaults to `database`; run `php artisan queue:work` if you move mail/notifications onto the queue.
- **CORS / Sanctum:** ensure the frontend origin is allowed and `FRONTEND_URL` is correct for OAuth and email links.
- **PayMongo webhook:** register `POST {APP_URL}/api/paymongo/webhook` in the PayMongo dashboard.
- **Google OAuth:** register `GOOGLE_REDIRECT_URI` in the Google Cloud console.
- **Frontend:** build with `npm run build` and serve `frontend/dist` from any static host/CDN; point `VITE_API_URL` at the deployed API.

---

## Future Improvements

- Split the monolithic `frontend/src/App.jsx` into feature modules and enable route-based code splitting (the production bundle currently exceeds the 500 kB warning threshold).
- Move transactional email and notifications onto the queue for faster request responses.
- Real-time messaging and notifications (WebSockets / Laravel Reverb).
- Configurable commission rates behind a Super Admin settings screen (currently code-fixed).
- Automated frontend test coverage (unit/e2e) to complement the backend feature suite.
- Multi-currency / additional local payment methods.
- Native mobile clients consuming the existing API.

---

_FishMarket — connecting hatcheries, farmers, and local government in one trusted marketplace._
