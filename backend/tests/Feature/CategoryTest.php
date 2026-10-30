<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    // ---------- Akses ----------

    public function test_guest_cannot_list_categories(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_kasir_can_list_categories(): void
    {
        $this->asKasir();
        $this->makeCategory('Buku');

        $this->getJson('/api/categories')->assertOk();
    }

    public function test_kasir_cannot_create_update_or_delete_categories(): void
    {
        $this->asKasir();
        $category = $this->makeCategory('Buku');

        $this->postJson('/api/categories', ['name' => 'Baru'])->assertForbidden();
        $this->putJson("/api/categories/{$category->id}", ['name' => 'Diubah'])->assertForbidden();
        $this->deleteJson("/api/categories/{$category->id}")->assertForbidden();

        $this->assertSame('Buku', $category->fresh()->name);
        $this->assertDatabaseCount('categories', 1);
    }

    // ---------- Create ----------

    public function test_admin_can_create_category(): void
    {
        $this->asAdmin();

        $this->postJson('/api/categories', ['name' => 'Alat Tulis'])->assertSuccessful();

        $this->assertDatabaseHas('categories', ['name' => 'Alat Tulis']);
    }

    public function test_create_rejects_invalid_input(): void
    {
        $this->asAdmin();

        $cases = [
            'nama kosong' => ['name' => ''],
            'nama null' => ['name' => null],
            'nama hilang' => [],
            'nama terlalu panjang' => ['name' => str_repeat('N', 300)],
            'nama berupa array' => ['name' => ['a', 'b']],
            'nama berupa angka bersarang' => ['name' => ['x' => 1]],
        ];

        foreach ($cases as $label => $payload) {
            $res = $this->postJson('/api/categories', $payload);
            $this->assertSame(422, $res->status(), "Kasus: {$label}. Body: " . substr($res->getContent(), 0, 200));
        }

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $this->asAdmin();
        $this->makeCategory('Buku');

        $this->postJson('/api/categories', ['name' => 'Buku'])->assertStatus(422);

        $this->assertSame(1, Category::where('name', 'Buku')->count());
    }

    public function test_unicode_names_are_accepted(): void
    {
        $this->asAdmin();

        $res = $this->postJson('/api/categories', ['name' => 'Pena 筆 ñ 📝']);

        $this->assertNoServerError($res, 'nama unicode');
        $this->assertDatabaseHas('categories', ['name' => 'Pena 筆 ñ 📝']);
    }

    // ---------- Show ------------

    public function test_category_detail_returns_the_category(): void
    {
        $this->asKasir();
        $category = $this->makeCategory("Pensil");

        $this->getJson("/api/categories/{$category->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Pensil']);
    }

    public function test_unknown_category_returns_404(): void
    {
        $this->asKasir();

        $this->getJson('/api/categories/999999')->assertNotFound();
    }

    public function test_guest_cannot_view_a_category(): void
    {
        $this->getJson('/api/categories/1')->assertUnauthorized();
    }

    // ---------- Update ----------

    public function test_admin_can_update_category(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Buku Tulis'])->assertSuccessful();

        $this->assertSame('Buku Tulis', $category->fresh()->name);
    }

    /** Menyimpan ulang dengan nama yang sama tidak boleh dianggap duplikat. */
    public function test_update_with_own_name_is_not_a_duplicate(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Buku'])->assertSuccessful();
    }

    public function test_update_rejects_name_used_by_another_category(): void
    {
        $this->asAdmin();
        $this->makeCategory('Buku');
        $other = $this->makeCategory('Pulpen');

        $this->putJson("/api/categories/{$other->id}", ['name' => 'Buku'])->assertStatus(422);

        $this->assertSame('Pulpen', $other->fresh()->name);
    }

    public function test_update_rejects_empty_name(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');

        $this->putJson("/api/categories/{$category->id}", ['name' => ''])->assertStatus(422);

        $this->assertSame('Buku', $category->fresh()->name);
    }

    // ---------- Delete ----------

    public function test_admin_can_delete_empty_category(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Kosong');

        $this->deleteJson("/api/categories/{$category->id}")->assertSuccessful();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /** Kategori yang masih punya produk: boleh ditolak atau dihapus rapi, tidak boleh 500. */
    public function test_deleting_category_with_products_does_not_crash(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Berisi');
        $this->makeProduct(['category_id' => $category->id]);

        $res = $this->deleteJson("/api/categories/{$category->id}");

        $this->assertNoServerError($res, 'hapus kategori yang masih punya produk');
    }

    // ---------- 404 ----------

    public function test_unknown_category_returns_friendly_json_404(): void
    {
        $this->asAdmin();

        foreach (['put', 'delete'] as $verb) {
            $res = $this->{$verb . 'Json'}('/api/categories/999999', ['name' => 'X']);

            $res->assertNotFound();
            $this->assertNotEmpty($res->json('message'));
            $this->assertArrayNotHasKey('trace', $res->json());
            $this->assertStringNotContainsString('App\\Models', $res->getContent());
        }
    }

    public function test_non_numeric_category_id_returns_404_not_500(): void
    {
        $this->asAdmin();

        $res = $this->putJson('/api/categories/abc', ['name' => 'X']);

        $this->assertSame(404, $res->status(), 'ID bukan angka harus 404. Body: ' . substr($res->getContent(), 0, 200));
    }
}
