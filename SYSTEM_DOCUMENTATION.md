# FishMarket — System Documentation

This document explains **how the FishMarket system works** from a software-engineering perspective: its objectives, the people who use it, every major workflow, the data it stores, and how it is secured. It is written so a new engineer, analyst, or reviewer can understand the whole system **without reading the source code**.

For setup instructions see [README.md](README.md); for code-level architecture see [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md).

---

## Table of Contents

- [System Overview](#system-overview)
- [System Objectives](#system-objectives)
- [Actors](#actors)
- [Complete Workflows](#complete-workflows)
  - [Registration](#1-registration)
  - [Login](#2-login)
  - [Email Verification](#3-email-verification)
  - [Google Login](#4-google-login)
  - [Listing Approval](#5-listing-approval)
  - [Ordering](#6-ordering)
  - [Payment](#7-payment)
  - [Revenue Sharing](#8-revenue-sharing)
  - [Wallet](#9-wallet)
  - [Payout](#10-payout)
  - [Reviews & Ratings](#11-reviews--ratings)
  - [Messaging](#12-messaging)
  - [AI Assistant](#13-ai-assistant)
  - [Notifications](#14-notifications)
  - [Reports](#15-reports)
  - [Announcements](#16-announcements)
  - [Moderation](#17-moderation)
  - [Activity Logs](#18-activity-logs)
  - [Order Tracking](#19-order-tracking)
  - [Withdrawal Tracking](#20-withdrawal-tracking)
- [Database Overview](#database-overview)
  - [Main Tables](#main-tables)
  - [Relationships](#relationships)
- [Security](#security)
  - [Role Permissions](#role-permissions)
- [Future Improvements](#future-improvements)

---

## System Overview

FishMarket is a **web-based marketplace for fish fingerlings** that connects fish farmers (buyers) with hatcheries (sellers), governed by Local Government Unit (LGU) administrators and a central Super Admin. It is a two-part system:

- A **single-page web application** (the interface every user interacts with).
- A **REST API and database** (where all rules, money movement, and data live).

The defining characteristic of FishMarket is that it is **governed and escrow-based**. Unlike a typical marketplace where a seller is paid as soon as a buyer checks out, FishMarket **holds a buyer's payment until the local government verifies that the transaction actually completed**. Only then is the money split and released. This protects buyers, keeps local government informed of commerce in their municipality, and gives the platform a trustworthy audit trail of every transaction.

The system is organized around **four roles** and **municipalities**. A seller belongs to a municipality; the LGU admin for that municipality governs that seller's listings, verifies their transactions, and can see their local reports. The Super Admin sits above all municipalities.

---

## System Objectives

1. **Enable safe online trade of fingerlings** between local buyers and hatcheries.
2. **Keep local government in the loop** — every seller and transaction is verified by the LGU for that municipality before money moves.
3. **Protect buyers** by holding funds in escrow until delivery is verified.
4. **Distribute revenue automatically and transparently** between sellers, LGUs, and the platform.
5. **Provide each role with the tools it needs** — dashboards, wallets, analytics, and reports scoped to what that role is allowed to see.
6. **Maintain a complete, tamper-resistant audit trail** of governance and financial actions.
7. **Lower the knowledge barrier** with a multilingual, database-driven AI assistant that answers real questions about the platform.
8. **Communicate reliably** through in-app notifications and transactional email at every important step.

---

## Actors

### Buyer (Fish Farmer)
Purchases fingerlings. A buyer can browse and search listings, view seller profiles and their "farm posts", place and pay for orders, track those orders, review and rate sellers after a completed order, like/comment on farm posts, message sellers, and view personal purchase analytics. A **suspended buyer** may still log in and browse but cannot order, pay, message, or review.

### Seller (Hatchery)
Sells fingerlings. A seller manages listings (with photos/videos) that require LGU approval before going public, maintains a public profile and a social "Farm Posts" feed, fulfils orders through delivery, operates a **Seller Wallet** and requests payouts, rates buyers after completed orders, and views the marketplace read-only (a seller cannot buy from another seller). A **suspended seller** is signed out and blocked from listing, receiving orders, and withdrawing.

### LGU Admin (Local Government Unit)
Governs a **single municipality**. The LGU admin verifies sellers, approves/rejects/archives their listings, **verifies completed transactions** (approve, hold, or reject the seller's earnings — the step that releases money), suspends/reinstates local sellers, operates an **LGU Wallet** and requests LGU payouts, moderates local reviews and ratings, and views municipality-scoped reports, analytics, and activity logs. An LGU admin can see and act on **only their own municipality's** data.

### Super Admin
Oversees the entire platform. The Super Admin runs an executive dashboard, creates and manages LGU admin accounts and municipalities, **approves and releases** seller and LGU payouts, suspends/reinstates any buyer/seller/LGU admin, manages listings platform-wide, moderates (removes) unfair reviews or ratings, publishes announcements, and views global activity logs, moderation logs, reports, and exports.

---

## Complete Workflows

### 1. Registration
Only **buyers and sellers** register themselves. A visitor submits name, email, password, role, and (for sellers) a municipality. The system creates the account plus a matching profile — a buyer profile, or a seller profile that starts in a **"pending"** state awaiting LGU verification. A verification email is sent. **No login token is issued yet** — the account cannot be used until the email is verified. LGU admins are created by a Super Admin; the Super Admin account is seeded during setup.

### 2. Login
A user submits email and password. The system checks three things in order: (1) the credentials are correct, (2) the email has been verified, and (3) the account is in good standing. A seller suspended by their LGU, or an LGU admin disabled by the Super Admin, is refused with a clear message. A **suspended buyer is still allowed to log in** (they are only restricted from transacting). On success the system issues a secure session token used for all subsequent requests. One login page serves every role, and the user is routed to their role's dashboard.

### 3. Email Verification
The verification email contains a **signed, time-limited link**. Clicking it proves ownership of the email address and activates the account — no separate login is required to verify, because the signed link itself is the proof (useful when the link is opened on a different device). Links expire after a set period; an expired or invalid link shows a friendly page with the option to request a new one. Verification is required before first login.

### 4. Google Login
A user can sign in with Google instead of a password. They are redirected to Google's consent screen; after approving, Google returns them to FishMarket. The system finds an existing account by email (never creating a duplicate) or creates a new **buyer** account, treats the email as already verified (Google verified it), applies the same suspension checks as normal login, and signs the user in. New Google accounts start as buyers.

### 5. Listing Approval
A seller creates a listing (species, price, quantity, description, media). New listings are **not public** — they start unapproved and appear in a queue for the LGU admin of the seller's municipality. The LGU admin can **approve** (the listing becomes publicly visible and orderable), **reject** (with a reason; the seller is emailed), or **archive** (remove it from the market). The Super Admin can perform the same actions platform-wide. Only approved listings from non-suspended sellers appear in the public catalogue.

### 6. Ordering
A buyer chooses an approved listing and a quantity. The system validates the listing is available and in stock, creates the order with a unique **order number (FG-XXXXXX)**, and **immediately reserves stock** by decrementing the listing quantity so the item cannot be oversold while the buyer proceeds to payment. The order begins in a "placed" state, and a pending payment record is created alongside it.

### 7. Payment
The buyer pays through **PayMongo** (the payment gateway). The system creates a hosted checkout session and sends the buyer to it; on return, payment is confirmed either by the buyer's redirect back to FishMarket **or** by PayMongo's server-to-server webhook — whichever arrives first captures the payment, and duplicates are safely ignored. A captured payment is marked **"held" (escrow)** — the money is not yet the seller's. The order moves to "paid", a receipt is emailed to the buyer, and the seller is notified of a new order. If the payment is declined or abandoned, the order is marked failed and the reserved stock is returned. When PayMongo keys are not configured, the system runs a **demo checkout** so the entire flow can still be exercised.

### 8. Revenue Sharing
Revenue is split in **two separately-timed stages**, using fixed, code-defined rules:

- **At LGU verification (settlement):** when the LGU approves a completed order, the gross amount is divided **96% to the seller** and **4% to the LGU municipality**. The platform takes nothing at this stage. This split is recorded as an **immutable settlement** — it never changes afterward, so historical figures stay accurate.
- **At withdrawal:** the platform earns a **6% payout fee** on the amount a seller withdraws. This fee is calculated when the withdrawal is requested and only counts as realized platform revenue once the Super Admin marks the withdrawal **paid**.

Because the split is fixed and each settlement stores the exact split that applied at approval time, all financial figures across the app remain consistent and reproducible.

### 9. Wallet
Both sellers and LGUs have a wallet that shows balances derived from settlements and withdrawals (never from a mutable running total):

- **Available Balance** — money already earned and settled, minus anything reserved or withdrawn; this is what can be withdrawn now.
- **Pending Balance** — a projection of what a seller *will* receive for payments that are paid but **not yet LGU-verified** (no settlement exists yet).
- **Processing Amount** — money tied up in a withdrawal that has been requested but not yet paid out.
- **Withdrawn Amount** — actual cash received, **after** the platform's payout fee.
- **Total Earnings** — everything earned (settled + projected), before fees.

The AI assistant answers wallet questions using these exact same figures, so the wallet page and the assistant always agree.

### 10. Payout
When a seller (or LGU) wants their money, they submit a **withdrawal request** specifying an amount and a payout method (e.g., GCash, Maya, bank transfer). The request reserves that amount from the available balance. The **Super Admin** reviews it and can **approve**, **reject** (with a reason), and finally **mark it paid** once the money has actually been sent. The seller's real cash received is the requested amount minus the 6% platform fee; the LGU's payout has no such fee. Every step notifies the requester and, at approval/release, sends an email. Seller withdrawals are tracked as **PAY-##** and LGU withdrawals as **LGU-##**.

### 11. Reviews & Ratings
FishMarket supports **two directions of feedback**, both tied to a specific completed order (one per order):

- **Buyer reviews a seller** — a star rating plus optional title/comment, after the order is completed. This updates the seller's average rating shown on their profile and listings.
- **Seller rates a buyer** — a star rating plus optional note, so other sellers can judge whether a buyer is reliable. This updates the buyer's rating shown to sellers and admins.

LGU admins and the Super Admin see **both** directions in a unified "Reviews & Ratings" view (filterable by direction) and can **remove** any entry that is unfair; removal recalculates the affected party's average and is recorded in the activity log.

### 12. Messaging
Any two users can exchange direct messages (for example, a buyer asking a seller about a listing). Messages are organized into conversation threads, show unread counts, and can be edited or deleted within a short window. Messaging is available to all four roles; suspended buyers cannot send messages.

### 13. AI Assistant
A floating AI assistant is available on every page. It is **role-aware** and **database-driven**: for marketplace questions it first pulls **real data from the database** scoped to what the asker is allowed to see, then falls back to ranked recommendations, then to the app's own built-in knowledge base. The AI model (Google Gemini) is only ever asked to phrase these real facts naturally — it is not allowed to invent FishMarket data. It automatically detects and replies in **English, Filipino, or Cebuano**, politely refuses off-topic questions, and serves a built-in answer if the AI service is unavailable. Only **aggregate usage statistics** are stored for analytics — never the contents of messages beyond a user's own chat history.

### 14. Notifications
The system raises **in-app notifications** at every important moment: payment received, new order, order confirmed/delivered, earnings awaiting LGU approval, listing approved/rejected, withdrawal approved/paid, account suspended/reinstated, and announcements. Notifications appear in each role's dashboard with unread counts and can be marked read individually or all at once. Many notifications are paired with a transactional email (see the Email System in the README).

### 15. Reports
Each role has analytics and reporting scoped to its permissions:

- **Buyer** — personal purchase analytics (spend over time, orders by status, favorite species).
- **Seller** — sales, revenue, orders by status, top species.
- **LGU** — municipality listings, sellers, orders, revenue (LGU share), and moderation activity.
- **Super Admin** — platform-wide executive metrics, revenue, orders, listings, sellers, and moderation.

Reports can be **exported to PDF or Excel** for the selected time period.

### 16. Announcements
The Super Admin can publish announcements to the whole platform. Announcements can be **scheduled** to appear at a future time and expire after a window; a background job checks every few minutes and publishes any that are due, notifying users. Active announcements appear as banners on role dashboards.

### 17. Moderation
Governance is layered by role:

- **LGU admins** verify and moderate within their municipality: seller verification, listing approval/rejection/archival, seller suspension/reinstatement, earnings verification (approve/hold/reject), and removal of unfair local reviews/ratings.
- **The Super Admin** moderates platform-wide: suspend/reinstate buyers, sellers, and LGU admins; manage any listing; and remove any review or rating.

Suspending an account carries a reason (and optional notes), notifies the affected user by email, and is recorded in both the moderation log and the activity log.

### 18. Activity Logs
Every significant governance and financial action flows into a **unified activity log / audit trail**: registrations, listing approvals/rejections/archivals, seller verification, account suspensions/reinstatements, earnings approvals and revenue distributions, seller and LGU payout lifecycle events, reviews and ratings, and review/rating removals. LGU admins see the activity for their municipality; the Super Admin sees everything. The log is searchable and filterable by category, action, date, and municipality, and each entry links to the relevant record.

### 19. Order Tracking
Every order has a **unified order number (FG-XXXXXX)** used consistently across orders, emails, notifications, the AI assistant, reports, and lookup. Buyers and sellers can look up an order by its number and see a **visual timeline** of its lifecycle — placed, paid, confirmed, in transit, completed — along with payment status and (for admins) settlement/payout status. LGU admins and the Super Admin can look up any order in their scope for investigation without navigating the seller/buyer hierarchy.

### 20. Withdrawal Tracking
Payouts are tracked separately for sellers and LGUs so their figures are never conflated:

- **Seller payouts — PAY-##**, tracked through pending → approved → paid (or rejected).
- **LGU payouts — LGU-##**, tracked the same way.

Each transition is visible to the requester, recorded in the activity log, and (at approval/release) emailed. The Super Admin's payout dashboards show both queues side by side with their statuses.

---

## Database Overview

The system uses a relational database (MySQL in production, SQLite for local/testing). All data access goes through the API; the interface never touches the database directly.

### Main Tables

**Identity & profiles**
- `users` — every account (buyer, seller, lgu_admin, super_admin), with role, status, and municipality.
- `buyer_profiles` — buyer details and cached buyer rating.
- `seller_profiles` — hatchery details, verification status, and cached seller rating.
- `municipalities` — the local government units sellers/LGU admins belong to.

**Catalogue**
- `listings` — fingerling listings (species, price, stock, approval status).
- `listing_media` — photos/videos attached to a listing.

**Orders & money**
- `orders` — each purchase, with its order number and lifecycle status.
- `payments` — the payment for an order and its escrow status.
- `payment_logs` — an audit record of every payment event/provider payload.
- `settlements` — the **immutable** record created at LGU approval, storing the revenue split.
- `withdrawal_requests` — seller payouts (PAY-##), including the frozen platform fee.
- `lgu_withdrawal_requests` — LGU payouts (LGU-##).

**Feedback**
- `reviews` — buyer → seller reviews.
- `buyer_ratings` — seller → buyer ratings.

**Social**
- `seller_posts`, `seller_post_media`, `seller_post_likes`, `seller_post_comments` — the "Farm Posts" feed and its engagement.

**Communication & governance**
- `messages` — direct messages between users.
- `app_notifications` — in-app notifications.
- `announcements` — global announcements (with scheduling).
- `activity_logs` — written audit-trail entries.
- `moderation_logs` — suspension/reinstatement records.

**AI**
- `ai_chats` — a user's own AI conversation history.
- `ai_usage_events` — aggregate-only AI usage metrics (no message content).

### Relationships

- A **user** has exactly one buyer profile *or* one seller profile, and belongs to a municipality.
- A **seller profile** has many listings (each with media), many farm posts, and many orders.
- An **order** belongs to a buyer, a seller, and a listing; it has one payment (with many payment-log events), and at most one settlement, one review, and one buyer rating.
- A **settlement** links an order/payment to the seller and municipality that earned from it; it is created only at LGU approval and never modified.
- **Withdrawal requests** belong to a seller; **LGU withdrawal requests** belong to a municipality.
- **Reviews** and **buyer ratings** each belong to an order and update the cached average rating on the seller/buyer profile.
- **Farm posts** have media, likes, and comments; likes/comments belong to the users who made them.

Financial records are effectively **append-only**: settlements are immutable, and each one carries the exact split that applied when it was created, so past figures never shift even if the rules change in the future.

---

## Security

- **Authentication:** every protected request must carry a valid session token issued at login (or Google sign-in). Unverified accounts cannot obtain a token.
- **Authorization by role:** each part of the API is gated to the roles allowed to use it; a request from the wrong role is rejected. On top of that, controllers enforce **ownership and scope** checks — a seller can only edit their own listings, a buyer can only see their own orders, and an LGU admin can only act within their own municipality.
- **Escrow protection:** money cannot reach a seller without an immutable settlement, and settlements are created only through LGU verification.
- **Suspension enforcement:** suspended accounts are blocked from the actions their status forbids (transacting, listing, withdrawing) at the API level, not just hidden in the interface.
- **Payment integrity:** payment confirmation is idempotent, so duplicate webhook/redirect calls cannot double-charge or double-credit; every payment event is logged.
- **Privacy:** the AI assistant stores only aggregate usage metadata; commenter contact details are never exposed in public payloads; passwords are hashed; email-verification links are signed and time-limited.
- **Auditability:** governance and financial actions are recorded in the activity and moderation logs, attributable to the actor who performed them.
- **Graceful failure:** missing third-party keys degrade to safe fallbacks (demo payments, local AI answers, logged emails) rather than exposing errors.

### Role Permissions

| Capability | Buyer | Seller | LGU Admin | Super Admin |
| --- | :---: | :---: | :---: | :---: |
| Browse marketplace | ✅ | ✅ (read-only) | ✅ | ✅ |
| Place & pay for orders | ✅ | ❌ | ❌ | ❌ |
| Create/manage listings | ❌ | ✅ (own) | ❌ | ❌ |
| Approve/reject listings | ❌ | ❌ | ✅ (own municipality) | ✅ (platform-wide) |
| Verify seller / verify earnings | ❌ | ❌ | ✅ (own municipality) | — |
| Review a seller | ✅ | ❌ | ❌ | ❌ |
| Rate a buyer | ❌ | ✅ | ❌ | ❌ |
| Remove a review/rating | ❌ | ❌ | ✅ (own municipality) | ✅ (any) |
| Wallet & request payout | ❌ | ✅ (seller) | ✅ (LGU) | — |
| Approve/release payouts | ❌ | ❌ | ❌ | ✅ |
| Suspend sellers | ❌ | ❌ | ✅ (own municipality) | ✅ (any) |
| Suspend buyers / LGU admins | ❌ | ❌ | ❌ | ✅ |
| Manage LGU admins & municipalities | ❌ | ❌ | ❌ | ✅ |
| Publish announcements | ❌ | ❌ | ❌ | ✅ |
| View activity/moderation logs | ❌ | ❌ | ✅ (own municipality) | ✅ (all) |
| Messaging & AI assistant | ✅ | ✅ | ✅ | ✅ |

*(“—” means the capability doesn't apply to that role.)*

---

## Future Improvements

- **Configurable commission rates** behind a Super Admin settings screen (currently fixed in code).
- **Real-time messaging and notifications** (live updates instead of on-refresh).
- **Queued email/notifications** for faster responses under load.
- **Additional payment methods and multi-currency** support.
- **Automated frontend testing** to complement the existing backend test suite.
- **Modularized frontend** and code-splitting for faster load times.
- **Native mobile applications** consuming the same API.
- **Richer AI capabilities** (e.g., image-based disease diagnosis, growth tracking) building on the existing AI data foundation.

---

_This document describes the FishMarket system's behavior and rules. For environment setup see the README; for code structure and conventions see the Developer Guide._
