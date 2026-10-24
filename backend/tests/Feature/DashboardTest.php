<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    private const ENDPOINTS = [
        '/api/dashboard/summary',
        '/api/dashboard/today-sales',
        '/api/dashboard/top-products',
    ];

    // ---------- Akses ----------

    public function test_guest_gets_401_on_every_dashboard_endpoint(): void
    {
        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }

    public function test_kasir_gets_403_on_every_dashboard_endpoint(): void
    {
        $this->asKasir();

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
    }

    public function test_admin_can_open_every_dashboard_endpoint(): void
    {
        $this->asAdmin();

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri)->assertOk();
        }
    }

    // ---------- Bentuk dan nilai ----------

    public function test_summary_has_expected_structure(): void
    {
        $this->asAdmin();

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonStructure([
                'today' => ['transaction_count', 'total_sales'],
                'top_products',
                'low_stock',
            ]);
    }

    public function test_empty_database_gives_zeros_and_empty_lists(): void
    {
        $this->asAdmin();

        $res = $this->getJson('/api/dashboard/summary')->assertOk();

        $this->assertSame(0, $res->json('today.transaction_count'));
        $this->assertEquals(0, $res->json('today.total_sales'));
        $this->assertSame([], $res->json('top_products'));
        $this->assertSame([], $res->json('low_stock'));
    }

    public function test_today_totals_match_the_transactions_made(): void
    {
        $a = $this->makeProduct(['price' => 3000, 'stock' => 50]);
        $b = $this->makeProduct(['price' => 5000, 'stock' => 50]);
        $this->asKasir();
        $this->checkout([[$a->id, 2]])->assertStatus(201);          // 6.000
        $this->checkout([[$a->id, 1], [$b->id, 1]])->assertStatus(201); // 8.000

        $this->asAdmin();
        $res = $this->getJson('/api/dashboard/today-sales')->assertOk();

        $this->assertSame(2, $res->json('transaction_count'));
        $this->assertEquals(14000, $res->json('total_sales'));
    }

    public function test_yesterdays_transactions_are_not_counted_as_today(): void
    {
        $product = $this->makeProduct(['stock' => 50]);
        $this->asKasir();
        $this->checkout([[$product->id, 1]])->assertStatus(201);

        DB::table('transactions')->update(['created_at' => now()->subDay()->toDateTimeString()]);

        $this->asAdmin();
        $res = $this->getJson('/api/dashboard/today-sales')->assertOk();
        $this->assertSame(0, $res->json('transaction_count'));
        $this->assertEquals(0, $res->json('total_sales'));
    }

    // ---------- Produk terlaris ----------

    public function test_top_products_are_ordered_by_quantity_and_respect_the_limit(): void
    {
        $low = $this->makeProduct(['name' => 'Sedikit', 'stock' => 50]);
        $mid = $this->makeProduct(['name' => 'Sedang', 'stock' => 50]);
        $top = $this->makeProduct(['name' => 'Terlaris', 'stock' => 50]);
        $this->asKasir();
        $this->checkout([[$low->id, 1], [$mid->id, 3], [$top->id, 5]])->assertStatus(201);

        $this->asAdmin();
        $res = $this->getJson('/api/dashboard/summary?top_limit=2')->assertOk();

        $names = collect($res->json('top_products'))->pluck('name')->all();
        $this->assertSame(['Terlaris', 'Sedang'], $names);
        $this->assertSame(5, $res->json('top_products.0.total_quantity'));
    }

    public function test_top_products_ignores_sales_older_than_thirty_days(): void
    {
        $product = $this->makeProduct(['stock' => 50]);
        $this->asKasir();
        $this->checkout([[$product->id, 2]])->assertStatus(201);

        DB::table('transaction_items')->update(['created_at' => now()->subDays(31)->toDateTimeString()]);

        $this->asAdmin();
        $this->assertSame([], $this->getJson('/api/dashboard/top-products')->assertOk()->json());
    }

    public function test_top_products_revenue_uses_price_at_sale(): void
    {
        $product = $this->makeProduct(['price' => 3000, 'stock' => 50]);
        $this->asKasir();
        $this->checkout([[$product->id, 2]])->assertStatus(201);
        DB::table('products')->where('id', $product->id)->update(['price' => 9999]);

        $this->asAdmin();
        $res = $this->getJson('/api/dashboard/top-products')->assertOk();

        $this->assertEquals(6000, $res->json('0.total_revenue'));
    }

    // ---------- Stok menipis ----------

    public function test_low_stock_uses_default_threshold_of_ten_inclusive(): void
    {
        $this->makeProduct(['name' => 'Lima', 'stock' => 5]);
        $this->makeProduct(['name' => 'Sepuluh', 'stock' => 10]);
        $this->makeProduct(['name' => 'Sebelas', 'stock' => 11]);
        $this->asAdmin();

        $res = $this->getJson('/api/dashboard/summary')->assertOk();

        $this->assertSame(['Lima', 'Sepuluh'], collect($res->json('low_stock'))->pluck('name')->all());
    }

    public function test_low_stock_threshold_can_be_changed(): void
    {
        $this->makeProduct(['name' => 'Nol', 'stock' => 0]);
        $this->makeProduct(['name' => 'Lima Belas', 'stock' => 15]);
        $this->asAdmin();

        $zero = $this->getJson('/api/dashboard/summary?low_stock_threshold=0')->assertOk();
        $this->assertSame(['Nol'], collect($zero->json('low_stock'))->pluck('name')->all());

        $twenty = $this->getJson('/api/dashboard/summary?low_stock_threshold=20')->assertOk();
        $this->assertSame(['Nol', 'Lima Belas'], collect($twenty->json('low_stock'))->pluck('name')->all());
    }

    public function test_stock_drops_into_low_stock_after_a_sale(): void
    {
        $product = $this->makeProduct(['name' => 'Hampir Habis', 'stock' => 12]);
        $this->asKasir();
        $this->checkout([[$product->id, 5]])->assertStatus(201);

        $this->asAdmin();
        $res = $this->getJson('/api/dashboard/summary')->assertOk();

        $this->assertSame(['Hampir Habis'], collect($res->json('low_stock'))->pluck('name')->all());
        $this->assertSame(7, $res->json('low_stock.0.stock'));
    }

    // ---------- Validasi parameter ----------

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $this->asAdmin();

        $cases = [
            '/api/dashboard/summary?low_stock_threshold=-1',
            '/api/dashboard/summary?low_stock_threshold=1001',
            '/api/dashboard/summary?low_stock_threshold=abc',
            '/api/dashboard/summary?low_stock_threshold=1.5',
            '/api/dashboard/summary?top_limit=0',
            '/api/dashboard/summary?top_limit=21',
            '/api/dashboard/summary?top_limit=abc',
            '/api/dashboard/top-products?limit=0',
            '/api/dashboard/top-products?limit=51',
            '/api/dashboard/top-products?limit=abc',
        ];

        foreach ($cases as $uri) {
            $res = $this->getJson($uri);
            $this->assertSame(422, $res->status(), "{$uri} harus 422, dapat {$res->status()}");
        }
    }
}
