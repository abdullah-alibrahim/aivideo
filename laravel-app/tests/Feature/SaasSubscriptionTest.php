<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Reel;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\SubscriptionManager;
use Carbon\CarbonImmutable;
use Database\Seeders\FreePlanSeeder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaasSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_seeding_preserves_existing_users_and_reels(): void
    {
        $user = User::factory()->create();
        $reel = Reel::create([
            'title' => 'Existing reel',
            'topic' => 'Existing topic',
        ]);

        $this->seed(FreePlanSeeder::class);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('reels', ['id' => $reel->id, 'title' => 'Existing reel']);
    }

    public function test_free_plan_seeder_is_idempotent_and_uses_the_approved_limits(): void
    {
        $this->seed(FreePlanSeeder::class);
        $this->seed(FreePlanSeeder::class);

        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseHas('plans', [
            'slug' => 'free',
            'price_minor' => 0,
            'currency' => 'USD',
            'interval_days' => 30,
            'limits' => json_encode([
                'reels_per_period' => 5,
                'ai_actions_per_period' => 20,
                'max_concurrent_renders' => 1,
            ]),
            'is_active' => true,
        ]);
    }

    public function test_seeder_does_not_overwrite_admin_edited_free_limits(): void
    {
        $this->seed(FreePlanSeeder::class);
        $free = Plan::query()->where('slug', 'free')->sole();
        $free->update(['limits' => ['reels_per_period' => 9]]);

        $this->seed(FreePlanSeeder::class);

        $this->assertSame(['reels_per_period' => 9], $free->fresh()->limits);
    }

    public function test_expired_subscription_is_reported_without_deleting_history(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'free')->sole();
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => CarbonImmutable::now()->subDays(40),
            'ends_at' => CarbonImmutable::now()->subDays(10),
            'auto_renew' => false,
        ]);

        $this->assertSame('expired', $subscription->effectiveStatus());
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'active']);
    }

    public function test_usage_events_enforce_an_idempotency_key(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('slug', 'free')->value('id'),
            'status' => 'active',
            'starts_at' => CarbonImmutable::now(),
            'auto_renew' => true,
        ]);
        $event = [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'type' => 'reel_generation',
            'status' => 'reserved',
            'event_key' => 'registration-test-event',
            'quantity' => 1,
            'occurred_at' => CarbonImmutable::now(),
        ];

        UsageEvent::query()->create($event);

        $this->expectException(QueryException::class);
        UsageEvent::query()->create($event);
    }

    public function test_existing_current_subscription_is_not_replaced_by_free_backfill(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'free')->sole();
        $existing = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => CarbonImmutable::now()->subDay(),
            'auto_renew' => true,
        ]);

        $this->artisan('saas:assign-free-subscriptions')->assertExitCode(0);

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $existing->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_backfill_can_be_run_twice_without_duplicate_subscriptions(): void
    {
        $this->seed(FreePlanSeeder::class);
        User::factory()->create();

        $this->artisan('saas:assign-free-subscriptions')
            ->expectsOutput('Assigned 1 free subscription(s).')
            ->assertExitCode(0);
        $this->artisan('saas:assign-free-subscriptions')
            ->expectsOutput('Assigned 0 free subscription(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_assigning_a_second_current_entitlement_is_rejected(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $actor = User::factory()->create(['is_admin' => true]);
        $subscriptionManager = app(SubscriptionManager::class);
        $subscriptionManager->ensureFreeSubscription($user);
        $paidPlan = Plan::query()->create([
            'slug' => 'pro',
            'name' => 'Pro',
            'price_minor' => 1000,
            'currency' => 'USD',
            'interval_days' => 30,
            'limits' => ['reels_per_period' => 100],
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $subscriptionManager->assign(
            $user,
            $paidPlan,
            'active',
            CarbonImmutable::now(),
            CarbonImmutable::now()->addDays(30),
            $actor,
        );
    }

    public function test_changing_plan_preserves_subscription_dates(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $actor = User::factory()->create(['is_admin' => true]);
        $subscription = app(SubscriptionManager::class)->ensureFreeSubscription($user);
        $startsAt = $subscription->starts_at;
        $endsAt = $subscription->ends_at;
        $paidPlan = Plan::query()->create([
            'slug' => 'pro',
            'name' => 'Pro',
            'price_minor' => 1000,
            'currency' => 'USD',
            'interval_days' => 30,
            'limits' => ['reels_per_period' => 100],
            'is_active' => true,
        ]);

        $updated = app(SubscriptionManager::class)->changePlan($subscription, $paidPlan, $actor);

        $this->assertSame($paidPlan->id, $updated->plan_id);
        $this->assertTrue($startsAt->equalTo($updated->starts_at));
        $this->assertSame($endsAt, $updated->ends_at);
    }

    public function test_admin_can_assign_a_manual_subscription(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $actor = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->create([
            'slug' => 'pro',
            'name' => 'Pro',
            'price_minor' => 1000,
            'currency' => 'USD',
            'interval_days' => 30,
            'limits' => ['reels_per_period' => 100],
            'is_active' => true,
        ]);
        $startsAt = CarbonImmutable::now()->subDay()->startOfSecond();
        $endsAt = CarbonImmutable::now()->addDays(29)->startOfSecond();

        $subscription = app(SubscriptionManager::class)->assign(
            $user,
            $plan,
            'trial',
            $startsAt,
            $endsAt,
            $actor,
        );

        $this->assertSame('trial', $subscription->status);
        $this->assertTrue($subscription->starts_at->equalTo($startsAt));
        $this->assertTrue($subscription->ends_at->equalTo($endsAt));
        $this->assertSame($actor->id, $subscription->created_by);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'action' => 'subscription.assigned',
            'target_id' => $subscription->id,
        ]);
    }

    public function test_manual_subscription_can_be_extended(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $actor = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->where('slug', 'free')->sole();
        $manager = app(SubscriptionManager::class);
        $subscription = $manager->assign(
            $user,
            $plan,
            'active',
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDays(10),
            $actor,
        );
        $newEnd = CarbonImmutable::now()->addDays(20)->startOfSecond();

        $extended = $manager->extend($subscription, $newEnd, $actor);

        $this->assertTrue($extended->ends_at->equalTo($newEnd));
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'action' => 'subscription.extended',
        ]);
    }

    public function test_manual_subscription_can_be_canceled(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();
        $actor = User::factory()->create(['is_admin' => true]);
        $manager = app(SubscriptionManager::class);
        $subscription = $manager->assign(
            $user,
            Plan::query()->where('slug', 'free')->sole(),
            'active',
            CarbonImmutable::now()->subDay(),
            null,
            $actor,
        );

        $canceled = $manager->cancel($subscription, $actor);

        $this->assertSame('canceled', $canceled->status);
        $this->assertNotNull($canceled->canceled_at);
        $this->assertFalse($canceled->auto_renew);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $actor->id,
            'action' => 'subscription.canceled',
        ]);
    }

    public function test_models_expose_the_expected_relations_and_audit_records(): void
    {
        $this->seed(FreePlanSeeder::class);
        $user = User::factory()->create();

        $this->assertInstanceOf(HasMany::class, $user->subscriptions());
        $this->assertInstanceOf(HasMany::class, $user->usageEvents());
        $this->assertInstanceOf(HasMany::class, $user->adminAuditEvents());
        $this->assertInstanceOf(Plan::class, Plan::query()->where('slug', 'free')->sole());
        $this->assertInstanceOf(AdminAuditLog::class, new AdminAuditLog);
    }
}
