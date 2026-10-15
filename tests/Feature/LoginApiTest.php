<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'admin.udumbara',
            'password' => Hash::make('password-rahasia'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password-rahasia',
            'remember' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login berhasil.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', 'admin.udumbara')
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertIsString($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'username' => 'admin.udumbara',
            'password' => Hash::make('password-rahasia'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'admin.udumbara',
            'password' => 'password-salah',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Username atau password salah.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_requires_username_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_authenticated_user_can_logout_and_revoke_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout berhasil.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
