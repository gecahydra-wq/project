# AbaiMarket — Design Mockups (Figma reference)

Static **HTML mockups** of the key AbaiMarket screens, one per role. They are built with the application's **real stylesheet** (`assets/app.css`, copied verbatim from `frontend/src/App.css`), so what you see here matches the live app pixel-for-pixel. Use them as a visual reference to rebuild the UI as Figma frames.

> These are **design references only** — no JavaScript, no backend, no interactivity. Buttons and links don't do anything (except the sidebar/gallery links that jump between mock pages).

## How to view

Open **`index.html`** in any browser — it's a gallery that links to every screen with a live preview thumbnail. Or open any `NN-*.html` file directly.

Everything is local and self-contained; no internet connection is needed.

## Screens

| File | Screen | Role |
|---|---|---|
| `01-login.html` | Login &amp; registration (brand panel + auth card + Google sign-in) | Public |
| `02-buyer-dashboard.html` | Buyer dashboard — stat tiles, recent-orders table, notifications | Buyer |
| `03-buyer-browse.html` | Marketplace — filters + listing card grid with ratings/prices | Buyer |
| `04-seller-dashboard.html` | Seller dashboard — KPI tiles + order management (status actions, rate-buyer) | Seller |
| `05-lgu-earnings.html` | LGU seller-earnings approval queue + rejected/reopen transactions | LGU Admin |
| `06-admin-users.html` | Super Admin buyer moderation — suspend / reinstate / remove with reason | Super Admin |

These six cover the whole app's visual language: the **auth layout**, the **role dashboard shell** (fish-photo sidebar + profile chip + nav + main area), **stat tiles**, **data tables**, **listing cards**, **action cards**, **badges**, **forms**, and the **floating AI button**. Every other screen in the app is a recombination of these same components.

## Folder contents

```
figma/
├── index.html                 ← start here (visual gallery)
├── 01-login.html … 06-admin-users.html
├── README.md
├── assets/
│   ├── app.css                ← the app's real stylesheet (source of truth for the design)
│   ├── mock.css               ← thin overrides: local image path + freeze entry animations
│   ├── sidebar-bg.jpg          ← the sidebar background photo
│   └── placeholders/           ← avatar + species artwork (bangus, tilapia, grouper, …)
└── previews/                   ← PNG thumbnails used by index.html
```

## Design system at a glance (pull these into Figma styles)

All values live in `:root` at the top of `assets/app.css`. The key tokens:

**Brand colours**
- Primary `#0b5a8a` · Primary-dark `#073b5c` · Primary-hover `#094a72`
- Teal `#0f9b8e` · Teal-dark `#0c7b71` · Teal-soft `#e3f7f4`

**Neutrals** — gray-50 `#f8fafc` → ink `#0f172a` (full ramp in `:root`)

**Status** — success `#15803d` / bg `#dcfce7` · warning `#b45309` / bg `#fef3c7` · danger `#b91c1c` / bg `#fee2e2` · info `#0369a1` / bg `#e0f2fe`

**Type** — Inter (system-ui fallback). Sizes are `--fs-*` custom properties in `app.css`.

**Radius / shadow / spacing** — `--radius-*`, `--shadow-*` custom properties in `app.css`.

**Badges** — role pills use `.badge-role-buyer/seller/lgu/admin`; status pills use `.badge-success/info/warning/danger/neutral`.

## Notes for converting to Figma

- The **sidebar** is a fixed 280 px column with a layered background: brand gradient → gradient wash → `sidebar-bg.jpg` photo → a dark scrim that keeps the white nav text legible.
- Icons are **Lucide** (inline SVG here). In Figma, use the free Lucide icon set for 1:1 matches.
- The mocks freeze the app's entry animations to their final frame (see `mock.css`) so screenshots/inspection show the settled design.
- Fonts: install **Inter** in Figma for exact type matching.
