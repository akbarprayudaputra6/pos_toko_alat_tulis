<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function createTransaction(int $userId, array $items): Transaction
    {
        return DB::transaction(function () use ($userId, $items) {
            $totalAmount = 0;
            $transactionItemsData = [];

            foreach ($items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk dengan ID {$item['product_id']} tidak ditemukan.",
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    Log::warning('transaction.failed', [
                        'user_id'    => Auth::id(),
                        'product_id' => $product->id,
                        'requested'  => $item['quantity'],
                        'available'  => $product->stock,
                    ]);

                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->name} tidak cukup. Sisa stok: {$product->stock}.",
                    ]);
                }

                $product->decrement('stock', $item['quantity']);

                $subtotal = $product->price * $item['quantity'];
                $totalAmount += $subtotal;

                $transactionItemsData[] = [
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price_at_sale' => $product->price,
                    'subtotal' => $subtotal,
                ];
            }

            $transaction = Transaction::create([
                'user_id' => $userId,
                'invoice_number' => $this->generateInvoiceNumber(),
                'total_amount' => $totalAmount,
            ]);

            foreach ($transactionItemsData as $itemData) {
                $transaction->transactionItem()->create($itemData);
                if ($product->stock <= 10) {
                    Log::info('stock.low', ['product_id' => $product->id, 'stock' => $product->stock]);
                }
            }

            return $transaction->load('transactionItem.product', 'user');
        });

        Log::info('transaction.created', [
            'invoice' => $transaction->invoice_number,
            'user_id' => $transaction->user_id,
            'total'   => $transaction->total_amount,
            'items'   => count($items),
        ]);
    }

    /**
     * Admin lihat semua transaksi, Kasir cuma lihat miliknya sendiri.
     */
    public function getAll(User $requester): LengthAwarePaginator
    {
        return Transaction::with('transactionItem.product', 'user')
            ->when($requester->role !== 'admin', fn($query) => $query->where('user_id', $requester->id))
            ->latest()
            ->paginate(15);
    }

    /**
     * Admin bisa lihat detail transaksi siapa pun.
     * Kasir cuma bisa lihat detail transaksinya sendiri.
     */
    public function getDetail(User $requester, int $transactionId): Transaction
    {
        $transaction = Transaction::with('transactionItem.product', 'user')->findOrFail($transactionId);

        if ($requester->role !== 'admin' && $transaction->user_id !== $requester->id) {
            throw ValidationException::withMessages([
                'transaction' => ['Anda tidak memiliki akses ke transaksi ini.'],
            ]);
        }

        return $transaction;
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ymd');
        $lastTransaction = Transaction::where('invoice_number', 'like', "{$prefix}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        $sequence = $lastTransaction
            ? (int) substr($lastTransaction->invoice_number, -4) + 1
            : 1;

        return $prefix . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
