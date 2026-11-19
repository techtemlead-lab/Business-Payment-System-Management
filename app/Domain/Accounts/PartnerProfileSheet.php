<?php

namespace App\Domain\Accounts;

use App\Models\DealerRank;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything the company stores about one dealer or sub-dealer, in plain groups for one office page.
 *
 * The password is not here: the page shows it through the audited reveal in PasswordAccess. The
 * national ID number is shown in full to owners only; other office staff see the last four digits.
 */
final class PartnerProfileSheet
{
    public function __construct(private PartnerKycDocuments $documents) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $partner, User $viewer): array
    {
        $stored = $partner->getAttribute('profile');
        $profile = is_array($stored) ? $stored : [];
        $rank = DealerRank::query()->where('user_id', $partner->id)->first();
        $sponsor = $partner->sponsor_id ? User::query()->whereKey($partner->sponsor_id)->first(['id', 'name', 'dealer_code', 'role']) : null;
        $team = DB::table('referral_tree_paths')->where('ancestor_id', $partner->id)->where('depth', '>', 0)
            ->selectRaw('COUNT(*) AS team, COUNT(*) FILTER (WHERE depth = 1) AS direct')->first();
        $wallet = DB::table('wallets')->where('user_id', $partner->id)->first();

        return [
            'id' => $partner->id,
            'name' => $partner->name,
            'role' => $partner->role,
            'status' => $partner->status,
            'locked' => PartnerProfileLock::isLocked($partner),
            'appointment' => app(PartnerAppointment::class)->for($partner),
            'identity' => [
                'name_bn' => $profile['name_bn'] ?? null,
                'dealer_code' => $partner->dealer_code,
                'refer_code' => $partner->refer_code,
                'father_name' => $profile['father_name'] ?? null,
                'mother_name' => $profile['mother_name'] ?? null,
                'occupation' => $profile['occupation'] ?? null,
                'date_of_birth' => $profile['date_of_birth'] ?? null,
                'shop_name' => $profile['shop_name'] ?? $profile['pharmacy'] ?? null,
                'joined' => $this->iso($partner->getAttribute('created_at')),
                'closed_at' => $this->iso($partner->getAttribute('closed_at')),
                'locale' => $partner->locale,
            ],
            'contact' => [
                'phone' => $partner->phone,
                'email' => $partner->email,
                'extra_contact' => $profile['extra_contact'] ?? null,
                'village' => $profile['village'] ?? null,
                'post_office' => $profile['post_office'] ?? null,
                'union' => $profile['union'] ?? null,
                'upazila' => $profile['upazila'] ?? null,
                'district' => $partner->district,
                'division' => $partner->division,
                'latitude' => $partner->latitude,
                'longitude' => $partner->longitude,
                'directory_visible' => (bool) $partner->directory_visible,
            ],
            'place' => [
                'place_code' => $profile['place_code'] ?? null,
                'area_code' => $profile['area_code'] ?? null,
                'union_place_code' => $profile['union_place_code'] ?? null,
            ],
            'kyc' => [
                'identity_type' => $profile['identity_type'] ?? null,
                'nid' => $this->nid($partner, $viewer),
                'birth_certificate_number' => $profile['birth_certificate_number'] ?? null,
                'documents' => $this->documents->listFor($partner),
            ],
            'network' => [
                'sponsor' => $sponsor ? ['id' => $sponsor->id, 'name' => $sponsor->name, 'dealer_code' => $sponsor->dealer_code, 'role' => $sponsor->role] : null,
                'rank' => $partner->role === 'dealer' ? ($rank->current_rank ?? 'dealer') : null,
                'rank_achieved_at' => $this->iso($rank?->getAttribute('achieved_at')),
                'lifetime_purchases' => Money::decimal(Money::cents((string) ($rank->lifetime_purchase_total ?? '0'))),
                'team_size' => (int) ($team->team ?? 0),
                'direct_count' => (int) ($team->direct ?? 0),
            ],
            'money' => [
                'available' => Money::decimal(Money::cents((string) ($wallet->available ?? '0'))),
                'payouts' => $this->payouts($partner),
            ],
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    /** National ID: full for owners, last four digits for other office staff. */
    private function nid(User $partner, User $viewer): ?string
    {
        $nid = $partner->nid;
        if (! is_string($nid) || $nid === '') {
            return null;
        }

        return $viewer->owner() ? $nid : '••••'.substr($nid, -4);
    }

    /**
     * The last five payouts with how they were paid: bank or mobile banking and the account used.
     *
     * @return list<array{number: string, method: string, bank_name: ?string, account: ?string, net_amount: string, paid_at: string}>
     */
    private function payouts(User $partner): array
    {
        return DB::table('payout_receipts')->where('user_id', $partner->id)->orderByDesc('paid_at')->limit(5)
            ->get(['number', 'method', 'bank_name', 'account_number', 'net_amount', 'paid_at'])
            ->map(fn (object $row): array => [
                'number' => (string) $row->number,
                'method' => (string) $row->method,
                'bank_name' => $row->bank_name,
                'account' => $this->decrypted($row->account_number),
                'net_amount' => Money::decimal(Money::cents((string) $row->net_amount)),
                'paid_at' => (string) $row->paid_at,
            ])->all();
    }

    private function decrypted(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        try {
            return (string) decrypt($cipher);
        } catch (Throwable) {
            return null;
        }
    }
}
