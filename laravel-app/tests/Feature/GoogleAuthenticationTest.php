<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google', [
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    public function test_google_authentication_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('oauth.google.redirect'));
        $this->assertTrue(Route::has('oauth.google.callback'));
    }

    public function test_google_redirect_starts_the_provider_flow(): void
    {
        Socialite::fake('google');

        $this->get(route('oauth.google.redirect'))
            ->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_google_redirect_reports_missing_provider_configuration(): void
    {
        config()->set('services.google.client_id', null);

        $this->get(route('oauth.google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');
    }

    public function test_verified_google_profile_creates_a_verified_member(): void
    {
        Socialite::fake('google', $this->googleUser());

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('reels.index'));

        $user = User::query()->where('email', 'google-member@example.test')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('google-user-123', $user->google_id);
        $this->assertFalse($user->is_admin);
        $this->assertNotSame('', $user->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_callback_reuses_the_stable_provider_identity(): void
    {
        $user = User::factory()->create([
            'email' => 'google-member@example.test',
        ]);
        $user->forceFill(['google_id' => 'google-user-123'])->save();
        Socialite::fake('google', $this->googleUser());

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('reels.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_verified_google_profile_links_to_an_existing_verified_email(): void
    {
        $user = User::factory()->create([
            'email' => 'google-member@example.test',
        ]);
        $originalPassword = $user->password;
        Socialite::fake('google', $this->googleUser());

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('reels.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-user-123', $user->fresh()->google_id);
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_google_profile_cannot_link_to_an_unverified_local_account(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'google-member@example.test',
        ]);
        Socialite::fake('google', $this->googleUser());

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_identity_cannot_replace_a_different_link_for_its_email(): void
    {
        $user = User::factory()->create([
            'email' => 'google-member@example.test',
        ]);
        $user->forceFill(['google_id' => 'different-google-user'])->save();
        Socialite::fake('google', $this->googleUser());

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertSame('different-google-user', $user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_profile_without_verified_email_is_rejected(): void
    {
        Socialite::fake('google', $this->googleUser(verified: false));

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_profile_without_a_verified_email_claim_is_rejected(): void
    {
        Socialite::fake('google', $this->googleUser(verified: null));

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_profile_without_email_is_rejected(): void
    {
        Socialite::fake('google', $this->googleUser(email: null));

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_google_state_does_not_create_an_account(): void
    {
        $this->get(route('oauth.google.callback', ['code' => 'provider-code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_provider_failure_shows_an_english_error_without_partial_account(): void
    {
        Socialite::fake('google', fn () => throw new ConnectException(
            'Google unavailable',
            new GuzzleRequest('GET', 'https://www.googleapis.com/oauth2/v4/token'),
        ));

        $this->get(route('oauth.google.callback', ['lang' => 'en']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'oauth' => 'Unable to sign in with Google. Please try again.',
            ]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_buttons_are_available_on_login_and_registration_pages(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('oauth.google.redirect'), false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('oauth.google.redirect'), false);
    }

    private function googleUser(
        string $googleId = 'google-user-123',
        ?string $email = 'google-member@example.test',
        ?bool $verified = true,
    ): GoogleUser {
        $user = GoogleUser::fake([
            'id' => $googleId,
            'name' => 'Google Member',
            'email' => $email,
        ]);
        $rawUser = $user->getRaw();
        if ($verified === null) {
            unset($rawUser['email_verified']);
        } else {
            $rawUser['email_verified'] = $verified;
        }
        $user->setRaw($rawUser);

        return $user;
    }
}
