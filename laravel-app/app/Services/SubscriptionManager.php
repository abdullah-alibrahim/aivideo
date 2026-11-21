<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class SubscriptionManager
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function ensureFreeSubscription(User $user): Subscription
    {
        return DB::transaction(function () use ($user): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $now = CarbonImmutable::now();

            $existing = $lockedUser->subscriptions()
                ->whereIn('status', ['trial', 'active'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('starts_at')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $freePlan = Plan::query()
                ->where('slug', 'free')
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->first();

            if ($freePlan === null) {
                throw new LogicException('The active Free plan is not configured.');
            }

            $subscription = $lockedUser->subscriptions()->create([
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => null,
                'auto_renew' => true,
            ]);

            $this->auditLogger->record(null, 'subscription.free_assigned', $subscription, [
                'plan_slug' => 'free',
                'status' => 'active',
            ]);

            return $subscription;
        });
    }

    public function assign(
        User $user,
        Plan $plan,
        string $status,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        User $actor,
    ): Subscription {
        $this->assertAdministrator($actor);
        $this->assertValidPeriod($status, $startsAt, $endsAt);

        return DB::transaction(function () use ($user, $plan, $status, $startsAt, $endsAt, $actor): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $this->assertAssignablePlan($lockedPlan);
            $this->assertNoOverlappingEntitlement($lockedUser, $startsAt, $endsAt);

            $subscription = $lockedUser->subscriptions()->create([
                'plan_id' => $lockedPlan->id,
                'status' => $status,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'auto_renew' => false,
                'created_by' => $actor->id,
            ]);

            $this->auditLogger->record($actor, 'subscription.assigned', $subscription, [
                'plan_id' => $lockedPlan->id,
                'status' => $status,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    public function changePlan(Subscription $subscription, Plan $plan, User $actor): Subscription
    {
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($subscription, $plan, $actor): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($subscription->user_id);
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $this->assertAssignablePlan($lockedPlan);
            $this->assertNoOverlappingEntitlement(
                $lockedUser,
                $locked->starts_at,
                $locked->ends_at,
                $locked->id,
            );

            $previousPlanId = $locked->plan_id;
            $locked->plan_id = $lockedPlan->id;
            $locked->save();

            $this->auditLogger->record($actor, 'subscription.plan_changed', $locked, [
                'from_plan_id' => $previousPlanId,
                'to_plan_id' => $lockedPlan->id,
            ]);

            return $locked->refresh();
        });
    }

    public function extend(Subscription $subscription, CarbonImmutable $endsAt, User $actor): Subscription
    {
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($subscription, $endsAt, $actor): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($subscription->user_id);
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            if (! in_array($locked->status, ['trial', 'active'], true)
                || $locked->ends_at === null
                || $endsAt->lessThanOrEqualTo($locked->ends_at)
                || $endsAt->lessThanOrEqualTo($locked->starts_at)) {
                throw ValidationException::withMessages([
                    'ends_at' => __('The subscription end date must extend the current period.'),
                ]);
            }

            $this->assertNoOverlappingEntitlement($lockedUser, $locked->starts_at, $endsAt, $locked->id);
            $previousEnd = $locked->ends_at;
            $locked->ends_at = $endsAt;
            $locked->save();

            $this->auditLogger->record($actor, 'subscription.extended', $locked, [
                'from_ends_at' => $previousEnd->toIso8601String(),
                'to_ends_at' => $endsAt->toIso8601String(),
            ]);

            return $locked->refresh();
        });
    }

    public function cancel(Subscription $subscription, User $actor): Subscription
    {
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($subscription, $actor): Subscription {
            User::query()->lockForUpdate()->findOrFail($subscription->user_id);
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            if ($locked->status === 'canceled') {
                return $locked;
            }

            if (! in_array($locked->status, ['trial', 'active'], true)) {
                throw ValidationException::withMessages([
                    'subscription' => __('Only active or trial subscriptions can be canceled.'),
                ]);
            }

            $locked->status = 'canceled';
            $locked->canceled_at = CarbonImmutable::now();
            $locked->auto_renew = false;
            $locked->save();

            $this->auditLogger->record($actor, 'subscription.canceled', $locked, [
                'status' => 'canceled',
                'canceled_at' => $locked->canceled_at->toIso8601String(),
            ]);

            return $locked->refresh();
        });
    }

    private function assertAdministrator(User $actor): void
    {
        if (! $actor->is_admin) {
            abort(403);
        }
    }

    private function assertAssignablePlan(Plan $plan): void
    {
        if (! $plan->is_active || $plan->archived_at !== null) {
            throw ValidationException::withMessages([
                'plan_id' => __('The selected plan is not available for assignment.'),
            ]);
        }
    }

    private function assertValidPeriod(string $status, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt): void
    {
        if (! in_array($status, ['trial', 'active'], true)) {
            throw ValidationException::withMessages([
                'status' => __('A subscription must be active or in trial.'),
            ]);
        }

        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => __('The subscription end date must be after its start date.'),
            ]);
        }
    }

    private function assertNoOverlappingEntitlement(
        User $user,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?int $exceptSubscriptionId = null,
    ): void {
        $subscriptions = $user->subscriptions()
            ->whereIn('status', ['trial', 'active'])
            ->when($exceptSubscriptionId !== null, fn ($query) => $query->whereKeyNot($exceptSubscriptionId))
            ->lockForUpdate()
            ->get(['id', 'starts_at', 'ends_at']);

        foreach ($subscriptions as $existing) {
            $startsBeforeExistingEnds = $existing->ends_at === null || $startsAt->lessThan($existing->ends_at);
            $existingStartsBeforeNewEnds = $endsAt === null || $existing->starts_at->lessThan($endsAt);

            if ($startsBeforeExistingEnds && $existingStartsBeforeNewEnds) {
                throw ValidationException::withMessages([
                    'subscription' => __('The account already has an overlapping subscription.'),
                ]);
            }
        }
    }
}
