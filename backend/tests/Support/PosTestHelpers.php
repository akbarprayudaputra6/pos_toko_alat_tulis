<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Satu tempat untuk semua asumsi tentang skema Anda.
 * Jika nama kolom atau field di proyek Anda berbeda, ubah di sini saja.
 * 
 * @mixin \Tests\TestCase
 */
trait PosTestHelpers
{
    protected const PASSWORD = 'password123';

    protected function makeUser(string $role = 'kasir', array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => ucfirst($role) . ' Test',
            'email' => $role . '-' . uniqid() . '@toko.test',
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
        ], $overrides))->save();

        return $user;
    }

    protected function asAdmin(): User
    {
        $user = $this->makeUser('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function asKasir(): User
    {
        $user = $this->makeUser('kasir');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeCategory(string $name = 'Alat Tulis'): Category
    {
        return Category::create(['name' => $name]);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        if (!isset($overrides['category_id'])) {
            $overrides['category_id'] = $this->makeCategory('Kategori ' . uniqid())->id;
        }

        return Product::create(array_merge([
            'name' => 'Pulpen',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 3000,
            'stock' => 100,
        ], $overrides));
    }

    /** @param array<int, array{0:int,1:mixed}> $lines pasangan [product_id, quantity] */
    protected function checkoutPayload(array $lines): array
    {
        return [
            'items' => array_map(
                fn($l) => ['product_id' => $l[0], 'quantity' => $l[1]],
                $lines
            ),
        ];
    }

    /** Membuat transaksi lewat API sebagai pengguna yang sedang login. */
    protected function checkout(array $lines)
    {
        return $this->postJson('/api/transactions', $this->checkoutPayload($lines));
    }

    protected function stockOf(Product $product): int
    {
        return (int) Product::query()->whereKey($product->id)->value('stock');
    }

    protected function assertNoServerError($response, string $label = ''): void
    {
        $this->assertLessThan(
            500,
            $response->status(),
            "Server error (500) pada kasus: {$label}. Body: " . substr($response->getContent(), 0, 300)
        );
    }
}
