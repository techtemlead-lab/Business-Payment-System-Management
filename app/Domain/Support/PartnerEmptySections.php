<?php

namespace App\Domain\Support;

use App\Models\PartnerContest;
use App\Models\PartnerEvent;
use App\Models\SalesKitItem;
use App\Models\TrainingItem;
use App\Models\User;

/**
 * Menu pages of a dealer or sub-dealer that have nothing to show yet (training, meetings, sales kit,
 * contests). The menu hides them until the company adds content, so an elderly partner is not sent to an empty page.
 */
final class PartnerEmptySections
{
    /** @return list<string> */
    public function for(User $user): array
    {
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true)) {
            return [];
        }
        $empty = [];
        if (! TrainingItem::query()->visibleTo($user)->exists()) {
            $empty[] = 'training';
        }
        if (! PartnerEvent::query()->visibleTo($user)->exists()) {
            $empty[] = 'events';
        }
        if (! SalesKitItem::query()->exists()) {
            $empty[] = 'sales-kit';
        }
        if (! PartnerContest::query()->whereDate('ends_on', '>=', now((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString())->exists()) {
            $empty[] = 'contests';
        }

        return $empty;
    }
}
