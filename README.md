# FishMarket - Fish Fingerlings Web-Based Marketplace

Capstone marketplace based on the ABAI PPT requirements. It connects Buyers/Fish Farmers, Sellers/Hatcheries, LGU Admins, and a Super Admin with role-based dashboards, listing governance, PayMongo checkout, and Gemini AI farming assistance.

## Stack

- Frontend: React, Vite, Tailwind CSS, React Router, Axios, React Query, React Hook Form, shadcn-style card/form/table components
- Backend: Laravel 12, Sanctum authentication, REST API, Laravel Storage-ready upload fields
- Database: MySQL-ready migrations; local `.env` currently uses SQLite for quick demo
- Payment: PayMongo Checkout API with demo fallback if keys are blank
- AI: Google Gemini API with local educational fallback if key is blank

## Run Locally

```bash
start-backend.cmd
start-frontend.cmd
```

Or manually:

```bash
cd backend
composer install
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000

cd ../frontend
npm install
npm.cmd run dev -- --host 127.0.0.1 --port 5173
```

## Routes

- Public: `/`, `/browse`, `/sellers`, `/about`, `/login`, `/register`
- Buyer: `/buyer/dashboard`
- Seller: `/seller/dashboard`
- LGU Admin: `/lgu/dashboard`
- Super Admin: `/admin/dashboard`

One login page is used for all roles. Buyers and sellers may register. LGU Admins and Super Admins are seeded/manual-created.

## Seeded Administrator Accounts

Running `php artisan migrate:fresh --seed` produces a clean environment with only the two administrator
accounts below. There are no seeded buyers or sellers — those register normally through the app, and the
marketplace starts with zero listings.

- LGU Admin: `lgu@gmail.com` / `admin2026`
- Super Admin: `superadmin@gmail.com` / `admin2026`

## Environment Keys

Set these in `backend/.env` when ready:

```env
FRONTEND_URL=http://127.0.0.1:5173
PAYMONGO_PUBLIC_KEY=
PAYMONGO_SECRET_KEY=
PAYMONGO_WEBHOOK_SECRET=
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.0-flash
```

For MySQL, update:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fishmarket
DB_USERNAME=root
DB_PASSWORD=
```

## Verification

```bash
cd frontend
npm.cmd run lint
npm.cmd run build

cd ../backend
php artisan test
```
