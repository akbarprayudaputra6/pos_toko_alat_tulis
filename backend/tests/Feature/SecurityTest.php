<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

/**
 * Tes keamanan dasar (gaya OWASP API Top 10): autentikasi, otorisasi, injeksi,
 * mass assignment, kebocoran informasi, dan pembatasan laju.
 *
 * Tes yang berlabel #[Group('hardening')] adalah rekomendasi penguatan, bukan bug.
 * Lewati dengan: php artisan test --exclude-group=hardening
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    // =====================================================================
    // 1. Autentikasi: tanpa token semua endpoint terlindungi harus 401
    // =====================================================================

    public static function protectedEndpoints(): array
    {
        return [
            'me' => ['GET', '/api/me'],
            'logout' => ['POST', '/api/logout'],
            'daftar kategori' => ['GET', '/api/categories'],
            'buat kategori' => ['POST', '/api/categories'],
            'ubah kategori' => ['PUT', '/api/categories/{category}'],
            'hapus kategori' => ['DELETE', '/api/categories/{category}'],
            'daftar produk' => ['GET', '/api/products'],
            'detail produk' => ['GET', '/api/products/{product}'],
            'buat produk' => ['POST', '/api/products'],
            'ubah produk' => ['PUT', '/api/products/{product}'],
            'hapus produk' => ['DELETE', '/api/products/{product}'],
            'daftar transaksi' => ['GET', '/api/transactions'],
            'detail transaksi' => ['GET', '/api/transactions/1'],
            'buat transaksi' => ['POST', '/api/transactions'],
            'dashboard ringkasan' => ['GET', '/api/dashboard/summary'],
            'dashboard hari ini' => ['GET', '/api/dashboard/today-sales'],
            'dashboard terlaris' => ['GET', '/api/dashboard/top-products'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_protected_endpoints_reject_guests(string $method, string $uri): void
    {
        $uri = $this->fillIds($uri);

        $this->json($method, $uri)->assertUnauthorized();
    }

    public static function brokenAuthorizationHeaders(): array
    {
        return [
            'tanpa token' => ['Bearer'],
            'token kosong' => ['Bearer '],
            'skema salah' => ['Basic YWRtaW46YWRtaW4='],
            'token ngawur' => ['Bearer abc.def.ghi'],
            'token berisi SQL' => ["Bearer ' OR 1=1 --"],
            'token format sanctum palsu' => ['Bearer 1|' . str_repeat('a', 40)],
            'token sangat panjang' => ['Bearer ' . str_repeat('A', 10000)],
        ];
    }

    #[DataProvider('brokenAuthorizationHeaders')]
    public function test_broken_authorization_headers_are_unauthorized_not_500(string $header): void
    {
        $res = $this->withHeaders(['Authorization' => $header])->getJson('/api/me');

        $this->assertSame(401, $res->status(), 'Header: ' . substr($header, 0, 40) . ' -> ' . $res->status());
    }

    // =====================================================================
    // 2. Otorisasi: kasir tidak boleh memakai endpoint admin
    // =====================================================================

    public static function adminOnlyEndpoints(): array
    {
        return [
            'buat kategori' => ['POST', '/api/categories'],
            'ubah kategori' => ['PUT', '/api/categories/{category}'],
            'hapus kategori' => ['DELETE', '/api/categories/{category}'],
            'buat produk' => ['POST', '/api/products'],
            'ubah produk' => ['PUT', '/api/products/{product}'],
            'hapus produk' => ['DELETE', '/api/products/{product}'],
            'dashboard ringkasan' => ['GET', '/api/dashboard/summary'],
            'dashboard hari ini' => ['GET', '/api/dashboard/today-sales'],
            'dashboard terlaris' => ['GET', '/api/dashboard/top-products'],
        ];
    }

    #[DataProvider('adminOnlyEndpoints')]
    public function test_kasir_is_forbidden_on_admin_endpoints(string $method, string $uri): void
    {
        $this->asKasir();
        $uri = $this->fillIds($uri);

        $res = $this->json($method, $uri, ['name' => 'X', 'sku' => 'X-1', 'price' => 1, 'stock' => 1]);

        $this->assertSame(403, $res->status(), "{$method} {$uri} harus 403 untuk kasir, dapat {$res->status()}");
    }

    // =====================================================================
    // 3. Mass assignment dan eskalasi hak akses
    // =====================================================================

    /** Pendaftaran publik tidak boleh bisa membuat akun admin. */
    public function test_public_registration_cannot_create_an_admin(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Penyusup',
            'email' => 'penyusup@toko.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'penyusup@toko.test')->first();
        $this->assertTrue(
            $user === null || $user->role !== 'admin',
            'BUG KEAMANAN: siapa pun bisa mendaftar sebagai admin lewat field "role". '
                . 'Hapus "role" dari RegisterRequest dan paksa role = kasir.'
        );
    }

    public function test_client_cannot_choose_the_id_of_a_new_record(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');

        $res = $this->postJson('/api/products', [
            'id' => 999999,
            'category_id' => $category->id,
            'name' => 'Pulpen',
            'sku' => 'MASS-001',
            'price' => 1000,
            'stock' => 1,
            'created_at' => '2000-01-01 00:00:00',
        ]);

        $this->assertNoServerError($res, 'mass assignment id');
        $product = Product::where('sku', 'MASS-001')->first();
        if ($product) {
            $this->assertNotSame(999999, $product->id);
            $this->assertNotSame('2000-01-01', $product->created_at->toDateString());
        }
    }

    // =====================================================================
    // 4. SQL Injection
    // =====================================================================

    public static function sqlPayloads(): array
    {
        return [
            'tautologi kutip tunggal' => ["' OR '1'='1"],
            'tautologi angka' => ["' OR 1=1 --"],
            'tautologi kutip ganda' => ['" OR ""="'],
            'drop table' => ["'; DROP TABLE products; --"],
            'union select' => ["' UNION SELECT id, email, password, 1, 1, 1, 1 FROM users --"],
            'stacked query' => ["1; DELETE FROM products"],
            'komentar mysql' => ["admin'#"],
            'blind time based' => ["' OR SLEEP(5) --"],
            'like wildcard bersarang' => ["%' OR '%'='"],
        ];
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_injection_in_product_search_returns_nothing_and_breaks_nothing(string $payload): void
    {
        $this->asKasir();
        $this->makeProduct(['name' => 'Pulpen']);
        $this->makeProduct(['name' => 'Buku']);

        $res = $this->getJson('/api/products?search=' . urlencode($payload));

        $this->assertNoServerError($res, 'search: ' . $payload);
        $this->assertSame([], $res->json(), 'Payload dianggap teks biasa, hasil harus kosong.');
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertSame(2, Product::count());
        $this->assertTrue(Schema::hasTable('users'));
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_injection_in_category_filter_does_not_leak_other_categories(string $payload): void
    {
        $this->asKasir();
        $a = $this->makeCategory('Kat A');
        $b = $this->makeCategory('Kat B');
        $this->makeProduct(['name' => 'Produk A', 'category_id' => $a->id]);
        $this->makeProduct(['name' => 'Produk B', 'category_id' => $b->id]);

        $res = $this->getJson('/api/products?category_id=' . urlencode("{$a->id} {$payload}"));

        $this->assertNoServerError($res, 'category_id: ' . $payload);
        $names = collect($res->json())->pluck('name')->all();
        $this->assertNotContains('Produk B', $names, 'Filter kategori bocor ke kategori lain.');
        $this->assertTrue(Schema::hasTable('products'));
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_injection_in_login_never_authenticates(string $payload): void
    {
        $user = $this->makeUser('admin', ['email' => 'admin@toko.test']);

        foreach (
            [
                ['email' => $payload, 'password' => $payload],
                ['email' => 'admin@toko.test', 'password' => $payload],
                ['email' => "admin@toko.test{$payload}", 'password' => self::PASSWORD],
            ] as $body
        ) {
            $res = $this->postJson('/api/login', $body);

            $this->assertNoServerError($res, 'login: ' . $payload);
            $this->assertEmpty($res->json('token'), 'LOGIN BERHASIL dengan payload SQL!');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_sql_injection_in_register_is_stored_literally_or_rejected(): void
    {
        $payload = "x'); DROP TABLE users; --";

        $res = $this->postJson('/api/register', [
            'name' => $payload,
            'email' => 'inj@toko.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertNoServerError($res, 'register name');
        $this->assertTrue(Schema::hasTable('users'));
        if ($user = User::where('email', 'inj@toko.test')->first()) {
            $this->assertSame($payload, $user->name);
        }
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_injection_in_product_fields_is_stored_literally_or_rejected(string $payload): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');

        $res = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => $payload,
            'sku' => substr('SQL-' . md5($payload), 0, 20),
            'price' => 1000,
            'stock' => 1,
        ]);

        $this->assertNoServerError($res, 'nama produk: ' . $payload);
        $this->assertTrue(Schema::hasTable('products'));
        if ($res->isSuccessful()) {
            $this->assertDatabaseHas('products', ['name' => $payload]);
        }
    }

    public function test_sql_injection_in_url_ids_gives_404_not_data_or_500(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();
        $this->checkout([[$product->id, 1]])->assertStatus(201);

        foreach (
            [
                '/api/products/' . rawurlencode('1 OR 1=1'),
                '/api/products/' . rawurlencode("1'; DROP TABLE products; --"),
                '/api/transactions/' . rawurlencode('1 OR 1=1'),
                '/api/categories/' . rawurlencode('1 UNION SELECT 1'),
            ] as $uri
        ) {
            $res = $this->getJson($uri);
            $this->assertNoServerError($res, $uri);
            $this->assertNotSame(200, $res->status(), "{$uri} tidak boleh mengembalikan data.");
        }

        $this->assertTrue(Schema::hasTable('products'));
    }

    public function test_sql_injection_in_dashboard_parameters_is_rejected(): void
    {
        $this->asAdmin();

        foreach (['1 OR 1=1', "1; DROP TABLE products", "1' --", '1 UNION SELECT 1'] as $payload) {
            $this->getJson('/api/dashboard/summary?low_stock_threshold=' . urlencode($payload))->assertStatus(422);
            $this->getJson('/api/dashboard/summary?top_limit=' . urlencode($payload))->assertStatus(422);
            $this->getJson('/api/dashboard/top-products?limit=' . urlencode($payload))->assertStatus(422);
        }

        $this->assertTrue(Schema::hasTable('products'));
    }

    // =====================================================================
    // 5. XSS dan tipe data tak terduga
    // =====================================================================

    /**
     * API ini menyimpan teks apa adanya (benar untuk API JSON); yang wajib dijamin:
     * jawabannya bertipe JSON, bukan HTML. Frontend bertugas meng-escape saat menampilkan.
     */
    public function test_xss_payload_is_returned_as_json_data_never_as_html(): void
    {
        $this->asAdmin();
        $category = $this->makeCategory('Buku');
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)>';

        $res = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => $payload,
            'sku' => 'XSS-001',
            'price' => 1000,
            'stock' => 1,
        ]);

        $this->assertNoServerError($res, 'xss');
        $this->assertStringContainsString('application/json', (string) $res->headers->get('Content-Type'));

        $list = $this->getJson('/api/products');
        $this->assertStringContainsString('application/json', (string) $list->headers->get('Content-Type'));
    }

    public function test_type_confusion_never_causes_server_error(): void
    {
        // Pembatas laju (throttle:5,1) dimatikan agar semua kombinasi benar-benar dicoba.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->asAdmin();
        $category = $this->makeCategory('Buku');
        $weird = [['x' => 1], [1, 2], true, null, 1.5, str_repeat('A', 70000), "\0", '{"a":1}'];

        foreach ($weird as $i => $value) {
            foreach (['name', 'sku', 'price', 'stock', 'category_id'] as $field) {
                $payload = [
                    'category_id' => $category->id,
                    'name' => 'Pulpen',
                    'sku' => 'TYPE-' . $i . $field,
                    'price' => 1000,
                    'stock' => 1,
                    $field => $value,
                ];
                $this->assertNoServerError($this->postJson('/api/products', $payload), "{$field} = " . json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        }

        foreach (['email', 'password'] as $field) {
            foreach ($weird as $value) {
                $this->assertNoServerError($this->postJson('/api/login', [
                    'email' => 'a@b.test',
                    'password' => 'x',
                    $field => $value,
                ]), "login {$field}");
            }
        }
    }

    public function test_malformed_json_body_is_not_a_server_error(): void
    {
        $res = $this->call('POST', '/api/login', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{bukan json');

        $this->assertNoServerError($res, 'JSON rusak');
    }

    // =====================================================================
    // 6. Kebocoran informasi
    // =====================================================================

    /** Error tak terduga tidak boleh membocorkan pesan internal (SQL, path, nama kelas). */
    public function test_unexpected_exceptions_do_not_leak_internal_details(): void
    {
        config(['app.debug' => false]); // seperti di produksi
        Route::get('/api/_boom', function () {
            throw new \RuntimeException("SQLSTATE[HY000]: rahasia-internal di C:\\proyek\\app\\Services\\X.php");
        });

        $res = $this->getJson('/api/_boom');

        $this->assertSame(500, $res->status());
        $body = $res->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body, 'BUG KEAMANAN: pesan exception mentah sampai ke klien. Di handler, kembalikan pesan umum untuk 500 dan catat aslinya lewat report($e).');
        $this->assertStringNotContainsString('rahasia-internal', $body);
        $this->assertStringNotContainsString('Services', $body);
        $this->assertArrayNotHasKey('trace', (array) $res->json());
        $this->assertArrayNotHasKey('file', (array) $res->json());
    }

    public function test_unknown_routes_and_wrong_methods_return_clean_json(): void
    {
        config(['app.debug' => false]);

        $unknown = $this->getJson('/api/tidak-ada');
        $this->assertSame(404, $unknown->status());
        $this->assertArrayNotHasKey('trace', (array) $unknown->json());

        $wrongMethod = $this->deleteJson('/api/login');
        $this->assertContains($wrongMethod->status(), [404, 405], 'Method salah harus 404/405, dapat ' . $wrongMethod->status());
        $this->assertArrayNotHasKey('trace', (array) $wrongMethod->json());
    }

    public function test_login_error_message_is_generic(): void
    {
        $user = $this->makeUser();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);
        $body = strtolower($res->getContent());

        $this->assertStringNotContainsString('password is incorrect', $body);
        $this->assertStringNotContainsString('user not found', $body);
        $this->assertStringNotContainsString('tidak terdaftar', $body);
    }

    // =====================================================================
    // 7. Pembatasan laju (brute force)
    // =====================================================================

    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', []);
        }

        $this->postJson('/api/register', [])->assertStatus(429);
    }

    // =====================================================================
    // 8. Penguatan (rekomendasi, bukan bug)
    // =====================================================================

    #[Group('hardening')]
    public function test_responses_send_nosniff_header(): void
    {
        $this->asKasir();

        $this->assertSame(
            'nosniff',
            $this->getJson('/api/products')->headers->get('X-Content-Type-Options'),
            'Tambahkan middleware yang mengirim X-Content-Type-Options: nosniff.'
        );
    }

    #[Group('hardening')]
    public function test_api_tokens_expire(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'Token Sanctum tidak pernah kedaluwarsa. Isi "expiration" (menit) di config/sanctum.php.'
        );
    }

    // ---------------------------------------------------------------------

    /** Mengganti {category} dan {product} dengan ID nyata agar 404 tidak menutupi 401/403. */
    private function fillIds(string $uri): string
    {
        if (str_contains($uri, '{category}')) {
            $uri = str_replace('{category}', (string) $this->makeCategory('Cat ' . uniqid())->id, $uri);
        }
        if (str_contains($uri, '{product}')) {
            $uri = str_replace('{product}', (string) $this->makeProduct()->id, $uri);
        }

        return $uri;
    }
}
