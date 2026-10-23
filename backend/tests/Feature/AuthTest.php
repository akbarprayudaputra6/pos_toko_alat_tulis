<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi',
            'email' => 'budi@toko.test',
            'password' => 'password123',
        ], $overrides);
    }

    // ---------- Register ----------

    public function test_register_with_valid_data_succeeds_and_password_is_hashed(): void
    {
        $res = $this->postJson('/api/register', $this->validRegistration());

        $res->assertSuccessful();
        $user = User::where('email', 'budi@toko.test')->firstOrFail();
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertStringNotContainsString('password123', $res->getContent());
        $this->assertStringNotContainsString($user->password, $res->getContent());
    }

    public static function invalidRegistrations(): array
    {
        $base = [
            'name' => 'Budi',
            'email' => 'budi@toko.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        return [
            'nama kosong' => [array_merge($base, ['name' => ''])],
            'email bukan email' => [array_merge($base, ['email' => 'bukan-email'])],
            'email kosong' => [array_merge($base, ['email' => ''])],
            'password terlalu pendek' => [array_merge($base, ['password' => '123', 'password_confirmation' => '123'])],
            'body kosong' => [[]],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_register_rejects_invalid_input(array $payload): void
    {
        $this->postJson('/api/register', $payload)->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->makeUser('kasir', ['email' => 'budi@toko.test']);

        $this->postJson('/api/register', $this->validRegistration())->assertStatus(422);
    }

    // ---------- Login ----------

    public function test_login_with_correct_credentials_returns_token(): void
    {
        $user = $this->makeUser();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $res->assertSuccessful();
        $this->assertNotEmpty($res->json('token'), 'Respons login harus memuat field "token". Sesuaikan jika namanya lain.');
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);

        $this->assertContains($res->status(), [401, 422]);
        $this->assertEmpty($res->json('token'));
    }

    public function test_login_does_not_reveal_whether_email_exists(): void
    {
        $user = $this->makeUser();

        $wrongPassword = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);
        $unknownEmail = $this->postJson('/api/login', ['email' => 'tidak-ada@toko.test', 'password' => 'salah-total']);

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429);
    }

    // ---------- Me dan Logout ----------

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_rejects_garbage_token(): void
    {
        $this->withToken('token-ngawur-123')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_current_user_without_password(): void
    {
        $user = $this->makeUser();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('token');

        $res = $this->withToken($token)->getJson('/api/me');

        $res->assertOk();
        $this->assertStringContainsString($user->email, $res->getContent());
        $this->assertStringNotContainsString($user->password, $res->getContent());
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->makeUser();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_token_of_deleted_user_stops_working(): void
    {
        $user = $this->makeUser();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('token');

        $user->delete();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
