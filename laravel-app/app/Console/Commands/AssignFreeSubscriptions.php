<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SubscriptionManager;
use Illuminate\Console\Command;

class AssignFreeSubscriptions extends Command
{
    protected $signature = 'saas:assign-free-subscriptions';

    protected $description = 'Assign the Free plan to standard accounts without an entitlement.';

    public function handle(SubscriptionManager $subscriptions): int
    {
        $assigned = 0;

        User::query()
            ->where('is_admin', false)
            ->chunkById(100, function ($users) use ($subscriptions, &$assigned): void {
                foreach ($users as $user) {
                    $subscription = $subscriptions->ensureFreeSubscription($user);
                    $assigned += $subscription->wasRecentlyCreated ? 1 : 0;
                }
            });

        $this->info("Assigned {$assigned} free subscription(s).");

        return self::SUCCESS;
    }
}
