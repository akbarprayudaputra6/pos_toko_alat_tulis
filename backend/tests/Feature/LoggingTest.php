<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\PosTestHelpers;
use Tests\TestCase;

/**
 * Memastikan kejadian penting TERCATAT, dan data rahasia TIDAK ikut tercatat.
 * Log::spy() merekam semua pemanggilan Log; lalu kita periksa yang kita mau.
 */
class LoggingTest extends TestCase
{
    use RefreshDatabase, PosTestHelpers;

    public function test_failed_login_is_logged_without_the_password(): void
    {
        $user = $this->makeUser('kasir');
        Log::spy();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);

        $this->assertFalse($res->isSuccessful());
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return $message === 'auth.login_failed'
                    && ! array_key_exists('password', $context)
                    && ! str_contains(json_encode($context), 'salah-total');
            })
            ->once();
    }

    public function test_successful_login_is_logged_without_the_token(): void
    {
        $user = $this->makeUser('kasir');
        Log::spy();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) use ($user) {
                return $message === 'auth.login'
                    && $context['user_id'] == $user->id
                    && ! array_key_exists('token', $context);
            })
            ->once();
    }

    public function test_rate_limit_hit_is_logged(): void
    {
        $user = $this->makeUser('kasir');
        Log::spy();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'salah-total']);
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn($message, $context = []) => $message === 'security.rate_limited')
            ->once();
    }

    public function test_forbidden_access_is_logged_with_the_user(): void
    {
        $kasir = $this->asKasir();
        Log::spy();

        $this->getJson('/api/dashboard/summary')->assertForbidden();

        Log::shouldHaveReceived('notice')
            ->withArgs(function ($message, $context = []) use ($kasir) {
                return $message === 'security.forbidden' && $context['user_id'] == $kasir->id;
            })
            ->once();
    }

    public function test_successful_checkout_is_logged(): void
    {
        $kasir = $this->asKasir();
        $product = $this->makeProduct(['stock' => 50]);
        Log::spy();

        $this->checkout([[$product->id, 2]])->assertStatus(201);

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) use ($kasir) {
                return $message === 'transaction.created'
                    && $context['user_id'] == $kasir->id
                    && $context['items'] === 1
                    && str_starts_with($context['invoice'], 'INV-');
            })
            ->once();
    }

    public function test_checkout_failed_for_insufficient_stock_is_logged(): void
    {
        $this->asKasir();
        $product = $this->makeProduct(['stock' => 1]);
        Log::spy();

        $this->checkout([[$product->id, 5]])->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn($message, $context = []) => $message === 'transaction.failed')
            ->once();
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $res = $this->getJson('/api/me');   // tamu: 401, tetap harus punya header

        $this->assertNotEmpty($res->headers->get('X-Request-Id'));
    }
}
