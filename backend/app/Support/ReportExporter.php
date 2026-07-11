<?php

namespace App\Support;

use App\Models\FingerlingListing;
use App\Models\LguWithdrawalRequest;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Reports -- turns an already-scoped date range (and, for LGU,
 * municipality) into a flat table (title/columns/rows), then renders it as
 * PDF (via barryvdh/laravel-dompdf) or .xlsx (via phpoffice/phpspreadsheet).
 * Every query here reuses the same models/Support classes as the JSON
 * Reports endpoints (App\Support\RevenueReport, App\Support\
 * CommissionCalculator) and the same period scoping
 * (App\Support\AnalyticsPeriod, resolved by the caller) -- this is a
 * different *presentation* of the same underlying analytics, not a second
 * set of business-logic queries.
 */
class ReportExporter
{
    public const LGU_TYPES = ['sales', 'revenue', 'sellers'];

    public const SUPER_ADMIN_TYPES = ['marketplace-revenue', 'municipality-revenue', 'buyers', 'sellers', 'orders', 'listings', 'payouts'];

    public static function buildLguTable(string $type, int $municipalityId, Carbon $start, Carbon $end): array
    {
        return match ($type) {
            'sales' => self::lguSales($municipalityId, $start, $end),
            'revenue' => self::lguRevenue($municipalityId, $start, $end),
            'sellers' => self::lguSellers($municipalityId),
            default => throw new \InvalidArgumentException("Unknown LGU report type: {$type}"),
        };
    }

    public static function buildSuperAdminTable(string $type, Carbon $start, Carbon $end): array
    {
        return match ($type) {
            'marketplace-revenue' => self::marketplaceRevenue($start, $end),
            'municipality-revenue' => self::municipalityRevenue($start, $end),
            'buyers' => self::buyerStatistics(),
            'sellers' => self::sellerStatistics(),
            'orders' => self::ordersTable($start, $end),
            'listings' => self::listingsTable($start, $end),
            'payouts' => self::payoutsTable($start, $end),
            default => throw new \InvalidArgumentException("Unknown Super Admin report type: {$type}"),
        };
    }

    // ------------------------------------------------------------------
    // LGU report tables
    // ------------------------------------------------------------------

    private static function lguSales(int $municipalityId, Carbon $start, Carbon $end): array
    {
        $orders = Order::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))
            ->whereBetween('orders.created_at', [$start, $end])
            ->with(['buyer', 'sellerProfile', 'listing'])
            ->latest('orders.created_at')
            ->get();

        return [
            'title' => 'Sales Report',
            'columns' => ['Order #', 'Date', 'Buyer', 'Seller', 'Species', 'Qty', 'Total Amount', 'Status'],
            'rows' => $orders->map(fn ($o) => [
                $o->order_number,
                $o->created_at->format('Y-m-d'),
                $o->buyer?->name ?? 'Unknown',
                $o->sellerProfile?->hatchery_name ?? 'Unknown',
                $o->listing?->species ?? 'N/A',
                $o->quantity,
                number_format((float) $o->total_amount, 2),
                ucfirst($o->status),
            ])->all(),
        ];
    }

    private static function lguRevenue(int $municipalityId, Carbon $start, Carbon $end): array
    {
        $settlements = Settlement::where('municipality_id', $municipalityId)
            ->whereBetween('settled_at', [$start, $end])
            ->with(['order', 'sellerProfile'])
            ->latest('settled_at')
            ->get();

        return [
            'title' => 'Revenue Report',
            'columns' => ['Order #', 'Date Settled', 'Seller', 'Gross Amount', 'LGU Share'],
            'rows' => $settlements->map(fn ($s) => [
                $s->order?->order_number ?? 'N/A',
                $s->settled_at->format('Y-m-d'),
                $s->sellerProfile?->hatchery_name ?? 'Unknown',
                number_format((float) $s->gross_amount, 2),
                number_format((float) $s->lgu_share, 2),
            ])->all(),
        ];
    }

    private static function lguSellers(int $municipalityId): array
    {
        $sellers = \App\Models\SellerProfile::where('municipality_id', $municipalityId)
            ->withCount('listings')
            ->with('user')
            ->get()
            ->map(function ($seller) {
                $totalSales = Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount');

                return [$seller, $totalSales];
            });

        return [
            'title' => 'Seller Report',
            'columns' => ['Hatchery Name', 'Owner', 'Status', 'Rating', 'Listings', 'Total Completed Sales'],
            'rows' => $sellers->map(fn ($pair) => [
                $pair[0]->hatchery_name,
                $pair[0]->user?->name ?? 'Unknown',
                ucfirst($pair[0]->status),
                $pair[0]->rating,
                $pair[0]->listings_count,
                number_format((float) $pair[1], 2),
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Super Admin report tables
    // ------------------------------------------------------------------

    private static function marketplaceRevenue(Carbon $start, Carbon $end): array
    {
        $cards = RevenueReport::platformCards();

        return [
            'title' => 'Marketplace Revenue',
            'columns' => ['Metric', 'Value'],
            'rows' => [
                ['Gross Marketplace Revenue (all-time)', number_format($cards['gross_marketplace_revenue'], 2)],
                ['Platform Revenue Today', number_format($cards['today_platform_revenue'], 2)],
                ['Platform Revenue This Month', number_format($cards['monthly_platform_revenue'], 2)],
                ['Platform Revenue (all-time)', number_format($cards['total_platform_revenue'], 2)],
                ['Total Settled Orders', $cards['total_settled_orders']],
                ['Average Platform Revenue per Order', number_format($cards['average_platform_revenue_per_order'], 2)],
                ['Realized Platform Revenue (selected period)', number_format(RevenueReport::realizedPlatformRevenueTotal($start, $end), 2)],
            ],
        ];
    }

    private static function municipalityRevenue(Carbon $start, Carbon $end): array
    {
        $rows = RevenueReport::platformRevenueByMunicipality($start, $end);

        return [
            'title' => 'Municipality Revenue',
            'columns' => ['Municipality', 'Realized Platform Revenue', 'Paid Withdrawals'],
            'rows' => collect($rows)->map(fn ($row) => [
                $row->municipality,
                number_format((float) $row->amount, 2),
                $row->total,
            ])->all(),
        ];
    }

    private static function buyerStatistics(): array
    {
        $buyers = User::where('role', 'buyer')->with('municipality')->get();

        return [
            'title' => 'Buyer Statistics',
            'columns' => ['Name', 'Email', 'Municipality', 'Status', 'Total Orders', 'Completed Orders', 'Total Spent'],
            'rows' => $buyers->map(function ($buyer) {
                $orders = Order::where('buyer_id', $buyer->id);
                $completed = (clone $orders)->where('status', 'completed');

                return [
                    $buyer->name,
                    $buyer->email,
                    $buyer->municipality?->name ?? 'N/A',
                    ucfirst($buyer->status),
                    (clone $orders)->count(),
                    (clone $completed)->count(),
                    number_format((float) (clone $completed)->sum('total_amount'), 2),
                ];
            })->all(),
        ];
    }

    private static function sellerStatistics(): array
    {
        $sellers = \App\Models\SellerProfile::with(['user', 'municipality'])->withCount('listings')->get();

        return [
            'title' => 'Seller Statistics',
            'columns' => ['Hatchery Name', 'Owner', 'Municipality', 'Status', 'Rating', 'Listings', 'Total Completed Sales'],
            'rows' => $sellers->map(fn ($seller) => [
                $seller->hatchery_name,
                $seller->user?->name ?? 'Unknown',
                $seller->municipality?->name ?? 'N/A',
                ucfirst($seller->status),
                $seller->rating,
                $seller->listings_count,
                number_format((float) Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount'), 2),
            ])->all(),
        ];
    }

    private static function ordersTable(Carbon $start, Carbon $end): array
    {
        $orders = Order::whereBetween('orders.created_at', [$start, $end])
            ->with(['buyer', 'sellerProfile', 'listing'])
            ->latest('orders.created_at')
            ->get();

        return [
            'title' => 'Orders',
            'columns' => ['Order #', 'Date', 'Buyer', 'Seller', 'Municipality', 'Species', 'Qty', 'Total Amount', 'Status'],
            'rows' => $orders->map(fn ($o) => [
                $o->order_number,
                $o->created_at->format('Y-m-d'),
                $o->buyer?->name ?? 'Unknown',
                $o->sellerProfile?->hatchery_name ?? 'Unknown',
                $o->sellerProfile?->municipality?->name ?? 'N/A',
                $o->listing?->species ?? 'N/A',
                $o->quantity,
                number_format((float) $o->total_amount, 2),
                ucfirst($o->status),
            ])->all(),
        ];
    }

    private static function listingsTable(Carbon $start, Carbon $end): array
    {
        $listings = FingerlingListing::whereBetween('listings.created_at', [$start, $end])
            ->with(['sellerProfile', 'municipality'])
            ->latest('listings.created_at')
            ->get();

        return [
            'title' => 'Listings',
            'columns' => ['Title', 'Species', 'Seller', 'Municipality', 'Quantity', 'Price/Piece', 'Status'],
            'rows' => $listings->map(fn ($l) => [
                $l->title,
                $l->species,
                $l->sellerProfile?->hatchery_name ?? 'Unknown',
                $l->municipality?->name ?? 'N/A',
                $l->quantity,
                number_format((float) $l->price_per_piece, 2),
                ucfirst($l->approval_status),
            ])->all(),
        ];
    }

    private static function payoutsTable(Carbon $start, Carbon $end): array
    {
        $sellerPayouts = WithdrawalRequest::whereBetween('created_at', [$start, $end])
            ->with('sellerProfile.user')
            ->get()
            ->map(fn ($w) => [
                'Seller', $w->sellerProfile?->hatchery_name ?? 'Unknown', $w->method,
                number_format((float) $w->amount, 2), ucfirst($w->status), $w->created_at->format('Y-m-d'),
            ]);

        $lguPayouts = LguWithdrawalRequest::whereBetween('created_at', [$start, $end])
            ->with('municipality')
            ->get()
            ->map(fn ($w) => [
                'LGU', $w->municipality?->name ?? 'Unknown', $w->method,
                number_format((float) $w->amount, 2), ucfirst($w->status), $w->created_at->format('Y-m-d'),
            ]);

        return [
            'title' => 'Payouts',
            'columns' => ['Type', 'Recipient', 'Method', 'Amount', 'Status', 'Requested'],
            'rows' => $sellerPayouts->concat($lguPayouts)->sortByDesc(fn ($row) => $row[5])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public static function toPdf(array $table, string $rangeLabel): Response
    {
        $pdf = Pdf::loadView('reports.table', [
            'title' => $table['title'],
            'columns' => $table['columns'],
            'rows' => $table['rows'],
            'generatedAt' => now()->format('M d, Y g:i A'),
            'rangeLabel' => $rangeLabel,
        ]);

        $filename = str($table['title'])->slug()->append('.pdf')->toString();

        return $pdf->download($filename);
    }

    public static function toExcel(array $table): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(str($table['title'])->limit(31, '')->toString());

        $columnCount = count($table['columns']);

        foreach ($table['columns'] as $columnIndex => $columnLabel) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).'1', $columnLabel);
        }
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        foreach ($table['rows'] as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2), $value);
            }
        }

        foreach (range(1, $columnCount) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        $filename = str($table['title'])->slug()->append('.xlsx')->toString();

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
