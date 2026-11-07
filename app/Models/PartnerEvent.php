<?php

namespace App\Models;

use App\Domain\Geography\PlaceGeographyResolver;
use App\Domain\Geography\PlaceTree;
use Database\Factories\PartnerEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A meeting or training session for partners. A draft is only seen by office
 * staff; once published, or later cancelled, partners in its audience see it.
 *
 * @property int $id
 * @property string $title_bn
 * @property string $title_en
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $place
 * @property string|null $online_url
 * @property string $audience
 * @property list<string>|null $divisions Division names, for display only.
 * @property list<int>|null $division_place_ids Division place code ids the event is for.
 * @property string|null $notes
 * @property string $status
 * @property bool $send_sms
 * @property Carbon|null $published_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
class PartnerEvent extends Model
{
    /** @use HasFactory<PartnerEventFactory> */
    use HasFactory;

    public const Timezone = 'Asia/Dhaka';

    public const Audiences = ['all', 'divisions'];

    public const Statuses = ['draft', 'published', 'cancelled'];

    protected $fillable = [
        'title_bn', 'title_en', 'starts_at', 'ends_at', 'place', 'online_url', 'audience', 'divisions', 'division_place_ids', 'notes',
        'status', 'send_sms', 'published_at', 'cancelled_at', 'cancel_reason', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'divisions' => 'array',
            'division_place_ids' => 'array',
            'send_sms' => 'boolean',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Published or cancelled events whose audience includes this partner.
     * Drafts, and events cancelled before they were ever published, stay hidden.
     *
     * @param  Builder<PartnerEvent>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('status', ['published', 'cancelled'])
            ->whereNotNull('published_at')
            ->where(function (Builder $audience) use ($user): void {
                $audience->where('audience', 'all');
                $division = self::partnerDivisionId($user);
                if ($division !== null) {
                    $audience->orWhere(fn (Builder $byDivision) => $byDivision
                        ->where('audience', 'divisions')
                        ->whereJsonContains('division_place_ids', $division));
                }
            });
    }

    public function isVisibleTo(User $user): bool
    {
        if (! in_array($this->status, ['published', 'cancelled'], true) || $this->published_at === null) {
            return false;
        }
        $division = $this->audience === 'all' ? null : self::partnerDivisionId($user);

        return $this->audience === 'all'
            || ($division !== null && in_array($division, array_map('intval', $this->division_place_ids ?? []), true));
    }

    /**
     * The division place code id a partner's place code lies in; a partner without a
     * place code is in no division (their typed division name is never used).
     */
    public static function partnerDivisionId(User $user): ?int
    {
        return PlaceTree::divisionIdOf(PlaceGeographyResolver::profilePlaceId($user));
    }

    public function startsInDhaka(): Carbon
    {
        return $this->starts_at->copy()->setTimezone(self::Timezone);
    }

    public function endsInDhaka(): Carbon
    {
        return $this->ends_at->copy()->setTimezone(self::Timezone);
    }
}
