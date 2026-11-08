<?php

namespace App\Domain\Payouts;

use App\Support\Money;
use App\Support\SpreadsheetCell;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The month-end list of approved partner withdrawals, ready to pay by bank or mobile wallet.
 * Read-only: this never changes a withdrawal. Paying one is still recorded by hand,
 * with its reference and receipt, in the payouts hub.
 */
final class PayoutBatch
{
    public const Channels = ['Bank', 'bKash', 'Nagad', 'Rocket'];

    public const FileColumns = ['serial', 'withdrawal_id', 'dealer_code', 'payee_name', 'channel', 'destination', 'requested_amount', 'tax_withheld', 'net_to_pay', 'requested_on'];

    /**
     * On-screen list: account numbers stay masked here.
     *
     * @return array{rows: list<array<string, mixed>>, count: int, total_net: string}
     */
    public function listing(string $month, ?string $channel): array
    {
        $rows = [];
        $total = 0;
        foreach ($this->approved($month, $channel)->get() as $row) {
            $net = Money::cents((string) ($row->net_amount ?? $row->amount));
            $total += $net;
            $rows[] = [
                'id' => $row->id,
                'dealer_code' => $row->dealer_code,
                'name' => $row->name,
                'channel' => $row->channel,
                'destination_masked' => $this->masked($this->destination($row->destination)),
                'amount' => $row->amount,
                'withheld_amount' => $row->withheld_amount ?? '0.00',
                'net_amount' => Money::decimal($net),
                'requested_on' => $this->requestedOn($row->created_at),
            ];
        }

        return ['rows' => $rows, 'count' => count($rows), 'total_net' => Money::decimal($total)];
    }

    /**
     * File rows, with full account numbers for the bank or wallet upload.
     *
     * @return array{rows: list<list<string>>, ids: list<string>, total_net: string}
     */
    public function fileRows(string $month, ?string $channel): array
    {
        $rows = [];
        $ids = [];
        $total = 0;
        foreach ($this->approved($month, $channel)->get() as $index => $row) {
            $net = Money::cents((string) ($row->net_amount ?? $row->amount));
            $total += $net;
            $ids[] = $row->id;
            $rows[] = [
                (string) ($index + 1),
                $row->id,
                SpreadsheetCell::text((string) $row->dealer_code),
                SpreadsheetCell::text((string) $row->name),
                $row->channel,
                $this->fileDestination($this->destination($row->destination)),
                (string) $row->amount,
                (string) ($row->withheld_amount ?? '0.00'),
                Money::decimal($net),
                $this->requestedOn($row->created_at),
            ];
        }

        return ['rows' => $rows, 'ids' => $ids, 'total_net' => Money::decimal($total)];
    }

    /** Approved and unpaid, requested on or before the end of the chosen month (Asia/Dhaka). */
    private function approved(string $month, ?string $channel): Builder
    {
        $end = CarbonImmutable::parse($month.'-01', 'Asia/Dhaka')->addMonth()->startOfMonth()->utc();

        return DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('w.status', 'approved')
            ->where('w.created_at', '<', $end)
            ->when($channel !== null, fn (Builder $query) => $query->where('w.channel', $channel))
            ->orderBy('w.channel')->orderBy('w.created_at')->orderBy('w.id')
            ->select(['w.id', 'w.channel', 'w.destination', 'w.amount', 'w.withheld_amount', 'w.net_amount', 'w.created_at', 'u.dealer_code', 'u.name']);
    }

    private function destination(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }
        try {
            return (string) decrypt($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    private function masked(string $destination): string
    {
        return $destination === '' ? '' : str_repeat('•', max(mb_strlen($destination) - 4, 0)).mb_substr($destination, -4);
    }

    /** A phone or account number goes out as plain digits, so the bank tool can read it; anything else is made formula-safe. */
    private function fileDestination(string $destination): string
    {
        if (preg_match('/^\+?\d[\d\s-]*$/D', $destination)) {
            return (string) preg_replace('/\D/', '', $destination);
        }

        return SpreadsheetCell::text($destination);
    }

    private function requestedOn(string $createdAt): string
    {
        return CarbonImmutable::parse($createdAt, 'UTC')->timezone('Asia/Dhaka')->toDateString();
    }
}
