<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * Ringkasan lengkap: penjualan hari ini, produk terlaris, stok menipis.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'top_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $summary = $this->dashboardService->getSummary(
            lowStockThreshold: $validated['low_stock_threshold'] ?? 10,
            topLimit: $validated['top_limit'] ?? 5,
        );

        return response()->json($summary);
    }

    /**
     * Hanya penjualan hari ini.
     */
    public function todaySales()
    {
        return response()->json($this->dashboardService->getTodaySales());
    }

    /**
     * Hanya produk terlaris (30 hari terakhir).
     */
    public function topProducts(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $products = $this->dashboardService->getTopProducts(
            limit: $validated['limit'] ?? 5,
        );

        return response()->json($products);
    }
}
