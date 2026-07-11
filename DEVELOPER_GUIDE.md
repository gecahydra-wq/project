# FishMarket — Developer Guide

This guide is for developers continuing work on FishMarket. It explains how the system is structured, where the business logic lives, the conventions to follow, and how to safely extend, debug, and add features. Read [README.md](README.md) first for setup and a high-level overview.

---

## Table of Contents

- [Architecture](#architecture)
- [Application Structure](#application-structure)
- [Backend Architecture](#backend-architecture)
  - [Folder Explanations](#folder-explanations-backend)
  - [Routes](#routes)
  - [Controllers](#controllers)
  - [Service Layer](#service-layer)
  - [Models](#models)
  - [Database Relationships](#database-relationships)
- [Frontend Architecture](#frontend-architecture)
- [Domain Deep-Dives](#domain-deep-dives)
  - [Authentication](#authentication)
  - [Google Login](#google-login)
  - [Order Workflow](#order-workflow)
  - [PayMongo](#paymongo)
  - [Revenue Distribution](#revenue-distribution)
  - [Wallet System](#wallet-system)
  - [Email System](#email-system)
  - [AI Integration](#ai-integration)
- [Developer Notes](#developer-notes)
  - [Coding Standards](#coding-standards)
  - [Best Practices](#best-practices)
  - [How to Safely Extend the System](#how-to-safely-extend-the-system)
  - [How to Add New Features](#how-to-add-new-features)
  - [How to Debug](#how-to-debug)

---

## Architecture

FishMarket is a **decoupled SPA + REST API**:

```
┌───────────────────────────┐        HTTPS / JSON          ┌────────────────────────────┐
│   React 19 SPA (Vite)     │  ─────────────────────────►  │     Laravel 12 REST API     │
│   frontend/src/App.jsx    │   Bearer token (Sanctum)     │     routes/api.php          │
│   TanStack React Query    │  ◄─────────────────────────  │                             │
└───────────────────────────┘                              └──────────────┬──────────────┘
                                                                           │
                          ┌────────────────────────────────────────────────┼───────────────────────────┐
                          │                        │                        │                           │
                    Controllers              Service Layer              Eloquent                External
                   (thin, HTTP)          (app/Support + Services)      Models / DB            PayMongo · Gemini
                                          business rules & money        MySQL/SQLite          Google OAuth · SMTP
```

Guiding principles baked into the codebase:

1. **Thin controllers, rich service layer.** Controllers validate input, enforce permissions, and shape responses. The real business logic (money math, wallet balances, revenue splits, AI resolution, moderation, audit trail) lives in `app/Support` and `app/Services`.
2. **One source of truth for money.** Wallet balances, dashboard cards, reports, and even the AI assistant compute financial figures through the *same* helpers (`SellerWallet`, `LguWallet`, `RevenueReport`, `CommissionCalculator`), so they can never disagree.
3. **Escrow before payout.** Money is held until an LGU verifies the transaction. Settlements are immutable once created.
4. **Everything auditable.** State-changing admin actions flow into a unified activity log.
5. **Graceful degradation.** PayMongo, Gemini, and mail all have safe fallbacks so a missing key never breaks the app.

---

## Application Structure

```
fishmarket/
├── backend/    # Laravel 12 API — all business logic and data
└── frontend/   # React 19 SPA — all UI, one App.jsx
```

The two halves communicate only over the JSON API. The frontend never talks to the database; the backend never renders application UI (Blade is used only for emails and a couple of return pages).

---

## Backend Architecture

### Folder Explanations (backend)

| Path | Responsibility |
| --- | --- |
| `app/Http/Controllers/Api/` | REST controllers. Thin: validate → authorize → delegate → respond. One controller per role/domain. |
| `app/Http/Middleware/` | `EnsureRole` (the `role:` gate) and `DemoAuth` (legacy token auth). |
| `app/Services/` | Integrations with **external** systems: `GeminiService` (AI), `PayMongoService` (payments). |
| `app/Support/` | The **domain service layer** — the heart of the app. Money, wallets, revenue, AI resolution, moderation, audit log, exporters, presenters. Pure PHP, mostly static helpers, no HTTP concerns. |
| `app/Models/` | Eloquent models + relationships. |
| `app/Mail/` | Mailables for every transactional email. |
| `app/Observers/` | `UserActivityObserver` logs new registrations into the activity trail. |
| `app/Console/Commands/` | `PublishScheduledAnnouncements` (runs on the scheduler). |
| `config/services.php` | Credentials for PayMongo, Gemini, and Google. |
| `database/migrations/` | Schema, in order. New schema changes go here. |
| `resources/views/` | Blade for emails and the shared PayMongo/verification return page. |
| `routes/api.php` | Every API route, grouped by auth + role middleware. |
| `routes/console.php` | Scheduler entries (e.g. `announcements:publish` every 5 min). |
| `tests/Feature/` | The feature test suite (the primary safety net). |

### Routes

All routes live in `routes/api.php` and are organized by **middleware group**, which is the clearest map of the permission model:

- **Public:** listings, seller profiles, municipalities, login/register, Google OAuth legs, email verification, PayMongo webhook.
- **`role:buyer`:** buyer dashboard/profile/analytics, order placement, checkout, reviews.
- **`role:seller`:** seller dashboard/analytics/profile, listing CRUD + media, order status updates, wallet, withdrawals, seller posts, rate-buyer.
- **`role:buyer,seller`:** shared order index + order lookup by number.
- **`role:buyer,seller,lgu_admin,super_admin`:** messaging, AI assistant, active announcements, seller-post likes/comments.
- **`prefix('lgu') + role:lgu_admin`:** municipality-scoped governance, wallet, reports, activity log, reviews & ratings moderation.
- **`prefix('super-admin') + role:super_admin`:** platform-wide administration, payouts, moderation, announcements, reports.

Every protected group also carries `auth:sanctum` and `verified`. Route-model binding is used throughout; several bindings key on human identifiers (e.g. orders by `order_number`).

### Controllers

Controllers are intentionally thin and role-oriented:

| Controller | Responsibility |
| --- | --- |
| `AuthController` | Register / login / logout / me / change password. Issues Sanctum tokens. |
| `GoogleAuthController` | Google OAuth redirect + callback (Socialite). |
| `EmailVerificationController` | Signed email-verification link + resend. |
| `ListingController` | Public catalogue + seller-owned listing & media CRUD. |
| `OrderController` | Order lifecycle, checkout, PayMongo webhook/return, seller notes. |
| `ReviewController` | Buyer → seller reviews. |
| `BuyerController` | Buyer dashboard, profile, notifications, analytics. |
| `SellerController` | Seller dashboard, profile, wallet, withdrawals, buyer profiles, rate-buyer. |
| `SellerProfileController` | Public seller profile (with posts, ratings, reviews). |
| `SellerPostController` | Seller "Farm Posts" CRUD + post media (owner only). |
| `SellerPostInteractionController` | Likes + comments on posts (all roles). |
| `MessageController` | Direct messaging threads. |
| `AnnouncementController` | Announcement CRUD + active feed. |
| `AiAssistantController` | AI ask + chat history. |
| `LguController` | Municipality-scoped governance, wallet, earnings verification, reviews moderation. |
| `SuperAdminController` | Platform-wide administration, payouts, moderation, executive dashboard. |
| `PlatformController` | Shared read models used by more than one admin role (reports, reviews, listings, exports). |

When a controller method has non-obvious rules, they're documented in a PHPDoc block on the method. Read those first.

### Service Layer

`app/Support` is where the business rules live. Key classes:

| Class | Purpose |
| --- | --- |
| `CommissionCalculator` | The fixed revenue split (96/4 at settlement, 6% withdrawal fee). Single source of the percentages. |
| `SellerWallet` | Seller balance math (available / pending / processing / withdrawn / total earnings). |
| `LguWallet` | LGU municipality balance math (same buckets). |
| `RevenueReport` | Platform + municipality revenue cards, time series, and executive snapshot. |
| `OrderTimeline` / `OrderTransactionPresenter` | Normalized, role-aware single-order detail + status timeline. |
| `ActivityLog` | Unified audit trail — both a writer (`record`) and a read path (`query`) that merges written entries with events living in other tables (moderation, settlements, withdrawals, reviews, ratings). |
| `AccountModeration` | Suspend/reinstate mechanics for buyers, sellers, LGU admins (logs + emails). |
| `ListingModeration` / `ReviewModeration` | Listing removal notifications; review/rating removal + aggregate recompute. |
| `AiDataQueryResolver` | Answers marketplace questions from **live DB facts**, scoped by role. |
| `AiRecommendationEngine` | Ranked, scored recommendations scoped by role. |
| `AiIntentClassifier` | Scripted knowledge base + off-topic detection + greetings. |
| `AiLanguageDetector` | English / Filipino / Cebuano detection. |
| `AnalyticsPeriod` | Shared time-period resolution + bucketizing for every chart. |
| `ReportExporter` | PDF (dompdf) + Excel (PhpSpreadsheet) report generation. |
| `ImageUploader` | Media validation (real MIME), storage, deletion. |
| `SafeMailer` | Send mail without ever letting a broken transport 500 a request. |
| `AnnouncementNotifier` | Fan-out of announcements to notifications. |

`app/Services` holds the two external integrations: `GeminiService` and `PayMongoService`.

### Models

Eloquent models in `app/Models`, grouped by domain:

- **Identity & profiles:** `User`, `BuyerProfile`, `SellerProfile`, `Municipality`
- **Catalogue:** `FingerlingListing`, `ListingMedia`
- **Orders & money:** `Order`, `MockPayment`, `PaymentLog`, `Settlement`, `WithdrawalRequest`, `LguWithdrawalRequest`
- **Feedback:** `Review`, `BuyerRating`
- **Social:** `SellerPost`, `SellerPostMedia`, `SellerPostLike`, `SellerPostComment`
- **Comms & audit:** `Message`, `AppNotification`, `Announcement`, `ActivityLogEntry`, `ModerationLog`
- **AI:** `AiConversation`, `AiUsageEvent`, `AiGrowthLog`, `AiDiseaseReport`

> **Important convention:** `AppServiceProvider` sets `Model::$snakeAttributes = false`, so **loaded relations serialize to JSON in camelCase** (`sellerProfile`, `buyerProfile`, `buyerRating`) while plain column attributes stay snake_case (`hatchery_name`, `order_number`). When you add a relation to an API payload, reference it in the frontend and in tests using the **camelCase** key. Explicit response keys you name yourself are whatever you type.

### Database Relationships

Core relationships (a → b means "a belongs to / has b"):

```
User 1─1 BuyerProfile            (buyers)
User 1─1 SellerProfile           (sellers)
User n─1 Municipality
SellerProfile n─1 Municipality

SellerProfile 1─n FingerlingListing 1─n ListingMedia
SellerProfile 1─n SellerPost 1─n SellerPostMedia
SellerPost 1─n SellerPostLike / SellerPostComment

Order n─1 Buyer (User)
Order n─1 SellerProfile
Order n─1 FingerlingListing
Order 1─1 MockPayment 1─n PaymentLog
Order 1─1 Settlement              (created only at LGU approval)
Order 1─1 Review                  (buyer → seller)
Order 1─1 BuyerRating             (seller → buyer)

Settlement n─1 SellerProfile / Municipality / Order / Payment
WithdrawalRequest n─1 SellerProfile        (PAY-##)
LguWithdrawalRequest n─1 Municipality      (LGU-##)

Review / BuyerRating aggregate onto seller_profiles.rating / buyer_profiles.rating
```

Money-related rows are **append-only** where it matters: a `Settlement` is never mutated after LGU approval, and each settlement carries the exact split that applied at approval time (so historical figures never shift if the commission constants ever change).

---

## Frontend Architecture

The SPA is deliberately compact:

- **`frontend/src/App.jsx`** — the entire application: the router, the axios instance, session helpers, and every screen/component. Components are plain function components; data fetching is via **TanStack React Query**; forms use **React Hook Form**; charts use **Recharts**; icons are **lucide-react**.
- **`frontend/src/App.css`** — a hand-authored design system built on CSS custom properties (design tokens for color, type scale, spacing, radius, shadow). Most "make it look good" changes happen here, and because primitives are shared, one CSS change propagates app-wide.
- **`frontend/src/main.jsx`** — mounts `<App/>`.

### Key patterns in `App.jsx`

- **`api`** — a single axios instance pointed at `VITE_API_URL` (default `http://127.0.0.1:8000/api`) with an interceptor that attaches the Sanctum bearer token from `localStorage`.
- **Session** — the logged-in user is stored in `localStorage` under `fishmarket_user` (+ `fishmarket_token`). Helpers: `getSession()`, `updateSessionUser()`. The user object includes `id`, `role`, `name`, `email`, `profile_picture`, `municipality`.
- **Routing & guards** — `<Protected allowed={[...]}>` wraps role-restricted routes and renders inside `<AppShell>` (sidebar nav + floating AI). Each role has one dashboard component driven by a `?tab=` query param.
- **Reusable primitives** — `StatsRow`/`Stat`, `Section`, `Dashboard`, `ChartCard`, `TimeSeriesChart`, `CategoryBarChart`, `EmptyState`, `LoadingState`, `Badge`, `RoleBadge`, `Avatar`, `MediaGallery` (lightbox), `PeriodFilter`. Reuse these before writing new markup.
- **Role dashboards** — `BuyerDashboard`, `SellerDashboard`, `LguDashboard`, `SuperAdminDashboard`. Each is tab-driven and composes the primitives above.
- **Query invalidation** — mutations call `queryClient.invalidateQueries({ queryKey: [...] })` to refresh; keep query keys stable and reuse them.

There is a custom hook stub at `app/Services/useAiAssistant.js` in the backend tree, but the live AI widget (`FloatingAi`) is implemented inline in `App.jsx` against the `/ai-assistant/*` endpoints.

---

## Domain Deep-Dives

### Authentication

- **Flow:** register → verify email → login. Only **buyers and sellers** self-register (`AuthController::register`), which also provisions their `BuyerProfile`/`SellerProfile`. **LGU admins are provisioned by a Super Admin; the Super Admin is seeded.**
- **Tokens:** a Sanctum bearer token is issued **only at successful login** — never at registration — which is what keeps unverified accounts out.
- **Gates at login (in order):** valid credentials → verified email → account standing (a LGU-suspended seller or Super-Admin-disabled LGU admin is refused with a 403). A **suspended buyer can still log in** — they're only blocked from transacting (see guards in `OrderController`, `MessageController`, `ReviewController`).
- **Role enforcement:** the `role:` middleware (`EnsureRole`) runs after `auth:sanctum` and 403s any role not in the allow-list.
- **Verification:** `EmailVerificationController` consumes the signed link (unauthenticated by design) and renders a branded HTML result page.

### Google Login

Handled by `GoogleAuthController` via Laravel Socialite (`stateless()`):

1. `redirect()` sends the browser to Google's consent screen (`prompt=select_account`).
2. `callback()` matches/creates a **buyer** account by email (never duplicating an existing account), marks it verified (Google already verified the email), applies the same suspension/disabled checks as normal login, then redirects the SPA to a frontend callback URL carrying a Sanctum token in the query string.

Requires `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`; the redirect URI must exactly match the one registered in Google Cloud.

### Order Workflow

Status progression: `placed → paid → confirmed → in_transit → completed` (or `failed` / `cancelled`).

1. **Place** (`OrderController::store`, buyer): validates the listing is approved and in stock, creates the order + a pending payment, and **decrements stock immediately** to prevent overselling.
2. **Checkout** (`OrderController::checkout`): `PayMongoService` returns a hosted checkout URL, or a demo session that marks the payment held right away when keys are absent.
3. **Payment captured** (`markOrderPaid`, idempotent): payment → `paid_held` (escrow), order → `paid`, an audit `PaymentLog` row is written, and the receipt/new-order emails fire **exactly once** — whether the PayMongo webhook or the buyer's success redirect arrives first.
4. **Deliver** (`OrderController::updateStatus`, seller): on `completed`, LGU admins in the seller's municipality are notified that earnings await approval.
5. **Verify & settle** (`LguController::approveEarnings`, LGU): creates the immutable `Settlement`, splitting the money — this is the step that actually releases funds.
6. **Payout** (see below).

A failed/abandoned checkout (`markPaymentCancelled`) restocks the reserved quantity.

### PayMongo

`app/Services/PayMongoService` wraps the PayMongo Checkout API.

- **Real mode:** creates a checkout session and returns its hosted URL. Confirmation arrives via the `POST /api/paymongo/webhook` endpoint (unauthenticated; always returns 200) and/or the buyer's post-redirect call.
- **Demo mode:** when keys are blank, returns a `mode: 'demo'` session and the payment is marked held immediately, so the full escrow/settlement/payout flow is testable without credentials.
- **Idempotency** is enforced in `OrderController::markOrderPaid`, so duplicate confirmations are safe.

Configure via `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`.

### Revenue Distribution

Fixed in `CommissionCalculator` (not a runtime setting). Two separately-timed splits:

1. **At settlement (LGU approval):** `split($gross)` → **Seller 96% / LGU 4%** (platform takes nothing here). Seller share is rounded first; the LGU absorbs the rounding remainder so the two always sum to gross.
2. **At withdrawal:** `withdrawalFee($amount)` → **6% platform fee**, frozen onto the `WithdrawalRequest` at request time and only realized as **Platform Revenue** when the Super Admin marks the withdrawal **Paid**.

`RevenueReport` reads these to build every revenue card, chart, and the AI's revenue answers — so all of them agree by construction.

### Wallet System

`SellerWallet` (per seller) and `LguWallet` (per municipality) compute balances from immutable settlements and withdrawal rows — never from mutable running totals. Buckets:

- **Available Balance** — settled share earned, minus everything reserved/withdrawn. This is what's withdrawable.
- **Pending Balance** — a *projection* of share for payments captured but not yet LGU-approved (no settlement exists yet); computed from today's commission settings and never persisted.
- **Processing Amount** — reserved by a submitted/approved-but-not-yet-paid withdrawal.
- **Withdrawn Amount** — real cash received, **net of the 6% platform fee**.
- **Total Earnings** — gross earned share (settled + projected), before fees.

Because the AI assistant answers "how much can I withdraw?" using the exact same helpers, the wallet page and the AI can never disagree.

### Email System

- All mail is a Laravel Mailable in `app/Mail`, sent through `SafeMailer` so a broken transport degrades gracefully (a failed send never turns a successful action into a 500).
- Local dev: set `MAIL_MAILER=log` to capture emails in `storage/logs`.
- Covers verification, payment receipt, order lifecycle, listing approval/rejection, earnings/payout releases, and account suspension/reinstatement.

### AI Integration

`AiAssistantController::ask` → `GeminiService::answer`. Resolution order (first match wins):

1. **Live DB facts** — `AiDataQueryResolver` (role-scoped).
2. **Recommendations** — `AiRecommendationEngine` (role-scoped, ranked).
3. **Scripted knowledge** — `AiIntentClassifier` (recognized FishMarket topics).
4. **Greeting** or **off-topic refusal**.

Cases 1–3 pass a grounded context object to Gemini via a system instruction — Gemini only *phrases* real facts, never invents FishMarket data. `AiLanguageDetector` picks English/Filipino/Cebuano. If `GEMINI_API_KEY` is blank or the API fails, the app serves its own scripted fallback. Only **aggregate** usage metadata is stored in `ai_usage_events` (role, category, fallback flag, response time) — never message contents.

---

## Developer Notes

### Coding Standards

**Backend (PHP / Laravel)**
- PSR-12; 4-space indent. Follow existing file style.
- Keep controllers thin — push logic into `app/Support`/`app/Services`.
- Validate with `$request->validate([...])`; authorize with explicit `abort_if`/`abort_unless` and clear messages.
- Money is always in centavo-accurate `round(..., 2)` terms; reuse `CommissionCalculator` — never hardcode percentages.
- Document non-obvious rules with a PHPDoc block explaining **why** (see existing controllers/support classes for the tone). Avoid restating obvious code.

**Frontend (React)**
- Function components + hooks. Data via React Query; forms via React Hook Form.
- Reuse the shared primitives and CSS tokens before adding new markup/styles.
- Remember relations serialize **camelCase** (`item.sellerProfile`, `order.buyerRating`).
- Keep query keys stable; invalidate them after mutations.

### Best Practices

- **Never bypass the escrow/settlement flow.** Funds must not reach a seller's Available Balance without a `Settlement`, and settlements are created only at LGU approval.
- **Never mutate a `Settlement`.** Corrections happen through new rows/adjustments, not edits, so history stays intact.
- **Respect municipality scoping** for anything an LGU touches (listings, sellers, earnings, reviews) — filter by `municipality_id`.
- **Respect suspension guards** — suspended buyers can browse but not transact; suspended sellers can't list/withdraw.
- **Keep money figures single-sourced** — read from `SellerWallet`/`LguWallet`/`RevenueReport`, don't recompute inline.
- **Log admin actions** through `ActivityLog::record` so they appear in the audit trail.

### How to Safely Extend the System

1. **Locate the layer.** Data shape → migration + model. Business rule → `app/Support`. HTTP surface → a controller method + a route. UI → `App.jsx` (+ tokens in `App.css`).
2. **Reuse the money helpers.** Any new financial figure must come from the existing wallet/revenue helpers.
3. **Add a migration, don't edit old ones.** Use additive, nullable columns; new tables for new concepts (e.g. how `buyer_ratings`, `seller_posts` were added).
4. **Wire permissions via route groups.** Put the endpoint under the correct `role:` group; add explicit ownership/scope checks in the controller.
5. **Cover it with a feature test** in `tests/Feature/` (see existing tests for helpers like `makeSeller`, `makeBuyer`, `makeOrder`).
6. **Run the gates:** `php artisan test`, then `npm run lint` and `npm run build`.

### How to Add New Features

A typical vertical slice (mirroring how existing features were built):

1. **Migration** — new table/columns (additive).
2. **Model(s)** — with relationships; add casts/fillable.
3. **Service/helper** (if there's real logic) in `app/Support`.
4. **Controller method** — validate, authorize, delegate, respond.
5. **Route** — in the correct role group in `routes/api.php`.
6. **Frontend** — a component/section in `App.jsx` using React Query + shared primitives; styles via tokens in `App.css`.
7. **Audit/notifications** — call `ActivityLog::record` and/or create `AppNotification`s where relevant.
8. **Tests** — feature tests for the happy path, permission failures, and any money recompute.
9. **Docs** — update this guide and the README if you touch a workflow.

### How to Debug

**Backend**
- `storage/logs/laravel.log` is the first stop (Gemini/PayMongo failures are logged there).
- Set `MAIL_MAILER=log` to inspect emails without sending.
- `php artisan tinker` to poke models/services interactively.
- `php artisan route:list` to confirm a route's middleware/binding.
- Run a single test: `php artisan test --filter=test_name`. Tests use an in-memory SQLite DB, so they never touch your dev data.
- Payment issues: inspect `payment_logs` (raw provider payloads) and the `MockPayment.status` transitions.
- Money mismatches: trace through `Settlement` rows and the `SellerWallet`/`LguWallet` helpers — never trust an inline recomputation.

**Frontend**
- React Query DevTools / the Network tab show every API call and its bearer token.
- Auth problems: check `localStorage` (`fishmarket_user`, `fishmarket_token`) and that `VITE_API_URL` points at the running API.
- A field that's `undefined`: confirm you're using the **camelCase** relation key.
- `npm run lint` catches unused vars / hook issues; `npm run build` catches anything that breaks the production bundle.

**Common gotchas**
- Relations are camelCase in JSON (`$snakeAttributes = false`).
- The PayMongo webhook and the success redirect are both idempotent — don't add side effects outside `markOrderPaid`'s "newly paid" guard.
- Don't run `migrate:fresh` against a database with real data.

---

_Questions about a specific rule? The PHPDoc blocks on the relevant controller/support method almost always explain the "why". Start there._
