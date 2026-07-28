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

**Actor generalization.** Buyer, Seller, LGU Admin, and Super Admin are all specializations of an **Authenticated User**. The "Common" use cases (messaging, seller-post engagement, AI Assistant, viewing active announcements, account management) belong to the Authenticated User and are therefore available to all four roles — this matches the shared `role:buyer,seller,lgu_admin,super_admin` route group in the code.

### 2.2 Secondary (external) actors

| Actor | Role in the system | Where it appears in code |
|---|---|---|
| **PayMongo** | Payment gateway. Hosts checkout and confirms payment via a server-to-server webhook. | `PayMongoService`, `OrderController::checkout` / `paymongoWebhook` |
| **Google OAuth** | Federated login provider. | `GoogleAuthController::redirect` / `callback` |
| **Gemini AI** | LLM that phrases the AI Assistant's grounded answers. | `GeminiService`, `AiAssistantController::ask` |
| **Email (SMTP)** | Outbound mail for verification and transactional notifications. | `EmailVerificationController`, `App\Mail\*` |

---

## 3. Use case catalog (with endpoint mapping)

Each row is a use case and the concrete endpoint(s) that implement it. `✔ verified` = also requires a verified email.

### 3.1 Public / Pre-login (Guest)

| Use case | Endpoint(s) |
|---|---|
| Register account | `POST /auth/register` |
| Log in | `POST /auth/login` |
| Log in with Google | `GET /auth/google/redirect`, `GET /auth/google/callback` |
| Verify email | `GET /email/verify/{id}/{hash}`, `POST /email/resend` |
| Browse listings | `GET /listings` |
| View listing details | `GET /listings/{listing}` |
| View seller profiles & posts | `GET /sellers`, `GET /sellers/{seller}` |
| View municipalities | `GET /municipalities` |

*«include»* Register and Verify email → **Email (SMTP)**; Log in with Google → **Google OAuth**.

### 3.2 Common — all signed-in roles (`role:buyer,seller,lgu_admin,super_admin`, ✔ verified)

| Use case | Endpoint(s) |
|---|---|
| Manage account | `GET /auth/me`, `POST /auth/logout`, `PATCH /auth/password` |
| Send / edit / delete messages | `GET /messages/threads`, `GET /messages/thread/{user}`, `POST /messages`, `PATCH /messages/{message}`, `DELETE /messages/{message}`, `PATCH /messages/thread/{user}/read` |
| Like & comment on seller posts | `POST /seller-posts/{post}/like`, `POST /seller-posts/{post}/comments`, `DELETE /seller-posts/comments/{comment}` |
| Ask AI Assistant (EN/Tagalog/Bisaya) | `POST /ai-assistant/ask`, `GET /ai-assistant/history` |
| View active announcements | `GET /announcements/active` |

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
| Manage notifications | `GET /buyer/notifications`, `PATCH /buyer/notifications/read-all`, `PATCH /buyer/notifications/{notification}/read` |

*«include»* Checkout & pay → **PayMongo** (hosted checkout + `POST /paymongo/webhook`, which is unauthenticated by design). *«extend»* Add to cart → Place order (a cart item is checked out through the same place-order flow).

### 3.4 Seller / Hatchery (`role:seller`, ✔ verified)

| Use case | Endpoint(s) |
|---|---|
| Manage hatchery profile | `PATCH /seller/profile`, `POST/DELETE /seller/profile/picture`, `POST/DELETE /seller/profile/cover-photo` |
| Manage listings & media | `POST/PATCH/DELETE /listings...`, `POST/DELETE /listings/{listing}/media...`, `PATCH /listings/{listing}/media/reorder` |
| Publish seller posts (farm updates) | `POST/PATCH/DELETE /seller/posts...`, `POST/DELETE /seller/posts/{post}/media...` |
| Update order delivery status | `PATCH /orders/{order}/status` |
| Add seller notes to order | `PATCH /orders/{order:order_number}/notes` |
| Rate buyer (completed order) | `POST /orders/{order}/rate-buyer` |
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
| Manage listings (global) | `GET /super-admin/listings...`, `PATCH .../approve\|reject\|archive\|update`, `DELETE /super-admin/listings/{listing}` |
| Manage announcements | `GET/POST /super-admin/announcements`, `PATCH/DELETE /super-admin/announcements/{announcement}` |
| Remove reviews & ratings | `DELETE /super-admin/reviews/{review}`, `DELETE /super-admin/buyer-ratings/{rating}` |
| Reports, activity & moderation logs | `GET /super-admin/reports...`, `GET /super-admin/activity-log...`, `GET /super-admin/moderation-log` |
| Manage profile picture & notifications | `POST/DELETE /super-admin/profile/picture`, `GET /super-admin/notifications`, `PATCH .../read`, `GET /super-admin/users` |

---

## 4. Relationships in the diagram

| Relationship | Meaning | Basis in code |
|---|---|---|
| Actor generalization (Buyer/Seller/LGU/Admin → Authenticated User) | The four roles inherit the Common use cases | shared `role:buyer,seller,lgu_admin,super_admin` group |
| «include» Checkout & pay → PayMongo | Paying always invokes the gateway | `OrderController::checkout` → `PayMongoService` |
| «include» Ask AI Assistant → Gemini AI | Answering invokes the model (with scripted fallback) | `GeminiService::answer` |
| «include» Register / Verify email → Email | Both send mail | `EmailVerificationController`, mailables |
| «include» Log in with Google → Google OAuth | Federated login | `GoogleAuthController` |
| «extend» Add to cart → Place order | A saved cart item is optionally converted into an order | `CartController` + `OrderController::store` |

---

## 5. Key business rules reflected in the use cases

- **Escrow, not instant payout.** "Checkout & pay" only *holds* funds (`payment.status = paid_held`). Money is released only when an LGU Admin runs **"Approve seller earnings"**, which creates the immutable settlement. This is why the LGU actor sits between the buyer's payment and the seller's wallet.
- **Municipality scoping.** Every LGU Admin use case operates only on data in that admin's own municipality; the Super Admin's equivalents are platform-wide. Same verb, different scope — represented by two separate packages.
- **Reversible rejection.** "Hold / reject / reopen earnings" exists because a rejected order's payment stays held; the reopen path returns it to the approval queue.
- **Two-directional feedback.** "Review seller" (buyer→seller) and "Rate buyer" (seller→buyer) are distinct use cases, each allowed once per completed order.
- **Verified email required.** Every authenticated use case is behind the `verified` middleware; an unverified account can log in but cannot transact.

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

> The `.drawio`, `.mmd`, and `.puml` are three independent, editable sources of the **same** use-case model. The `.png`/`.pdf` are rendered from the **PlantUML** source because it uses standard UML use-case notation (stick-figure actors, ellipse use cases, «include»/«extend»), which is the expected form for a defense. The Mermaid `.mmd` expresses the identical model as a flowchart for GitHub/Mermaid-Live viewing.
