<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    private function validProduct(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->makeCategory('Kategori ' . uniqid())->id,
            'name' => 'Pensil 2B',
            'sku' => 'PNS-' . uniqid(),
            'price' => 2500,
            'stock' => 40,
        ], $overrides);
    }

    // ---------- Akses ----------

    public function test_guest_cannot_list_products(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_kasir_can_list_products(): void
    {
        $this->asKasir();
        $this->makeProduct();

        $this->getJson('/api/products')->assertOk();
    }

    public function test_kasir_cannot_create_update_or_delete_products(): void
    {
        $this->asKasir();
        $product = $this->makeProduct();

        $this->postJson('/api/products', $this->validProduct())->assertForbidden();
        $this->putJson("/api/products/{$product->id}", ['name' => 'Diubah'])->assertForbidden();
        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertSame('Pulpen', $product->fresh()->name);
    }

    // ---------- Create ----------

    public function test_admin_can_create_product(): void
    {
        $this->asAdmin();
        $payload = $this->validProduct();

        $this->postJson('/api/products', $payload)->assertSuccessful();

        $this->assertDatabaseHas('products', ['sku' => $payload['sku'], 'name' => 'Pensil 2B']);
    }

    public function test_create_rejects_invalid_input(): void
    {
        $this->asAdmin();

        $cases = [
            'nama kosong' => ['name' => ''],
            'sku terlalu panjang' => ['sku' => str_repeat('A', 300)],
            'nama terlalu panjang' => ['name' => str_repeat('N', 300)],
            'harga negatif' => ['price' => -1],
            'harga bukan angka' => ['price' => 'abc'],
            'stok negatif' => ['stock' => -1],
            'stok desimal' => ['stock' => 1.5],
            'stok bukan angka' => ['stock' => 'banyak'],
            'kategori tidak ada' => ['category_id' => 999999],
            'kategori bukan angka' => ['category_id' => 'abc'],
        ];

        foreach ($cases as $label => $override) {
            $res = $this->postJson('/api/products', $this->validProduct($override));
            $this->assertSame(422, $res->status(), "Kasus: {$label}. Body: " . substr($res->getContent(), 0, 200));
        }
    }

    public function test_create_rejects_duplicate_sku(): void
    {
        $this->asAdmin();
        $existing = $this->makeProduct(['sku' => 'DUP-001']);

        $this->postJson('/api/products', $this->validProduct(['sku' => 'DUP-001']))->assertStatus(422);

        $this->assertSame(1, Product::where('sku', 'DUP-001')->count());
    }

    /** Nilai ekstrem tidak boleh membuat server error (misalnya overflow kolom decimal). */
    public function test_extreme_values_never_cause_server_error(): void
    {
        $this->asAdmin();

        $cases = [
            'harga raksasa' => ['price' => 99999999999999999],
            'stok raksasa' => ['stock' => 99999999999999999],
            'harga notasi ilmiah' => ['price' => '1e50'],
            'nama emoji dan unicode' => ['name' => '📝 Pena 筆 ñ'],
            'nama berisi null byte' => ['name' => "abc\0def"],
        ];

        foreach ($cases as $label => $override) {
            $this->assertNoServerError($this->postJson('/api/products', $this->validProduct($override)), $label);
        }
    }

    // ---------- Update dan Delete ----------

    public function test_admin_can_update_product(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $this->putJson("/api/products/{$product->id}", $this->validProduct([
            'category_id' => $product->category_id,
            'sku' => $product->sku,
            'name' => 'Nama Baru',
            'price' => 4500,
        ]))->assertSuccessful();

        $this->assertSame('Nama Baru', $product->fresh()->name);
    }

    public function test_update_with_negative_stock_is_rejected(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $res = $this->putJson("/api/products/{$product->id}", ['stock' => -5]);

        $this->assertContains($res->status(), [422]);
        $this->assertSame(100, $this->stockOf($product));
    }

    public function test_unknown_product_returns_friendly_json_404(): void
    {
        $this->asAdmin();

        $res = $this->putJson('/api/products/999999', ['name' => 'X']);

        $res->assertNotFound();
        $this->assertNotEmpty($res->json('message'));
        $this->assertArrayNotHasKey('exception', $res->json());
        $this->assertArrayNotHasKey('trace', $res->json());
    }

    public function test_admin_can_delete_product(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $res = $this->deleteJson("/api/products/{$product->id}");

        $res->assertStatus(200);

        $this->assertSoftDeleted($product);
    }

    /** Produk yang sudah pernah terjual: tidak boleh 500 (pelanggaran foreign key). */
    public function test_deleting_a_sold_product_does_not_crash(): void
    {
        $kasir = $this->asKasir();
        $product = $this->makeProduct();
        $this->checkout([[$product->id, 1]])->assertSuccessful();

        $this->asAdmin();
        $res = $this->deleteJson("/api/products/{$product->id}");

        $this->assertNoServerError($res, 'hapus produk yang sudah terjual');
        $this->assertDatabaseCount('transaction_items', 1);
    }

    // ---------- Pencarian dan filter ----------

    public function test_search_filters_by_name(): void
    {
        $this->asKasir();
        $this->makeProduct(['name' => 'Pulpen Biru']);
        $this->makeProduct(['name' => 'Penghapus']);

        $res = $this->getJson('/api/products?search=Pulpen')->assertOk();

        $names = collect($res->json())->pluck('name')->all();
        $this->assertSame(['Pulpen Biru'], $names);
    }

    public function test_filter_by_category(): void
    {
        $this->asKasir();
        $a = $this->makeCategory('Kat A');
        $b = $this->makeCategory('Kat B');
        $this->makeProduct(['name' => 'Produk A', 'category_id' => $a->id]);
        $this->makeProduct(['name' => 'Produk B', 'category_id' => $b->id]);

        $res = $this->getJson("/api/products?category_id={$a->id}")->assertOk();

        $this->assertSame(['Produk A'], collect($res->json())->pluck('name')->all());
    }
}
