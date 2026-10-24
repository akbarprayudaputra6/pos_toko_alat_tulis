<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    // ---------- Akses ----------

    public function test_guest_cannot_use_transaction_endpoints(): void
    {
        $this->postJson('/api/transactions', $this->checkoutPayload([[1, 1]]))->assertUnauthorized();
        $this->getJson('/api/transactions')->assertUnauthorized();
        $this->getJson('/api/transactions/1')->assertUnauthorized();
    }

    public function test_admin_can_also_checkout(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $this->checkout([[$product->id, 1]])->assertStatus(201);
    }

    public function test_transactions_cannot_be_edited_or_deleted(): void
    {
        $this->asKasir();
        $product = $this->makeProduct();
        $id = $this->checkout([[$product->id, 1]])->json('data.id');

        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $res = $this->{$method}("/api/transactions/{$id}", []);
            $this->assertContains($res->status(), [404, 405], "{$method} pada transaksi harus 404/405, dapat {$res->status()}");
        }

        $this->assertDatabaseCount('transactions', 1);
    }

    // ---------- Transaksi berhasil ----------

    public function test_checkout_reduces_stock_and_records_totals(): void
    {
        $kasir = $this->asKasir();
        $a = $this->makeProduct(['price' => 3000, 'stock' => 10]);
        $b = $this->makeProduct(['price' => 5000, 'stock' => 5]);

        $res = $this->checkout([[$a->id, 2], [$b->id, 1]]);

        $res->assertStatus(201);
        $this->assertEquals(11000, $res->json('data.total_amount'));
        $this->assertSame(8, $this->stockOf($a));
        $this->assertSame(4, $this->stockOf($b));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 2);
        $this->assertSame($kasir->id, Transaction::first()->user_id);
    }

    public function test_invoice_number_has_expected_format_and_increments(): void
    {
        $this->asKasir();
        $product = $this->makeProduct();

        $first = $this->checkout([[$product->id, 1]])->json('data.invoice_number');
        $second = $this->checkout([[$product->id, 1]])->json('data.invoice_number');

        $prefix = 'INV-' . now()->format('Ymd') . '-';
        $this->assertSame($prefix . '0001', $first);
        $this->assertSame($prefix . '0002', $second);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{4}$/', $first);
    }

    public function test_buying_exact_remaining_stock_succeeds_and_next_purchase_fails(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['stock' => 3]);

        $this->checkout([[$product->id, 3]])->assertStatus(201);
        $this->assertSame(0, $this->stockOf($product));

        $this->checkout([[$product->id, 1]])->assertStatus(422);
        $this->assertSame(0, $this->stockOf($product));
    }

    /** Harga dan total dihitung server; nilai kiriman klien harus diabaikan. */
    public function test_client_cannot_override_prices_totals_or_owner(): void
    {
        $kasir = $this->asKasir();
        $other = $this->makeUser('kasir');
        $product = $this->makeProduct(['price' => 3000, 'stock' => 10]);

        $res = $this->postJson('/api/transactions', [
            'user_id' => $other->id,
            'total_amount' => 1,
            'invoice_number' => 'INV-HACK-0001',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 1,
                'price_at_sale' => 1,
                'subtotal' => 1,
            ]],
        ]);

        $res->assertStatus(201);
        $transaction = Transaction::firstOrFail();
        $this->assertEquals(6000, $transaction->total_amount);
        $this->assertSame($kasir->id, $transaction->user_id);
        $this->assertNotSame('INV-HACK-0001', $transaction->invoice_number);
        $this->assertDatabaseHas('transaction_items', ['product_id' => $product->id, 'price_at_sale' => 3000]);
    }

    public function test_price_at_sale_is_a_snapshot_and_survives_price_changes(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['price' => 3000]);
        $id = $this->checkout([[$product->id, 2]])->json('data.id');

        DB::table('products')->where('id', $product->id)->update(['price' => 9999]);

        $res = $this->getJson("/api/transactions/{$id}")->assertOk();
        $this->assertEquals(3000, $res->json('transaction_item.0.price_at_sale'));
        $this->assertEquals(6000, $res->json('total_amount'));
    }

    public function test_response_does_not_leak_password_hashes(): void
    {
        $kasir = $this->asKasir();
        $product = $this->makeProduct();

        $res = $this->checkout([[$product->id, 1]]);

        $this->assertStringNotContainsString($kasir->password, $res->getContent());
        $this->assertArrayNotHasKey('password', (array) $res->json('data.user'));
    }

    // ---------- Transaksi gagal dan atomik ----------

    public function test_insufficient_stock_is_rejected_and_nothing_is_saved(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['stock' => 5]);

        $res = $this->checkout([[$product->id, 6]]);

        $res->assertStatus(422);
        $this->assertSame('Transaksi gagal.', $res->json('message'));
        $this->assertNotEmpty($res->json('errors.items'));
        $this->assertSame(5, $this->stockOf($product));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    /** Item kedua gagal: stok item pertama harus dikembalikan (rollback). */
    public function test_failure_on_second_item_rolls_back_the_first(): void
    {
        $this->asKasir();
        $a = $this->makeProduct(['stock' => 10]);
        $b = $this->makeProduct(['stock' => 5]);

        $this->checkout([[$a->id, 3], [$b->id, 999]])->assertStatus(422);

        $this->assertSame(10, $this->stockOf($a), 'Stok item pertama harus kembali (rollback).');
        $this->assertSame(5, $this->stockOf($b));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['stock' => 10]);
        $id = $product->id;

        $cases = [
            'items hilang' => [],
            'items kosong' => ['items' => []],
            'items bukan array' => ['items' => 'abc'],
            'items berupa angka' => ['items' => 5],
            'jumlah nol' => ['items' => [['product_id' => $id, 'quantity' => 0]]],
            'jumlah negatif' => ['items' => [['product_id' => $id, 'quantity' => -1]]],
            'jumlah desimal' => ['items' => [['product_id' => $id, 'quantity' => 1.5]]],
            'jumlah teks' => ['items' => [['product_id' => $id, 'quantity' => 'dua']]],
            'jumlah hilang' => ['items' => [['product_id' => $id]]],
            'jumlah raksasa' => ['items' => [['product_id' => $id, 'quantity' => 10001]]],
            'product_id hilang' => ['items' => [['quantity' => 1]]],
            'product_id teks' => ['items' => [['product_id' => 'abc', 'quantity' => 1]]],
            'product_id tidak ada' => ['items' => [['product_id' => 999999, 'quantity' => 1]]],
            'produk yang sama dua kali' => ['items' => [
                ['product_id' => $id, 'quantity' => 1],
                ['product_id' => $id, 'quantity' => 1],
            ]],
            'item bukan objek' => ['items' => ['abc']],
        ];

        foreach ($cases as $label => $payload) {
            $res = $this->postJson('/api/transactions', $payload);
            $this->assertSame(422, $res->status(), "Kasus: {$label}. Body: " . substr($res->getContent(), 0, 200));
        }

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10, $this->stockOf($product));
    }

    public function test_more_than_fifty_distinct_items_is_rejected(): void
    {
        $this->asKasir();
        $lines = [];
        for ($i = 0; $i < 51; $i++) {
            $lines[] = [$this->makeProduct(['stock' => 5])->id, 1];
        }

        $this->checkout($lines)->assertStatus(422);

        $this->assertDatabaseCount('transactions', 0);
    }

    // ---------- Daftar (index) ----------

    public function test_kasir_sees_only_own_transactions_and_admin_sees_all(): void
    {
        $product = $this->makeProduct(['stock' => 50]);

        $this->asKasir();
        $this->checkout([[$product->id, 1]])->assertStatus(201);
        $this->checkout([[$product->id, 1]])->assertStatus(201);

        $this->asKasir();
        $this->checkout([[$product->id, 1]])->assertStatus(201);
        $this->assertSame(1, $this->getJson('/api/transactions')->assertOk()->json('total'));

        $this->asAdmin();
        $this->assertSame(3, $this->getJson('/api/transactions')->assertOk()->json('total'));
    }

    public function test_index_is_paginated_by_fifteen(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['stock' => 100]);
        for ($i = 0; $i < 16; $i++) {
            $this->checkout([[$product->id, 1]])->assertStatus(201);
        }

        $page1 = $this->getJson('/api/transactions')->assertOk();
        $page1->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);
        $this->assertCount(15, $page1->json('data'));
        $this->assertSame(16, $page1->json('total'));

        $page2 = $this->getJson('/api/transactions?page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }

    public function test_weird_page_values_never_cause_server_error(): void
    {
        $this->asKasir();

        foreach (['-1', '0', 'abc', '999999', '1.5', '1;DROP TABLE transactions'] as $page) {
            $this->assertNoServerError($this->getJson('/api/transactions?page=' . urlencode($page)), "page={$page}");
        }
    }

    // ---------- Detail (show) ----------

    public function test_kasir_can_view_own_transaction(): void
    {
        $this->asKasir();
        $product = $this->makeProduct();
        $created = $this->checkout([[$product->id, 1]]);

        $res = $this->getJson('/api/transactions/' . $created->json('data.id'))->assertOk();

        $this->assertSame($created->json('data.invoice_number'), $res->json('invoice_number'));
    }

    /** IDOR: kasir B tidak boleh membaca transaksi kasir A. */
    public function test_kasir_cannot_view_another_kasirs_transaction(): void
    {
        $product = $this->makeProduct();
        $this->asKasir();
        $id = $this->checkout([[$product->id, 1]])->json('data.id');

        $this->asKasir();
        $res = $this->getJson("/api/transactions/{$id}");

        $this->assertContains($res->status(), [403, 404], 'IDOR! Kasir lain bisa membaca transaksi ini. Status: ' . $res->status());
        $this->assertStringNotContainsString('invoice_number', $res->getContent());
    }

    public function test_admin_can_view_any_transaction(): void
    {
        $product = $this->makeProduct();
        $this->asKasir();
        $id = $this->checkout([[$product->id, 1]])->json('data.id');

        $this->asAdmin();

        $this->getJson("/api/transactions/{$id}")->assertOk();
    }

    public function test_unknown_transaction_returns_json_404(): void
    {
        $this->asKasir();

        $res = $this->getJson('/api/transactions/999999');

        $res->assertNotFound();
        $this->assertNotEmpty($res->json('message'));
    }

    /** show(int $id) menerima teks? Harus 404, bukan 500 (TypeError). */
    public function test_non_numeric_transaction_id_returns_404_not_500(): void
    {
        $this->asKasir();

        foreach (['abc', '1abc', '1.5', '-1'] as $id) {
            $res = $this->getJson('/api/transactions/' . $id);
            $this->assertSame(404, $res->status(), "ID '{$id}' harus 404, dapat {$res->status()}. Body: " . substr($res->getContent(), 0, 200));
        }
    }
}
