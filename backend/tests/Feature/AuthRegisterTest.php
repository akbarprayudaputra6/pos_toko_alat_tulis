<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use RefreshDatabase; // reset database bersih setiap test

    public function test_user_dapat_register_dengan_data_valid(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Admin Toko',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@test.com',
            'role' => 'admin',
        ]);
    }

    public function test_register_gagal_jika_email_sudah_terdaftar(): void
    {
        User::factory()->create(['email' => 'admin@test.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Admin Lain',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        // $response->assertStatus(422)
        //     ->assertJsonValidationErrors('email');

        dd($response->status(), $response->json());
    }

    public function test_register_gagal_jika_password_kurang_dari_8_karakter(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Admin Toko',
            'email' => 'admin@test.com',
            'password' => 'short',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
