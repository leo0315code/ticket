<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class PasswordResetTest extends ApiTestCase
{
    public function test_forgot_password_sends_reset_link_with_frontend_url(): void
    {
        Notification::fake();
        $user = $this->makeUser('user', ['username' => 'reset_user', 'email' => 'reset@example.com']);

        $this->postJson('/api/password/forgot', ['account' => $user->username])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password?')
                && str_contains($url, 'token=')
                && str_contains($url, urlencode($user->email));
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset@example.com']);
    }

    public function test_forgot_password_accepts_email_and_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();
        $user = $this->makeUser('user', ['username' => 'by_email_user', 'email' => 'byemail@example.com']);

        $this->postJson('/api/password/forgot', ['account' => 'byemail@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);

        // 不存在的账号同样 200 且同一句提示
        $this->postJson('/api/password/forgot', ['account' => 'no-such-user'])->assertOk();
        Notification::assertCount(1);
    }

    public function test_forgot_password_without_email_does_not_send_mail(): void
    {
        Notification::fake();
        $user = $this->makeUser('user', ['username' => 'no_mail_user', 'email' => null]);

        $this->postJson('/api/password/forgot', ['account' => $user->username])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_reset_password_changes_password_and_revokes_tokens(): void
    {
        $user = $this->makeUser('user', ['username' => 'ok_user', 'email' => 'ok@example.com']);
        $user->createToken('phone');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'ok@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(password_verify('new-password-123', User::find($user->id)->password));

        $this->postJson('/api/login', [
            'account' => $user->username,
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $this->makeUser('user', ['username' => 'bad_user', 'email' => 'bad@example.com']);

        $this->postJson('/api/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'bad@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->postJson('/api/password/reset', [
            'token' => 'x',
            'email' => 'bad@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }
}
