# AbaiMarket — Use Case Diagram Documentation

**Project:** AbaiMarket — Fisheries Fingerling Marketplace
**Program:** BSIT Capstone Project
**Scope of this document:** the *actual* actors and use cases of the system, derived directly from `routes/api.php`, the `EnsureRole` middleware, and the controllers each route resolves to. Every use case below corresponds to one or more real, role-guarded API endpoints. No speculative feature is included.

---

## 1. How this model was derived (validation method)

| Source studied | What it confirmed |
|---|---|
| `routes/api.php` | Every endpoint, its HTTP verb, and the middleware group it lives in |
| `app/Http/Middleware/EnsureRole.php` (`role:` alias) | Which roles may enter each route group — the authority for actor→use-case mapping |
| `auth:sanctum` + `verified` middleware | Which use cases require authentication and a verified email |
| Controllers (`*Controller.php`) | What each endpoint actually does, and scoping (e.g. LGU actions are municipality-scoped) |

**Access control model.** Authorization is enforced in two layers, both real in the code:
1. **Authentication** — `auth:sanctum` (Laravel Sanctum token).
2. **Role gate** — `EnsureRole` compares `users.role` against the roles a route group allows, returning **403** for an authenticated user whose role is not permitted (distinct from the **401** for no token). The four roles are `buyer`, `seller`, `lgu_admin`, `super_admin`.

Some endpoints additionally require `verified` (a verified email). Public catalog reads and the auth endpoints are the only ones outside `auth:sanctum`.

---

## 2. Actors

### 2.1 Primary actors

| Actor | Description | Route guard |
|---|---|---|
| **Guest** | Unauthenticated visitor. Can browse the public catalog and self-register/log in. | no auth |
| **Buyer** | Registered purchaser of fingerlings. | `role:buyer` |
| **Seller (Hatchery)** | Registered fingerling seller; owns a hatchery profile. | `role:seller` |
| **LGU Admin** | Local Government Unit officer; all actions are **scoped to their own municipality**. | `role:lgu_admin` |
| **Super Admin** | Platform operator; **platform-wide** authority. | `role:super_admin` |

**Actor generalization.** Buyer, Seller, LGU Admin, and Super Admin are all specializations of an **Authenticated User**. The "Common" use cases (messaging, seller-post engagement, AI Assistant, viewing announcements, account management) belong to the Authenticated User and are therefore available to all four roles — this matches the shared `role:buyer,seller,lgu_admin,super_admin` route group in the code. *View announcements* is additionally associated with the **Guest**, because its endpoint is public (the site-wide announcement bar is shown on the public storefront too).

### 2.2 Secondary (external) actors

| Actor | Role in the system | Where it appears in code |
|---|---|---|
| **PayMongo** | Payment gateway. Hosts checkout and confirms payment through a **signed** webhook (signature checked against `PAYMONGO_WEBHOOK_SECRET`) and a checkout-session status check when the buyer returns. | `PayMongoService`, `OrderController::checkout` / `paymongoWebhook` / `markPaymentSuccess` |
| **Google OAuth** | Federated login provider. | `GoogleAuthController::redirect` / `callback` |
| **Gemini AI** | LLM that phrases the AI Assistant's grounded answers. | `GeminiService`, `AiAssistantController::ask` |
| **Email (Resend / SMTP)** | Outbound mail for verification, password reset, and transactional notifications. Production sends through the **Resend** HTTPS API from `no-reply@teamabai.website` (Railway blocks SMTP); local development uses Gmail SMTP. | `EmailVerificationController`, `PasswordResetController`, `App\Mail\*`, `SafeMailer` |
| **Scheduler (Laravel)** | Time-based system actor. Runs every 5 minutes (started by `start.sh` on Railway, `php artisan schedule:work` locally). | `routes/console.php`: `orders:expire-unpaid`, `announcements:publish`, `sanctum:prune-expired` |

---

## 3. Use case catalog (with endpoint mapping)

Each row is a use case and the concrete endpoint(s) that implement it. `✔ verified` = also requires a verified email.

### 3.1 Public / Pre-login (Guest)

| Use case | Endpoint(s) |
|---|---|
| Register account | `POST /auth/register` |
| Log in | `POST /auth/login` (rate-limited: 5 attempts per minute per email + IP) |
| Log in with Google | `GET /auth/google/redirect`, `GET /auth/google/callback` |
| Register with Google (role chosen first) | `GET /auth/google/redirect?registration=1&role=…[&municipality_id=…]`, `GET /auth/google/callback` |
| Verify email | `GET /email/verify/{id}/{hash}`, `POST /email/resend` |
| Reset forgotten password | `POST /auth/forgot-password`, `POST /auth/reset-password` (the emailed link opens the SPA page `/reset-password`) |
| Browse listings | `GET /listings` |
| View listing details | `GET /listings/{listing}` |
| View seller profiles & posts | `GET /sellers`, `GET /sellers/{seller}` |
| View municipalities | `GET /municipalities` |
| View announcements (site-wide bar) | `GET /announcements/active` — public; the bar is shown at the top of every page for guests and signed-in roles alike |

*«include»* Register, Verify email and Reset forgotten password → **Email (Resend / SMTP)**; Log in with Google → **Google OAuth**.

> Reset forgotten password works for every role, including Google-registered accounts (the reset gives them a usable password; Google sign-in keeps working). It answers identically for unknown emails, the link expires after 60 minutes and is single-use, and a successful reset logs the account out everywhere.

> Registering with Google never assumes a role. The visitor picks Buyer or Seller (plus a municipality, for a seller) before leaving for Google, and the choice returns inside an encrypted `state` parameter. A Google sign-in for an unknown email with no such choice creates **no** account — it redirects to the Register page to collect the role first. A seller registered this way follows the same *Verify seller registration* LGU workflow as an email registration.

### 3.2 Common — all signed-in roles (`role:buyer,seller,lgu_admin,super_admin`, ✔ verified)

| Use case | Endpoint(s) |
|---|---|
| Manage account | `GET /auth/me`, `POST /auth/logout`, `PATCH /auth/password` (login tokens expire after 7 days). The Change Password form appears only on the Super Admin profile; Buyers, Sellers and LGU Admins change their password through *Reset forgotten password* (§3.1). |
| Send / edit / delete messages | `GET /messages/threads`, `GET /messages/thread/{user}`, `POST /messages`, `PATCH /messages/{message}`, `DELETE /messages/{message}`, `PATCH /messages/thread/{user}/read` |
| Like & comment on seller posts | `POST /seller-posts/{post}/like`, `POST /seller-posts/{post}/comments`, `DELETE /seller-posts/comments/{comment}` |
| Ask AI Assistant (EN/Tagalog/Bisaya) | `POST /ai-assistant/ask`, `GET /ai-assistant/history` |
| View announcements (site-wide bar) | `GET /announcements/active` (public — see §3.1) |

*«include»* Ask AI Assistant → **Gemini AI** (with the app's scripted fallback when Gemini is unreachable).

### 3.3 Buyer (`role:buyer`, ✔ verified)

| Use case | Endpoint(s) |
|---|---|
| Manage buyer profile | `PATCH /buyer/profile`, `POST/DELETE /buyer/profile/picture` |
| View dashboard & analytics | `GET /buyer/dashboard`, `GET /buyer/analytics` |
| Add to cart (buy later) | `GET/POST /cart`, `PATCH /cart/{item}`, `DELETE /cart/{item}`, `DELETE /cart` |
| Place order | `POST /orders` |
| Checkout & pay | `POST /orders/{order}/checkout`, `POST /orders/{order}/payment-success`, `POST /orders/{order}/payment-cancelled` |
| Track / look up own orders | `GET /orders`, `GET /orders/{order:order_number}` |
| Review seller (completed order) | `POST /orders/{order}/review` |
| Report a seller | `GET /reports/reasons`, `POST /reports`, `GET /reports/mine` (shared `role:buyer,seller`; direction derived from the caller's role) |
| Manage notifications | `GET /buyer/notifications`, `PATCH /buyer/notifications/read-all`, `PATCH /buyer/notifications/{notification}/read` |

*«include»* Checkout & pay → **PayMongo** (hosted checkout + `POST /paymongo/webhook`, which is unauthenticated but only accepted with a valid PayMongo signature; the buyer's return to the success page is also confirmed with PayMongo before the order is marked paid). *«extend»* Add to cart → Place order (a cart item is checked out through the same place-order flow).

### 3.4 Seller / Hatchery (`role:seller`, ✔ verified)

| Use case | Endpoint(s) |
|---|---|
| Manage hatchery profile | `PATCH /seller/profile`, `POST/DELETE /seller/profile/picture`, `POST/DELETE /seller/profile/cover-photo` |
| Manage listings & media | `POST/PATCH/DELETE /listings...`, `POST/DELETE /listings/{listing}/media...`, `PATCH /listings/{listing}/media/reorder` |
| Publish seller posts (farm updates) | `POST/PATCH/DELETE /seller/posts...`, `POST/DELETE /seller/posts/{post}/media...` |
| Update order status / cancel order (restock) | `PATCH /orders/{order}/status` — cancelling returns the stock; a paid order's payment goes to the Super Admin refund queue |
| Add seller notes to order | `PATCH /orders/{order:order_number}/notes` |
| Rate buyer (completed order) | `POST /orders/{order}/rate-buyer` |
| Report a buyer | `GET /reports/reasons`, `POST /reports`, `GET /reports/mine` (shared `role:buyer,seller`) |
| Answer a Notice to Explain | `GET /seller/notices`, `POST /seller/notices/{notice}/respond` |
| View buyer profile | `GET /seller/buyers/{buyer}` |
| Wallet & request withdrawal | `GET /seller/wallet`, `POST /seller/withdrawals` |
| Dashboard & analytics | `GET /seller/dashboard`, `GET /seller/analytics` |
| Manage notifications | `GET /seller/notifications`, `PATCH /seller/notifications/...` |
| Track / look up orders (own listings) | `GET /orders`, `GET /orders/{order:order_number}` (shared `role:buyer,seller`) |

### 3.5 LGU Admin (`role:lgu_admin`, ✔ verified — all municipality-scoped)

| Use case | Endpoint(s) |
|---|---|
| Dashboard | `GET /lgu/dashboard` |
| Moderate listings | `GET /lgu/listings...`, `PATCH /lgu/listings/{listing}/approve\|reject\|archive`, `DELETE /lgu/listings/{listing}` |
| Verify / suspend / reinstate sellers | `GET /lgu/sellers`, `PATCH /lgu/sellers/{seller}/verify\|suspend\|reinstate` |
| Approve seller earnings (create settlement) | `GET /lgu/earnings`, `PATCH /lgu/payments/{payment}/approve` |
| Hold / reject / reopen earnings | `PATCH /lgu/payments/{payment}/hold\|clear-hold\|reject\|reopen`, `GET /lgu/earnings/rejected` |
| Remove unfair reviews & ratings | `GET /lgu/reviews`, `DELETE /lgu/reviews/{review}`, `DELETE /lgu/buyer-ratings/{rating}` |
| Handle user reports (own municipality) | `GET /lgu/user-reports`, `PATCH /lgu/user-reports/{report}` |
| Review Notices to Explain | `GET /lgu/seller-notices`, `PATCH /lgu/seller-notices/{notice}` |
| Reports & export | `GET /lgu/reports`, `GET /lgu/reports/export` |
| LGU wallet & withdrawals | `GET /lgu/wallet`, `POST /lgu/withdrawals` |
| Activity log | `GET /lgu/activity-log...` |
| Look up order | `GET /lgu/orders/{order:order_number}` |
| Manage profile picture & notifications | `POST/DELETE /lgu/profile/picture`, `PATCH /lgu/notifications/{notification}/read`, `GET /lgu/users` |

### 3.6 Super Admin (`role:super_admin`, ✔ verified — platform-wide)

| Use case | Endpoint(s) |
|---|---|
| Executive dashboard & order lookup | `GET /super-admin/dashboard`, `GET /super-admin/orders/{order:order_number}` |
| Manage municipalities | `POST /super-admin/municipalities` |
| Manage LGU admins | `GET /super-admin/lgu-admins`, `POST /super-admin/lgu-admins`, `PATCH /super-admin/lgu-admins/{admin}`, `PATCH .../disable`, `PATCH .../enable` |
| Moderate sellers & buyers | `PATCH /super-admin/sellers/{seller}/suspend\|reinstate`, `DELETE /super-admin/sellers/{seller}`, `PATCH /super-admin/buyers/{user}/suspend\|reinstate`, `DELETE /super-admin/buyers/{user}` |
| Approve seller & LGU payouts | `GET/PATCH /super-admin/withdrawals...`, `GET/PATCH /super-admin/lgu-withdrawals...` (approve / reject / paid) |
| Process refunds (mark refunded) | `GET /super-admin/refunds`, `PATCH /super-admin/refunds/{payment}/refunded` — the refund itself is done in the PayMongo dashboard |
| Manage listings (global) | `GET /super-admin/listings...`, `PATCH .../approve\|reject\|archive\|update`, `DELETE /super-admin/listings/{listing}` |
| Manage announcements | `GET/POST /super-admin/announcements`, `PATCH/DELETE /super-admin/announcements/{announcement}` |
| Remove reviews & ratings | `DELETE /super-admin/reviews/{review}`, `DELETE /super-admin/buyer-ratings/{rating}` |
| Handle user reports (platform-wide) | `GET /super-admin/user-reports`, `PATCH /super-admin/user-reports/{report}` |
| Reports, activity & moderation logs | `GET /super-admin/reports...`, `GET /super-admin/activity-log...`, `GET /super-admin/moderation-log` |
| Manage profile picture & notifications | `POST/DELETE /super-admin/profile/picture`, `GET /super-admin/notifications`, `PATCH .../read`, `GET /super-admin/users` |

### 3.7 System — automated (Scheduler)

| Use case | Implementation |
|---|---|
| Expire unpaid orders (release stock) | `orders:expire-unpaid` every 5 minutes — orders still unpaid after `ORDER_PAYMENT_TIMEOUT_MINUTES` (default 60) become `failed`, stock is returned, the buyer is notified. A real PayMongo session is double-checked first. |
| Publish scheduled announcements | `announcements:publish` every 5 minutes — sends the notification fan-out for announcements whose start time has arrived |

(`sanctum:prune-expired` also runs daily to delete expired login tokens; it has no user-visible use case.)

---

## 4. Relationships in the diagram

| Relationship | Meaning | Basis in code |
|---|---|---|
| Actor generalization (Buyer/Seller/LGU/Admin → Authenticated User) | The four roles inherit the Common use cases | shared `role:buyer,seller,lgu_admin,super_admin` group |
| «include» Checkout & pay → PayMongo | Paying always invokes the gateway | `OrderController::checkout` → `PayMongoService` |
| «include» Ask AI Assistant → Gemini AI | Answering invokes the model (with scripted fallback) | `GeminiService::answer` |
| «include» Register / Verify email / Reset forgotten password → Email | All send mail | `EmailVerificationController`, `PasswordResetController`, mailables |
| Scheduler → Expire unpaid orders / Publish scheduled announcements | Time-triggered, no human actor | `routes/console.php` |
| «include» Log in with Google → Google OAuth | Federated login | `GoogleAuthController` |
| «extend» Add to cart → Place order | A saved cart item is optionally converted into an order | `CartController` + `OrderController::store` |

---

## 5. Key business rules reflected in the use cases

- **Escrow, not instant payout.** "Checkout & pay" only *holds* funds (`payment.status = paid_held`). Money is released only when an LGU Admin runs **"Approve seller earnings"**, which creates the immutable settlement. This is why the LGU actor sits between the buyer's payment and the seller's wallet.
- **Municipality scoping.** Every LGU Admin use case operates only on data in that admin's own municipality; the Super Admin's equivalents are platform-wide. Same verb, different scope — represented by two separate packages.
- **Reversible rejection.** "Hold / reject / reopen earnings" exists because a rejected order's payment stays held; the reopen path returns it to the approval queue.
- **Two-directional feedback.** "Review seller" (buyer→seller) and "Rate buyer" (seller→buyer) are distinct use cases, each allowed once per completed order.
- **Verified email required.** Every authenticated use case is behind the `verified` middleware; an unverified account can log in but cannot transact.
- **Reporting is two-directional but role-derived.** Buyers report sellers and sellers report buyers through the *same* endpoint (`POST /reports`); the direction comes from the caller's role rather than a field, so neither side can file on another's behalf. An LGU Admin handles reports in their own municipality; the Super Admin handles them platform-wide.
- **A Notice to Explain is not a suspension.** A poor average rating raises a notice asking the seller to explain, and the seller answers it (`POST /seller/notices/{notice}/respond`). Only an LGU Admin or the Super Admin can actually suspend, through the separate seller-moderation use case — nothing suspends automatically.
- **Google registration never assumes a role** (see §3.1): a Google sign-in for an unknown email either carries an explicit Buyer/Seller choice or creates no account at all.
- **Stock is never lost.** Placing an order reserves stock; cancelling, a declined payment, or expiry (unpaid after 60 minutes) always returns it. Cancelled, failed and completed orders can no longer change status.
- **Refunds are a manual, tracked queue.** A cancelled paid order (or a payment that arrives after the order closed) is never settled to the seller; it waits as `refund_pending` until the Super Admin refunds the buyer in PayMongo and marks it refunded.
- **Payments are verified, not trusted.** The PayMongo webhook must carry a valid signature, and the buyer's success redirect is confirmed with PayMongo before an order is marked paid.
- **Account security.** Login is limited to 5 attempts per minute per email + IP, tokens expire after 7 days, and a password reset signs the account out everywhere.
- **One way to change a password for most roles.** Buyers, Sellers and LGU Admins change their password through the emailed Forgot password link; only the Super Admin keeps an in-profile Change Password form.
- **Announcements are public.** The Super Admin's active announcements appear as a bar across the top of every page — guests on the storefront and every signed-in role — in addition to the in-app notification each user receives.

---

## 6. Files in this folder

| File | Format | Use |
|---|---|---|
| `AbaiMarket_UseCase.drawio` | Draw.io / mxGraph XML | Fully editable — actors, ellipse use cases, packages, association/include/extend edges |
| `AbaiMarket_UseCase.mmd` | Mermaid | Use-case model as a flowchart; renders in Mermaid Live Editor and on GitHub |
| `AbaiMarket_UseCase.puml` | PlantUML | Native use-case notation; renders with `plantuml.jar` or the PlantUML server |
| `AbaiMarket_UseCase.png` | Raster image (rendered from the PlantUML source — native UML notation) | Presentation / defense slides |
| `AbaiMarket_UseCase.pdf` | Single-page vector PDF (from the PlantUML source) | Print / appendix |
| `USE_CASE_DOCUMENTATION.md` | This document | Written reference with endpoint mapping |

> **Regenerating the renders.** Render with a current PlantUML (1.2026.x, which needs Java 11+) and raise the image cap: `java -DPLANTUML_LIMIT_SIZE=16384 -jar plantuml.jar -tsvg AbaiMarket_UseCase.puml`, then export the SVG to PNG/PDF. Without that flag PlantUML silently crops any diagram taller than 4,096 px — the previous render was cut off below the LGU Admin package and was missing the Super Admin use cases entirely.
>
> The `.drawio`, `.mmd`, and `.puml` are three independent, editable sources of the **same** use-case model. The `.png`/`.pdf` are rendered from the **PlantUML** source because it uses standard UML use-case notation (stick-figure actors, ellipse use cases, «include»/«extend»), which is the expected form for a defense. The Mermaid `.mmd` expresses the identical model as a flowchart for GitHub/Mermaid-Live viewing.
