<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class VerifyEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_delivers_a_vietnamese_html_and_text_verification_email(): void
    {
        config(['mail.default' => 'array', 'app.name' => 'Laravel']);
        $this->freezeTime();

        $this->postJson('/api/register', [
            'name' => 'Nguyễn Văn An',
            'email' => 'owner@example.com',
            'phone' => '0901234567',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('data', null);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('owner@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Xác minh địa chỉ email của bạn', $email->getSubject());
        $this->assertStringContainsString('Quản lý nhà trọ', $email->getHtmlBody());
        $this->assertStringContainsString('Xác minh email', $email->getHtmlBody());
        $this->assertStringContainsString('Spam / Thư rác', $email->getHtmlBody());
        $this->assertStringNotContainsString('Verify Email Address', $email->getHtmlBody());
        $this->assertStringContainsString('Email này được gửi tự động', $email->getTextBody());

        preg_match('/href="([^"]+)"/', $email->getHtmlBody(), $matches);
        $verificationUrl = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $this->assertTrue(URL::hasValidSignature(Request::create($verificationUrl)));
        $this->assertStringContainsString($verificationUrl, $email->getTextBody());
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'email_verified_at' => null]);
    }

    public function test_email_uses_the_configured_expiration_and_the_same_signed_link_for_button_and_fallback(): void
    {
        config(['auth.verification.expire' => 45]);
        $this->freezeTime();
        $user = User::factory()->unverified()->make(['id' => 123]);

        $mail = (new VerifyEmailNotification)->toMail($user);
        $html = (string) $mail->render();

        parse_str(parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame((string) (now()->timestamp + 2700), $query['expires']);
        $this->assertTrue(URL::hasValidSignature(Request::create($mail->actionUrl)));
        $this->assertStringContainsString('45 phút', $html);
        $this->assertSame(2, substr_count($html, 'href="'.e($mail->actionUrl).'"'));
    }

    public function test_signed_link_from_the_custom_email_verifies_the_account(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmailNotification)->toMail($user);

        $this->get($mail->actionUrl)
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/email-verified?status=success');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_expired_link_redirects_as_invalid_and_does_not_verify_the_account(): void
    {
        config(['auth.verification.expire' => 10]);
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmailNotification)->toMail($user);
        $this->travel(11)->minutes();

        $this->get($mail->actionUrl)
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/email-verified?status=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_signed_link_does_not_verify_the_account(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmailNotification)->toMail($user);
        $tamperedUrl = str_replace(sha1($user->email), sha1('different@example.com'), $mail->actionUrl);

        $this->get($tamperedUrl)
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/email-verified?status=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resending_verification_uses_the_custom_notification(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/verification-notification', ['email' => $user->email])
            ->assertOk()->assertJsonPath('message', 'Gửi email xác minh thành công.');

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Xác minh địa chỉ email của bạn'
                && str_contains((string) $mail->render(), 'Spam / Thư rác');
        });
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_html_email_escapes_the_configured_brand_name(): void
    {
        config(['app.name' => '<script>alert("brand")</script>']);
        $this->freezeTime();
        $user = User::factory()->unverified()->make(['id' => 123]);

        $html = (string) (new VerifyEmailNotification)->toMail($user)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
