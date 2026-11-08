<?php

namespace App\Domain\Accounts;

use App\Models\PartnerCustomer;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * A partner's own customers. Every read and write is scoped to the signed-in partner, and only
 * partners get in at all, so no one else, not even the owner, can see the book.
 */
final class CustomerBook
{
    public const PerPage = 20;

    public static function today(): string
    {
        return now((string) config('madina.business_timezone'))->toDateString();
    }

    public static function assertPartner(User $user): void
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 404);
    }

    /** @return array{customers: LengthAwarePaginator<int, PartnerCustomer>, today: string, due_count: int} */
    public function pageFor(User $partner, ?string $search, ?string $status): array
    {
        self::assertPartner($partner);
        $today = self::today();
        $customers = PartnerCustomer::query()
            ->where('partner_id', $partner->id)
            ->when(filled($search), fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('phone', 'ilike', '%'.$search.'%')))
            ->when($status === 'due', fn ($query) => $query->whereNotNull('follow_up_on')->where('follow_up_on', '<=', $today))
            ->orderByRaw('follow_up_on ASC NULLS LAST')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PerPage, ['id', 'name', 'phone', 'notes', 'follow_up_on'])
            ->withQueryString();

        return [
            'customers' => $customers,
            'today' => $today,
            'due_count' => PartnerCustomer::query()->where('partner_id', $partner->id)->whereNotNull('follow_up_on')->where('follow_up_on', '<=', $today)->count(),
        ];
    }

    /** @param array{name: string, phone?: string|null, notes?: string|null, follow_up_on?: string|null} $data */
    public function add(User $partner, array $data): PartnerCustomer
    {
        self::assertPartner($partner);

        return PartnerCustomer::query()->create([...$data, 'partner_id' => $partner->id]);
    }

    /** @param array{name: string, phone?: string|null, notes?: string|null, follow_up_on?: string|null} $data */
    public function change(User $partner, int $customerId, array $data): void
    {
        $this->owned($partner, $customerId)->update($data);
    }

    public function remove(User $partner, int $customerId): void
    {
        $this->owned($partner, $customerId)->delete();
    }

    public function markFollowUpDone(User $partner, int $customerId): void
    {
        $this->owned($partner, $customerId)->update(['follow_up_on' => null]);
    }

    /**
     * Follow-ups due today and already overdue for each partner, in one query.
     *
     * @return array<int, array{due_today: int, overdue: int}>
     */
    public function dueCountsByPartner(): array
    {
        $today = self::today();

        return DB::table('partner_customers')
            ->whereNotNull('follow_up_on')
            ->where('follow_up_on', '<=', $today)
            ->groupBy('partner_id')
            ->selectRaw('partner_id, COUNT(*) FILTER (WHERE follow_up_on = ?) AS due_today, COUNT(*) FILTER (WHERE follow_up_on < ?) AS overdue', [$today, $today])
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->partner_id => ['due_today' => (int) $row->due_today, 'overdue' => (int) $row->overdue]])
            ->all();
    }

    private function owned(User $partner, int $customerId): PartnerCustomer
    {
        self::assertPartner($partner);

        return PartnerCustomer::query()->where('partner_id', $partner->id)->whereKey($customerId)->firstOrFail();
    }
}
