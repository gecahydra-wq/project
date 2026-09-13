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
| `07-landing.html` | Public landing — storefront hero, photo-led listing cards, Shop by Species row | Public |
| `08-seller-profile.html` | Seller profile — hatchery header + detailed buyer reviews (average, rating spread, verified-purchase entries) | Public |

These eight cover the whole app's visual language: the **public storefront layer** (hero, photo-led product cards, category row, review summary), the **auth layout**, the **role dashboard shell** (fish-photo sidebar + profile chip + nav + main area), **stat tiles**, **data tables**, **listing cards**, **action cards**, **badges**, **forms**, and the **floating AI button**. Every other screen in the app is a recombination of these same components.

## Folder contents

```
figma/
├── index.html                 ← start here (visual gallery)
├── 01-login.html … 08-seller-profile.html
├── README.md
├── assets/
│   ├── app.css                ← the app's real stylesheet (source of truth for the design)
│   ├── mock.css               ← thin overrides: local image path + freeze entry animations
│   ├── sidebar-bg.jpg          ← the sidebar background photo
│   └── placeholders/           ← avatar + species artwork (bangus, tilapia, catfish, …)
└── previews/                   ← PNG thumbnails used by index.html (07 and 08 not captured yet)
```

## Design system at a glance (pull these into Figma styles)

All values live in `:root` at the top of `assets/app.css`. The key tokens:

**Brand colours** — four hues; everything else is a lighter/darker step of one of them
- Navy `#0b2e4f` (header, sidebar, footer) · Navy-deep `#072135`
- Teal `#0e7c86` (buttons, links, active tabs) · Teal-hover `#0a626a` · Teal-press `#084d54` · Teal-soft `#e6f4f5`
- Seafoam `#a8e6da` (tags, icons on dark) · Seafoam-tint `#d8f4ee` · Seafoam-wash `#eefaf8`
- Coral `#ff6b4a` (prices and the single primary CTA only) · Coral-hover `#ff7a5c`

**Text-safe variants** — three brand colours fail WCAG AA *as type*, so each has a darker sibling used wherever the colour becomes text: coral-text `#c43f22`, teal-text `#0b6870`, mist-text `#616e78`. Use these for text in Figma, not the originals.

**Neutrals** — Sea mist `#eef3f5` (page) · White `#ffffff` (cards) · Hairline `#dce4e8` (borders) · Slate `#22333f` (body text, never pure black) · Mist `#8a97a3` (non-text uses only)

**Status** — success `#1b6b3a` / bg `#e3f5e9` · warning `#7a4e06` / bg `#fdf0d9` · danger `#8c2320` / bg `#fbe7e6` · info = Navy / bg `#e4eef5`

**Type** — Inter (system-ui fallback). Sizes are `--fs-*` custom properties in `app.css`.

**Radius** — buttons and inputs 10 px (`--radius-sm`), cards 14 px (`--radius-md`), large panels 24 px (`--radius-lg`), pills 999 px. Every `button`/`.button` is a **full pill**.

**Elevation** — whisper-soft and Navy-tinted, never neutral black: `--shadow-sm` `0 1px 2px rgba(11,46,79,.05)` at rest, `--shadow-md` two-layer on hover, `--shadow-float` only for modals over a dimmed backdrop. Surfaces lead with the 1 px Hairline; the shadow is a hint, not a lift.

**Spacing** — a 4 px-based `--space-*` scale; `--space-5` (1.5 rem) is the card's inner padding.

**Badges** — role pills use `.badge-role-buyer/seller/lgu/admin`; status pills use `.badge-success/info/warning/danger/neutral`.

## Notes for converting to Figma

- The **sidebar** is a fixed 280 px column with a layered background: brand gradient → gradient wash → `sidebar-bg.jpg` photo → a dark scrim that keeps the white nav text legible.
- Icons are **Lucide** (inline SVG here). In Figma, use the free Lucide icon set for 1:1 matches.
- The mocks freeze the app's entry animations to their final frame (see `mock.css`) so screenshots/inspection show the settled design.
- Fonts: install **Inter** in Figma for exact type matching.

## Keeping these in sync

`assets/app.css` is a verbatim copy of `frontend/src/App.css`. When the app’s stylesheet changes, re-copy it:

```bash
cp frontend/src/App.css figma/assets/app.css
```

The `previews/*.png` thumbnails are captured by hand — open a mock in a browser and screenshot it into `previews/` using the same filename as the page. `07-landing.png` and `08-seller-profile.png` have **not** been captured yet, so those two gallery tiles show a broken image until you do. The other six were captured before the current design pass, so re-shoot them too if you want the gallery to match.

Pages `07` and `08` wrap their content in `<div class="public-shell">`, matching `PublicLayout` in the app — the `PUBLIC STOREFRONT LAYER` rules at the end of `app.css` only apply inside that wrapper, so omitting it would render the public pages with dashboard styling instead.
