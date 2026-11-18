<?php

namespace App\Domain\Support;

use App\Models\PartnerContest;
use App\Models\PartnerEvent;
use App\Models\SalesKitItem;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        $checks = [
            'training' => TrainingItem::query()->visibleTo($user),
            'events' => PartnerEvent::query()->visibleTo($user),
            'sales-kit' => SalesKitItem::query(),
            'contests' => PartnerContest::query()->whereDate('ends_on', '>=', now((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString()),
        ];
        // All four "is there anything?" checks in one read, so every partner page keeps a fixed query budget.
        $columns = [];
        $bindings = [];
        foreach (array_values($checks) as $index => $query) {
            $base = $query->toBase();
            $columns[] = 'EXISTS ('.$base->toSql().') AS has_'.$index;
            array_push($bindings, ...$base->getBindings());
        }
        $row = (array) DB::selectOne('SELECT '.implode(', ', $columns), $bindings);
        $empty = [];
        foreach (array_keys($checks) as $index => $section) {
            if (! ($row['has_'.$index] ?? false)) {
                $empty[] = $section;
            }
        }

        return $empty;
    }
}
