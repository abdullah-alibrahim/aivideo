<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLogger
{
    public function record(?User $actor, string $action, ?Model $target, array $metadata = []): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
