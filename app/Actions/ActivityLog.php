<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityType;
use App\Models\ActivityEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLog
{
    public function record(
        User $user,
        Model $subject,
        ActivityType $type,
        string $message,
        ?\DateTimeInterface $at = null,
    ): ActivityEntry {
        return ActivityEntry::create([
            'user_id' => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'message' => $message,
            'at' => $at ?? now(),
            'created_at' => $at ?? now(),
        ]);
    }
}
