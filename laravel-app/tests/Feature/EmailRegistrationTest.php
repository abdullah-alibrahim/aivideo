<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\FreePlanSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FreePlanSeeder::class);
    }

    public function test_registration_routes_are_available_and_show_the_form(): void
    {
        $this->assertTrue(Route::has('register'));
        $this->assertTrue(Route::has('register.store'));

        $this->get('/register')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_new_registration_receives_an_active_free_subscription(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'New Member',
            'email' => '  NEW-MEMBER@example.test ',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(url('/email/verify'));

        $user = User::query()->where('email', 'new-member@example.test')->sole();

        $this->assertFalse($user->is_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $subscription = $user->subscriptions()->sole();
        $this->assertSame('free', $subscription->plan->slug);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->ends_at);
        $this->assertTrue($subscription->auto_renew);
        $this->assertTrue($subscription->starts_at->greaterThanOrEqualTo(now()->subMinute()));
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            return $notification->toMail($user)->subject === 'تحقق من عنوان بريدك الإلكتروني';
        });
    }

    public function test_registration_rolls_back_when_the_free_plan_is_missing(): void
    {
        Plan::query()->where('slug', 'free')->delete();

        $this->post('/register', [
            'name' => 'New Member',
            'email' => 'missing-plan@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertServerError();

        $this->assertDatabaseMissing('users', ['email' => 'missing-plan@example.test']);
    }

    public function test_verification_email_uses_the_selected_english_locale(): void
    {
        Notification::fake();
        $this->get('/register?lang=en')->assertOk();

        $this->post('/register?lang=en', [
            'name' => 'English Member',
            'email' => 'english-member@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(url('/email/verify'));

        $user = User::query()->where('email', 'english-member@example.test')->sole();
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            return $notification->toMail($user)->subject === 'Verify your email address';
        });
    }

    public function test_registration_rejects_invalid_email_and_short_password(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'New Member',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertRedirect('/register')->assertSessionHasErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_rate_limited_without_creating_accounts(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
                ->post('/register', [])
                ->assertSessionHasErrors(['name', 'email', 'password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->post('/register', [])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('users', 0);
    }
}
