# AbaiMarket — Entity Relationship Diagram (ERD) Documentation

**Project:** AbaiMarket — Fisheries Fingerling Marketplace
**Program:** BSIT Capstone Project
**Backend stack:** Laravel (MySQL), Sanctum auth, role middleware
**Scope of this document:** the *actual* persisted data model, derived directly from the Laravel migrations, Eloquent models, and their foreign-key definitions. Nothing here is assumed — every table, column, constraint, and relationship below exists in the implementation.

---

## 1. How this ERD was derived (validation method)

This ERD was reverse-engineered from the codebase, not from a design intent:

| Source studied | What it confirmed |
|---|---|
| `database/migrations/*` | Table names, columns, data types, defaults, nullability, unique keys, indexes |
| Foreign-key definitions (`->constrained()`, `->cascadeOnDelete()`, `->nullOnDelete()`) | Which relationships have real DB-level constraints and their on-delete behavior |
| `app/Models/*` (Eloquent relations) | Relationship direction and cardinality (`hasOne`, `hasMany`, `belongsTo`) |
| `routes/api.php` + `app/Http/Middleware/EnsureRole.php` | Which roles own/scope which data (informs the Use Case model) |

**Framework-only tables are intentionally excluded** from the domain ERD because they are not part of the business model: `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens` (Sanctum). They exist in the database but carry no domain relationships.

The domain model contains **26 entities**.

---

## 2. Entity catalog

Every column is listed. Type names are the Laravel/MySQL types from the migrations. `PK` = primary key, `FK` = foreign key, `UK` = unique. All tables carry `created_at` / `updated_at` timestamps unless noted.

### 2.1 Identity & location

#### `users`
The single account table for all four roles.

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| name | string | |
| email | string | **UK** |
| email_verified_at | timestamp | nullable |
| password | string | hashed |
| google_id | string | nullable, **UK** (Google OAuth) |
| role | string | `buyer` \| `seller` \| `lgu_admin` \| `super_admin` (default `buyer`) |
| municipality_id | bigint | nullable — **application-level FK only**, see §4.1 |
| phone | string | nullable |
| status | string | `active` \| `suspended` \| `disabled` (default `active`) |
| profile_picture | string | nullable |
| remember_token | string | framework |

#### `municipalities`
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| name | string | **UK** |
| province | string | default `Cebu` |

#### `buyer_profiles` — 1:1 extension of a buyer `users` row
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | FK → users (**cascade**) |
| municipality_id | bigint | nullable, FK → municipalities (**nullOnDelete**) |
| farm_name, water_source, address | string | nullable |
| pond_area | decimal(10,2) | nullable |
| bio | text | nullable |
| rating | decimal(3,2) | nullable — cached average of buyer ratings |
| ratings_count | int | default 0 |

#### `seller_profiles` — 1:1 extension of a seller `users` row (the "hatchery")
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | FK → users (**cascade**) |
| municipality_id | bigint | FK → municipalities (**cascade**) |
| hatchery_name | string | |
| description | text | nullable |
| rating | decimal(3,2) | default 0 — cached average of seller reviews |
| verified | boolean | default false (LGU verifies) |
| status | string | `pending` \| `verified` \| `suspended` |
| farming_methods, fish_raising_practices, farm_history, feeding_practices, certifications | text | nullable |
| address, profile_picture, cover_photo, water_source | string | nullable |
| gallery | json | nullable |
| years_experience | smallint | nullable |

### 2.2 Catalog

#### `listings` — a fingerling offering
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| municipality_id | bigint | FK → municipalities (**cascade**) |
| species, scientific_name, title, average_size | string | scientific_name/average_size nullable |
| description | text | nullable |
| quantity | int (unsigned) | current stock |
| price_per_piece | decimal(8,2) | |
| availability_status | string | default `in_stock` |
| approval_status | string | `pending` \| `approved` \| `rejected` \| `archived` |
| rejection_reason | text | nullable |

#### `listing_media` — photos/videos for a listing
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| listing_id | bigint | FK → listings (**cascade**) |
| type | string | `photo` \| `video` |
| title | string | |
| url | string | nullable |
| position | int | default 0 (ordering) |

### 2.3 Ordering & payment

#### `orders`
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| order_number | string | **UK** (e.g. `FG-XXXXXX`) |
| buyer_id | bigint | FK → users (**cascade**) |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| listing_id | bigint | FK → listings (**cascade**) |
| quantity | int | |
| unit_price, total_amount | decimal | |
| status | string | `placed` \| `confirmed` \| `in_transit` \| `completed` \| `cancelled` \| `failed` |
| pickup_notes | string | nullable |
| seller_notes | text | nullable |
| lgu_review_status | string | nullable: `on_hold` \| `rejected` |
| lgu_review_reason | string | nullable |
| lgu_reviewed_at | timestamp | nullable |
| lgu_reviewed_by | bigint | nullable, FK → users (**nullOnDelete**) |

> **Note on delivery status:** the UI label "Out for Delivery" is a *display* rendering of `status = in_transit`; there is no separate column.

#### `payments` (Eloquent model `MockPayment`, table `payments`) — 1:1 with an order
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| order_id | bigint | FK → orders (**cascade**) |
| amount | decimal | |
| status | string | `pending` \| `checkout_created` \| `paid_held` \| `released` \| `failed` |
| provider | string | default `paymongo` |
| provider_reference, checkout_url | string | nullable |
| released_at | timestamp | nullable |

#### `payment_logs` — provider event audit for a payment
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| payment_id | bigint | FK → payments (**cascade**) |
| event | string | |
| payload | json | nullable |

#### `settlements` — immutable revenue split, created on LGU earnings approval
One row **per order** (unique). Percentages/shares are snapshotted so later commission changes never rewrite history.

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| order_id | bigint | FK → orders, **UNIQUE** (**cascade**) |
| payment_id | bigint | FK → payments (**cascade**) |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| municipality_id | bigint | FK → municipalities (**cascade**) |
| approved_by | bigint | nullable, FK → users (**nullOnDelete**) |
| gross_amount, seller_share, lgu_share, platform_share | decimal(12,2) | frozen shares |
| seller_percent, lgu_percent, platform_percent | decimal(5,2) | frozen percentages |
| status | string | default `settled` |
| settled_at | timestamp | |

### 2.4 Payouts

#### `withdrawal_requests` — seller payout requests
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| method | string | `gcash` \| `maya` \| `bank_transfer` |
| account_name, account_number | string | |
| amount | decimal | |
| platform_fee | decimal | default 0 — frozen 6% payout fee at request time |
| status | string | `pending` \| `approved` \| `paid` \| `rejected` |
| rejection_reason | text | nullable |
| reviewed_at, paid_at | timestamp | nullable |

#### `lgu_withdrawal_requests` — municipality payout requests
Mirrors seller payouts but scoped to a municipality (shared municipal revenue). **No** `platform_fee` — the platform's cut is already taken at settlement.

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| municipality_id | bigint | FK → municipalities (**cascade**) |
| requested_by | bigint | nullable, FK → users (**nullOnDelete**) — who submitted |
| method, account_name, account_number | string | |
| amount | decimal | |
| status | string | `pending` \| `approved` \| `paid` \| `rejected` |
| rejection_reason | text | nullable |
| reviewed_at, paid_at | timestamp | nullable |

### 2.5 Feedback (both directions)

#### `reviews` — buyer → seller, one per order
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| order_id | bigint | FK → orders (**cascade**) |
| buyer_id | bigint | FK → users (**cascade**) |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| rating | tinyint | 1–5 |
| comment | text | nullable |
| title | string | nullable |

#### `buyer_ratings` — seller → buyer, one per order (unique)
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| order_id | bigint | FK → orders, **UNIQUE** (**cascade**) |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| buyer_id | bigint | FK → users (**cascade**) |
| rating | tinyint | 1–5 |
| comment | text | nullable |

### 2.6 Cart, messaging, notifications

#### `cart_items` — buyer "buy later" shortlist (a bookmark, not a reservation)
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| buyer_id | bigint | FK → users (**cascade**) |
| listing_id | bigint | FK → listings (**cascade**) |
| quantity | int | default 1 |
| — | — | **UNIQUE(buyer_id, listing_id)** |

#### `messages` — direct messaging between any two users
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| sender_id | bigint | FK → users (**cascade**) |
| receiver_id | bigint | FK → users (**cascade**) |
| body | text | |
| read_at, edited_at, deleted_at | timestamp | nullable (soft edit/delete markers) |

#### `notifications` (model `AppNotification`) — in-app notifications
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | FK → users (**cascade**) |
| type, title | string | |
| body | text | nullable |
| read_at | timestamp | nullable |

### 2.7 Content & engagement

#### `seller_posts` — a hatchery's farm-update feed
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| seller_profile_id | bigint | FK → seller_profiles (**cascade**) |
| body | text | nullable |

#### `seller_post_media`
| id | bigint | PK |
|---|---|---|
| seller_post_id | bigint | FK → seller_posts (**cascade**) |
| type, title | string | |
| url | string | nullable |
| position | int | default 0 |

#### `seller_post_likes` — one like per user per post
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| seller_post_id | bigint | FK → seller_posts (**cascade**) |
| user_id | bigint | FK → users (**cascade**) |
| — | — | **UNIQUE(seller_post_id, user_id)** |

#### `seller_post_comments`
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| seller_post_id | bigint | FK → seller_posts (**cascade**) |
| user_id | bigint | FK → users (**cascade**) |
| body | text | |

### 2.8 AI, announcements, audit

#### `ai_chats` (model `AiConversation`) — AI assistant chat history
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | nullable, FK → users (**nullOnDelete**) |
| language | string | default `Bisaya` (English \| Tagalog \| Bisaya) |
| message, response | text | |
| data_subject | string | nullable (carries implicit subject across turns) |

#### `ai_usage_events` — aggregate-only AI telemetry (no message content)
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | nullable, FK → users (**nullOnDelete**) |
| role | string | indexed |
| category | string | nullable, indexed |
| was_fallback | boolean | default false |
| response_time_ms | int | nullable |

#### `announcements` — platform-wide broadcasts
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| title | string | |
| body | text | |
| category | string | `maintenance` \| `update` \| `policy` \| `holiday` \| `general` |
| created_by | bigint | nullable, FK → users (**nullOnDelete**) |
| starts_at, expires_at, notified_at | timestamp | nullable |

#### `moderation_logs` — suspend/reinstate audit trail
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint | FK → users (**cascade**) — the moderated account |
| role | string | snapshot of subject role |
| moderator_id | bigint | nullable, FK → users (**nullOnDelete**) |
| action | string | `suspended` \| `reinstated` |
| reason | string | nullable |
| notes | text | nullable |
| resulting_status | string | |

#### `activity_logs` (model `ActivityLogEntry`) — global audit trail
| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| actor_id | bigint | nullable, FK → users (**nullOnDelete**) |
| actor_role | string | nullable |
| action | string | |
| target_user_id | bigint | nullable, FK → users (**nullOnDelete**) |
| municipality_id | bigint | nullable, FK → municipalities (**nullOnDelete**) |
| reference_type, reference_number, description | string/text | nullable |

---

## 3. Relationship summary (cardinality)

Notation: **1** = exactly one, **0..1** = optional one, **\*** = many.

| Parent | | Child | Meaning |
|---|---|---|---|
| municipalities | 1 — \* | users* / buyer_profiles / seller_profiles / listings / settlements / lgu_withdrawal_requests / activity_logs | location & scoping |
| users | 1 — 0..1 | buyer_profiles | a buyer's profile |
| users | 1 — 0..1 | seller_profiles | a seller's hatchery |
| users | 1 — \* | orders (as buyer) | purchases |
| users | 1 — \* | orders (as lgu_reviewed_by) | LGU review authorship |
| users | 1 — \* | cart_items / reviews / buyer_ratings / notifications / ai_chats / ai_usage_events | per-user records |
| users | 1 — \* | messages (as sender) / messages (as receiver) | two distinct FKs |
| users | 1 — \* | announcements (created_by) / settlements (approved_by) / lgu_withdrawal_requests (requested_by) | authored/approved records |
| users | 1 — \* | seller_post_likes / seller_post_comments | engagement |
| users | 1 — \* | moderation_logs (user_id) / moderation_logs (moderator_id) | subject vs. moderator |
| users | 1 — \* | activity_logs (actor_id) / activity_logs (target_user_id) | actor vs. target |
| seller_profiles | 1 — \* | listings / orders / reviews / buyer_ratings / withdrawal_requests / settlements / seller_posts | seller-owned records |
| listings | 1 — \* | listing_media / orders / cart_items | catalog usage |
| orders | 1 — 1 | payments | escrow payment |
| orders | 1 — 0..1 | reviews / buyer_ratings / settlements | post-completion records |
| payments | 1 — \* | payment_logs | provider events |
| payments | 1 — 0..1 | settlements | split source |
| seller_posts | 1 — \* | seller_post_media / seller_post_likes / seller_post_comments | feed content |

**Users→Orders appears twice** because an order references a user through two different columns: `buyer_id` (the purchaser) and `lgu_reviewed_by` (the LGU admin who held/rejected it). Likewise `messages`, `moderation_logs`, and `activity_logs` each reference `users` through two roles.

---

## 4. Implementation notes that matter for accuracy

### 4.1 `users.municipality_id` is an application-level reference, not a DB constraint
In the base migration this column is declared `$table->foreignId('municipality_id')->nullable();` **without** `->constrained()`. So there is **no DB-level foreign key** on it, even though `User::municipality()` defines a `belongsTo(Municipality::class)`. The ERD shows this relationship (dashed / "app-level") because the model and application logic treat it as a reference, but it is not enforced by the database. Every *other* municipality reference in the schema **is** a real constrained FK.

### 4.2 On-delete behavior
- **cascade** (child deleted with parent): profiles, listings, media, orders, payments, payment_logs, reviews, buyer_ratings, cart_items, messages, notifications, settlements, withdrawal_requests, seller_posts and their media/likes/comments, moderation_logs.user_id.
- **nullOnDelete** (FK set to NULL, row kept): `orders.lgu_reviewed_by`, `settlements.approved_by`, `lgu_withdrawal_requests.requested_by`, `announcements.created_by`, `ai_chats.user_id`, `ai_usage_events.user_id`, `moderation_logs.moderator_id`, all `activity_logs` FKs, `buyer_profiles.municipality_id`.

This is why account **removal** is blocked for accounts with order history — deleting such a user would cascade away their orders/payments/settlements and destroy the financial record.

### 4.3 Uniqueness rules enforcing business logic
- `orders.order_number` — human-facing order ID is unique.
- `settlements.order_id` — **one settlement per order** (a payment can be split only once).
- `buyer_ratings.order_id` — **one seller→buyer rating per order**.
- `cart_items (buyer_id, listing_id)` — a listing appears at most once in a buyer's cart (re-adding tops up quantity).
- `seller_post_likes (seller_post_id, user_id)` — a like is idempotent, one per user per post.
- `users.email`, `users.google_id`, `municipalities.name` — unique.

> `reviews` has **no** DB unique on `order_id`; the "one review per order" rule is enforced in `ReviewController`. This is documented as-is rather than shown as a DB constraint, because it is not one.

### 4.4 Escrow / revenue flow reflected in the schema
`payments.status = paid_held` (funds captured, not released) → LGU approval creates the immutable `settlements` row and flips the payment to `released` → seller's `settlements.seller_share` becomes withdrawable via `withdrawal_requests`; the municipality's `settlements.lgu_share` becomes withdrawable via `lgu_withdrawal_requests`. A rejected order keeps `payment.status = paid_held` but has no settlement, which is why rejected earnings are excluded from a seller's projected balance.

---

## 5. Files in this folder

| File | Format | Use |
|---|---|---|
| `AbaiMarket_ERD.drawio` | Draw.io / mxGraph XML | Fully editable — open at [app.diagrams.net](https://app.diagrams.net) or the VS Code Draw.io extension |
| `AbaiMarket_ERD.mmd` | Mermaid | Renders in Mermaid Live Editor and GitHub |
| `AbaiMarket_ERD.puml` | PlantUML | Renders with `plantuml.jar` or the PlantUML server |
| `AbaiMarket_ERD.png` | Raster image (rendered from the Mermaid source) | Presentation / defense slides |
| `AbaiMarket_ERD.pdf` | PDF (rendered from the Mermaid source) | Print / appendix |
| `ERD_DOCUMENTATION.md` | This document | Written reference |

> The `.drawio`, `.mmd`, and `.puml` are three independent, editable sources of the **same** model; the `.png`/`.pdf` are the presentation renders (Mermaid). All were generated from the verified schema, so they agree.
