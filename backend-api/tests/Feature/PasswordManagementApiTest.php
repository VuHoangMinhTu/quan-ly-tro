<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const RESET_LINK_MESSAGE = 'Nếu địa chỉ email này tồn tại trong hệ thống, BeeHouse đã gửi liên kết đặt lại mật khẩu. Vui lòng kiểm tra hộp thư.';

    public function test_forgot_password_sends_a_generic_success_response_for_an_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::RESET_LINK_MESSAGE)
            ->assertJsonPath('data', null);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            return str_contains($notification->resetUrl, rtrim(config('app.frontend_url'), '/').'/reset-password?')
                && str_contains($notification->resetUrl, 'email='.urlencode($user->email));
        });
    }

    public function test_reset_password_notification_uses_the_spa_url_and_vietnamese_template(): void
    {
        $user = User::factory()->make(['email' => 'owner@example.com']);
        $notification = new ResetPasswordNotification('http://frontend.test/reset-password?token=secret&email=owner%40example.com', 60);
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Đặt lại mật khẩu BeeHouse', $mail->subject);
        $this->assertStringContainsString('Đặt lại mật khẩu', $html);
        $this->assertStringContainsString('Spam / Thư rác', $html);
        $this->assertStringContainsString('http://frontend.test/reset-password?token=secret&amp;email=owner%40example.com', $html);
    }

    public function test_forgot_password_does_not_reveal_when_an_email_does_not_exist(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::RESET_LINK_MESSAGE)
            ->assertJsonPath('data', null);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_rejects_an_invalid_email_in_vietnamese(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.email.0', 'Địa chỉ email không hợp lệ.');
    }

    public function test_forgot_password_rate_limits_repeated_requests(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])
                ->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau.');
    }

    public function test_a_valid_reset_token_changes_the_password_and_revokes_all_tokens(): void
    {
        $user = $this->userWithPassword('old-password');
        $token = Password::broker()->createToken($user);
        $accessToken = $user->createToken('before-reset');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.')
            ->assertJsonPath('data', null);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->accessToken->id]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password'])
            ->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password'])
            ->assertOk();
    }

    public function test_an_invalid_reset_token_returns_a_clear_vietnamese_error(): void
    {
        $user = $this->userWithPassword('old-password');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.');
    }

    public function test_an_expired_reset_token_returns_a_clear_vietnamese_error(): void
    {
        config(['auth.passwords.users.expire' => 1]);
        $this->freezeTime();
        $user = $this->userWithPassword('old-password');
        $token = Password::broker()->createToken($user);
        $this->travel(2)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.');
    }

    public function test_an_authenticated_user_can_change_password_and_keep_only_the_current_token(): void
    {
        $user = $this->userWithPassword('old-password');
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)->putJson('/api/auth/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Đổi mật khẩu thành công.')
            ->assertJsonPath('data', null);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_change_password_rejects_an_incorrect_current_password(): void
    {
        $user = $this->userWithPassword('old-password');
        $token = $user->createToken('current');

        $this->withToken($token->plainTextToken)->putJson('/api/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Mật khẩu hiện tại không chính xác.')
            ->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không chính xác.');
    }

    public function test_change_password_rejects_a_password_that_matches_the_current_one(): void
    {
        $user = $this->userWithPassword('old-password');
        $token = $user->createToken('current');

        $this->withToken($token->plainTextToken)->putJson('/api/auth/password', [
            'current_password' => 'old-password',
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Mật khẩu mới phải khác mật khẩu hiện tại.')
            ->assertJsonPath('errors.password.0', 'Mật khẩu mới phải khác mật khẩu hiện tại.');
    }

    public function test_change_password_rejects_a_mismatched_confirmation(): void
    {
        $user = $this->userWithPassword('old-password');
        $token = $user->createToken('current');

        $this->withToken($token->plainTextToken)->putJson('/api/auth/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    private function userWithPassword(string $password): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ]);
    }
}
