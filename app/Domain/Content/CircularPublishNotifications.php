<?php

namespace App\Domain\Content;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fan out in-app partner notices when company circulars/notices first publish.
 * Does not send SMS or post financial entries.
 */
final class CircularPublishNotifications
{
    private const PARTNER_SLUGS = [
        'notice',
        'circular-training',
        'circular-price',
        'circular-meeting',
    ];

    public function notifyIfNewlyPublished(string $slug, string $title, bool $published, bool $wasPublished): void
    {
        if (! $published || $wasPublished || ! in_array($slug, self::PARTNER_SLUGS, true)) {
            return;
        }

        $kindKey = match ($slug) {
            'circular-training' => 'portal.circular_kind_training',
            'circular-price' => 'portal.circular_kind_price',
            'circular-meeting' => 'portal.circular_kind_meeting',
            default => 'portal.circular_kind_notice',
        };

        User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->select(['id', 'locale'])
            ->chunkById(500, function (Collection $users) use ($title, $kindKey): void {
                $timestamp = now();
                DB::table('account_notices')->insert($users->map(function (User $user) use ($title, $kindKey, $timestamp): array {
                    $locale = $user->preferredLocale();
                    $kind = __($kindKey, [], $locale);

                    return [
                        'user_id' => $user->id,
                        'title' => __('portal.notice_circular_published_title', ['kind' => $kind], $locale),
                        'body' => __('portal.notice_circular_published_body', [
                            'kind' => $kind,
                            'title' => $title,
                        ], $locale),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                })->all());
            });
    }
}
