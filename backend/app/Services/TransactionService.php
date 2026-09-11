<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    /**
     * Buat transaksi baru dengan atomic stock deduction.
     *
     * @param  int  $userId
     * @param  array  $items  Contoh: [['product_id' => 1, 'quantity' => 2], ...]
     * @return Transaction
     */
    public function createTransaction(int $userId, array $items): Transaction
    {
        return DB::transaction(function () use ($userId, $items) {
            $totalAmount = 0;
            $transactionItemsData = [];

            foreach ($items as $item) {
                // Lock row produk supaya nggak ada race condition
                // saat 2 kasir input transaksi bersamaan untuk produk yang sama
                $product = Product::where('id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk dengan ID {$item['product_id']} tidak ditemukan.",
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->name} tidak cukup. Sisa stok: {$product->stock}.",
                    ]);
                }

                // Kurangi stok
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

            // Buat record transaksi utama
            $transaction = Transaction::create([
                'user_id' => $userId,
                'invoice_number' => $this->generateInvoiceNumber(),
                'total_amount' => $totalAmount,
            ]);

            // Buat semua transaction_items sekaligus
            foreach ($transactionItemsData as $itemData) {
                $transaction->items()->create($itemData);
            }

            return $transaction->load('items.product');
        });
    }

    /**
     * Generate nomor invoice unik, contoh: INV-20260910-0001
     */
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
