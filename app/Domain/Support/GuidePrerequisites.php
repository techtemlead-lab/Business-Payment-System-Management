<?php

namespace App\Domain\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GuidePrerequisites
{
    /**
     * Ordered partner journey. Visiting a later step without earlier readiness
     * surfaces a helper telling the user what to finish first.
     *
     * @var list<string>
     */
    public const PartnerPath = [
        'overview',
        'profile',
        'catalog',
        'orders',
        'earnings',
        'withdrawals',
        'full-report',
        'help',
    ];

    /**
     * @return array{
     *   current: string,
     *   ready: bool,
     *   missing: list<array{key: string, title: string, reason: string, href: string}>,
     *   message: ?string,
     *   checklist: list<array{key: string, title: string, done: bool, href: string}>
     * }
     */
    public function forSection(User $user, string $section): array
    {
        if (! in_array($user->role, ['dealer', 'sub_dealer'], true) || $user->usingStaffWorkspace()) {
            return [
                'current' => $section,
                'ready' => true,
                'missing' => [],
                'message' => null,
                'checklist' => [],
            ];
        }

        $path = $user->role === 'sub_dealer'
            ? array_values(array_filter(self::PartnerPath, fn (string $key): bool => $key !== 'network'))
            : self::PartnerPath;

        if (! in_array($section, $path, true)) {
            $section = 'overview';
        }

        // Every step asks the same two questions (which tours are done, has the
        // partner ordered yet), so each is answered once for the whole checklist.
        $completedTours = DB::table('tour_completions')
            ->where('user_id', $user->id)
            ->pluck('page_key')
            ->map(fn ($pageKey): string => (string) $pageKey)
            ->all();
        $placedAnyOrder = null;
        $hasOrders = function () use ($user, &$placedAnyOrder): bool {
            return $placedAnyOrder ??= Order::query()->where('user_id', $user->id)->exists();
        };

        $status = [];
        foreach ($path as $key) {
            $status[$key] = $this->isSatisfied($user, $key, $completedTours, $hasOrders);
        }

        $checklist = [];
        foreach ($path as $key) {
            $checklist[] = [
                'key' => $key,
                'title' => $this->title($key),
                'done' => $status[$key],
                'href' => $this->href($key),
            ];
        }

        $missing = [];
        $currentIndex = array_search($section, $path, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        // Help hub always stays open; it still shows the journey checklist.
        if ($section !== 'help') {
            for ($i = 0; $i < $currentIndex; $i++) {
                $key = $path[$i];
                if ($status[$key]) {
                    continue;
                }
                $missing[] = [
                    'key' => $key,
                    'title' => $this->title($key),
                    'reason' => $this->reason($key),
                    'href' => $this->href($key),
                ];
            }
        }

        return [
            'current' => $section,
            'ready' => $missing === [],
            'missing' => $missing,
            'message' => $missing === []
                ? null
                : __('portal.prereq_message', [
                    'current' => $this->title($section),
                    'first' => $missing[0]['title'],
                ]),
            'checklist' => $checklist,
        ];
    }

    /**
     * @param  list<string>  $completedTours  Page keys of the tours this partner finished.
     * @param  \Closure(): bool  $hasOrders  Whether the partner placed any order, looked up at most once.
     */
    private function isSatisfied(User $user, string $key, array $completedTours, \Closure $hasOrders): bool
    {
        $tourDone = fn (string $pageKey): bool => in_array($pageKey, $completedTours, true);

        return match ($key) {
            'overview' => true,
            'profile' => $tourDone('overview')
                || $tourDone('profile')
                || filled($user->phone),
            'catalog' => $tourDone('catalog')
                || $hasOrders(),
            'orders' => $hasOrders()
                || $tourDone('orders'),
            'earnings' => $tourDone('earnings')
                || DB::table('commission_allocations')->where('user_id', $user->id)->exists()
                || Order::query()->where('user_id', $user->id)->whereIn('status', ['approved', 'partially_dispatched', 'dispatched', 'delivered'])->exists(),
            'withdrawals' => $tourDone('earnings')
                || DB::table('withdrawals')->where('user_id', $user->id)->exists()
                || (float) (DB::table('wallets')->where('user_id', $user->id)->value('available') ?? 0) > 0,
            'full-report' => $tourDone('full-report')
                || $hasOrders(),
            'help' => true,
            default => $tourDone($key),
        };
    }

    private function title(string $key): string
    {
        return __('portal.prereq_step_'.$key);
    }

    private function reason(string $key): string
    {
        return __('portal.prereq_reason_'.$key);
    }

    private function href(string $key): string
    {
        return match ($key) {
            'overview' => '/',
            default => '/'.$key,
        };
    }
}
