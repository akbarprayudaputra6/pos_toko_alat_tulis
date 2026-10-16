<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getSummary(int $lowStockThreshold = 10, int $topLimit = 5): array
    {
        return [
            'today' => $this->getTodaySales(),
            'top_products' => $this->getTopProducts($topLimit),
            'low_stock' => $this->getLowStock($lowStockThreshold),
        ];
    }

    public function getTodaySales(): array
    {
        $result = Transaction::query()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(total_amount), 0) as total_sales')
            ->first();

        return [
            'transaction_count' => (int) $result->transaction_count,
            'total_sales' => (float) $result->total_sales,
        ];
    }

    /**
     * Produk terlaris 30 hari terakhir, berdasarkan jumlah terjual.
     */
    public function getTopProducts(int $limit): array
    {
        return TransactionItem::query()
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->where('transaction_items.created_at', '>=', now()->subDays(30))
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(transaction_items.quantity) as total_quantity'),
                DB::raw('SUM(transaction_items.subtotal) as total_revenue'),
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->map(fn($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'total_quantity' => (int) $row->total_quantity,
                'total_revenue' => (float) $row->total_revenue,
            ])
            ->all();
    }

    private function getLowStock(int $threshold): array
    {
        return Product::query()
            ->where('stock', '<=', $threshold)
            ->orderBy('stock')
            ->get(['id', 'name', 'sku', 'stock'])
            ->all();
    }
}
