<?php

namespace Tests\Feature;

use App\Models\Landlord;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_a_landlord_profile(): void
    {
        Notification::fake();
        $payload = [
            'name' => 'Nguyen Van A',
            'email' => 'owner@example.com',
            'phone' => '+84901234567',
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/register', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful. Please verify your email.')
            ->assertJsonPath('data', null);

        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertDatabaseHas('landlords', [
            'user_id' => $user->id,
            'full_name' => $payload['name'],
            'phone' => $payload['phone'],
        ]);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Another Owner',
            'email' => 'owner@example.com',
            'phone' => '+84907654321',
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_user_can_log_in_and_receive_a_bearer_token(): void
    {
        $user = $this->createLandlordUser();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.landlord.user_id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_an_incorrect_password(): void
    {
        $user = $this->createLandlordUser();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_authenticated_user_can_retrieve_their_profile(): void
    {
        $user = $this->createLandlordUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.landlord.user_id', $user->id);
    }

    public function test_user_can_log_out_and_revoke_the_current_token(): void
    {
        $user = $this->createLandlordUser();
        $newToken = $user->createToken('test-token');

        $this->withToken($newToken->plainTextToken)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logged out successfully.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $newToken->accessToken->id]);
    }

    private function createLandlordUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        Landlord::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'phone' => '+84901234567',
        ]);

        return $user;
    }
}
