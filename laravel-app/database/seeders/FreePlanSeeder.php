<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class FreePlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Free plan with rolling 30-day usage limits.',
                'price_minor' => 0,
                'currency' => 'USD',
                'interval_days' => 30,
                'limits' => [
                    'reels_per_period' => 5,
                    'ai_actions_per_period' => 20,
                    'max_concurrent_renders' => 1,
                ],
                'is_active' => true,
            ],
        );
    }
}
