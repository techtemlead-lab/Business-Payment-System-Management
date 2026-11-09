<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Inventory\BranchScope;
use App\Support\LocalDigits;
use App\Support\Money;
use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The office queues that stay as their own pages: payments to check and the work log.
 */
final class FinanceSection implements WorkspaceSection
{
    /** Payment methods staff check by hand. A From My Account payment needs no check. */
    private const ClaimChannels = ['Bank', 'bKash', 'Nagad', 'Rocket', 'Office'];

    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'payments' => $this->paymentClaims($context->request, $context->branchScope),
            'audit' => $this->auditLog($context->request),
            default => [],
        };
    }

    /**
     * Payment claims with their order and buyer, on the shared table. On a branch picked in the
     * top bar, only claims on that branch's orders.
     *
     * @return array<string, mixed>
     */
    private function paymentClaims(Request $request, ?BranchScope $branchScope = null): array
    {
        $claims = DB::table('payment_claims')
            ->join('orders', 'orders.id', '=', 'payment_claims.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('company_branches', 'company_branches.id', '=', 'orders.fulfillment_branch_id')
            ->leftJoin('users as checkers', 'checkers.id', '=', 'payment_claims.verified_by')
            ->where('payment_claims.channel', '<>', 'Account')
            ->select(
                'checkers.name as checked_by',
                'payment_claims.updated_at as checked_at',
                'payment_claims.id',
                'payment_claims.order_id',
                'payment_claims.channel',
                'payment_claims.reference',
                'payment_claims.amount',
                'payment_claims.status',
                'payment_claims.reason',
                'payment_claims.created_at',
                DB::raw('(payment_claims.proof_path IS NOT NULL) AS has_proof'),
                'orders.number',
                'orders.total',
                'orders.paid',
                'orders.due_credit',
                'orders.status as order_status',
                'users.name',
                'users.phone',
                'users.dealer_code',
                'users.role',
                'users.district',
                'users.division',
                'company_branches.name as branch_name',
            );
        $branchScope?->restrictOrders($claims);
        $status = $request->string('status')->toString();
        $oneStatus = in_array($status, ['pending', 'verified', 'rejected'], true);
        $spec = new MoneyTableSpec(
            dateColumn: 'payment_claims.created_at',
            search: ['orders.number', 'users.name', 'users.phone', 'users.dealer_code', 'payment_claims.reference'],
            filters: [
                new MoneyTableFilter('channel', (string) __('portal.col_channel'), 'payment_claims.channel', array_map(fn (string $channel): array => ['value' => $channel, 'label' => (string) __('portal.pay_channel_'.strtolower($channel))], self::ClaimChannels)),
                new MoneyTableFilter('buyer_role', (string) __('treasury.filter_buyer_role'), 'users.role', self::partnerRoles()),
            ],
            sorts: [
                'created_at' => 'payment_claims.created_at',
                'number' => 'orders.number',
                'partner' => 'users.name',
                'area' => 'users.district',
                'channel' => 'payment_claims.channel',
                'amount' => 'payment_claims.amount',
                'due' => "CASE WHEN orders.status NOT IN ('rejected', 'cancelled') THEN GREATEST(orders.total - orders.paid - orders.due_credit, 0) ELSE 0 END",
                'status' => 'payment_claims.status',
                'checked_by' => 'checkers.name',
            ],
            defaultSort: 'created_at',
            tieBreaker: 'payment_claims.id',
            sums: ['amount' => 'payment_claims.amount'],
            defaultPeriod: null,
            sumsWhen: fn (): bool => $oneStatus,
            perPage: 20,
        );
        // Counts for the status tabs and money totals for the whole filtered list: every filter except status itself.
        $groups = MoneyTable::filtered($request, $claims, $spec)
            ->select('payment_claims.status', 'payment_claims.channel')
            ->selectRaw('COUNT(*) AS aggregate, COALESCE(SUM(payment_claims.amount), 0) AS amount')
            ->groupBy('payment_claims.status', 'payment_claims.channel')
            ->get();

        return [
            'claim_status_counts' => $groups->groupBy('status')
                ->map(fn ($rows): int => (int) $rows->sum('aggregate'))
                ->all(),
            'claim_totals' => [
                'by_status' => $groups->groupBy('status')->map(fn ($rows): array => [
                    'count' => (int) $rows->sum('aggregate'),
                    'amount' => Money::decimal($rows->sum(fn ($row): int => Money::cents((string) $row->amount))),
                ])->all(),
                'verified_by_channel' => $groups->where('status', 'verified')->groupBy('channel')->map(fn ($rows): string => Money::decimal(
                    $rows->sum(fn ($row): int => Money::cents((string) $row->amount)),
                ))->all(),
            ],
            'payments_table' => MoneyTable::make($request, $oneStatus ? (clone $claims)->where('payment_claims.status', $status) : $claims, $spec),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function partnerRoles(): array
    {
        return [
            ['value' => 'dealer', 'label' => (string) __('portal.acct_role_dealer')],
            ['value' => 'sub_dealer', 'label' => (string) __('portal.acct_role_sub_dealer')],
        ];
    }

    /**
     * The work log on the shared table: who changed what, newest first.
     *
     * @return array<string, mixed>
     */
    private function auditLog(Request $request): array
    {
        $events = DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'actor_id')
            ->select('audit_events.id', 'action', 'subject', 'audit_events.created_at', 'users.name');
        $search = trim($request->string('q')->toString());
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $subjectTerm = '%'.addcslashes(LocalDigits::latin($search), '%_\\').'%';
            $actions = $this->auditActionsNamed($search);
            $events->where(fn ($query) => $query->whereIn('audit_events.action', $actions)
                ->orWhere('audit_events.subject', 'ilike', $subjectTerm)
                ->orWhere('users.name', 'ilike', $term));
        }
        $locale = app()->getLocale();

        return ['work_log_table' => MoneyTable::make($request, $events, new MoneyTableSpec(
            dateColumn: 'audit_events.created_at',
            search: [],
            filters: [new MoneyTableFilter('action', (string) __('portal.audit_action'), 'audit_events.action', DB::table('audit_events')->distinct()->pluck('action')
                ->map(fn (string $action): array => ['value' => $action, 'label' => $this->auditActionName($action, $locale)])
                ->sortBy('label')->values()->all())],
            sorts: ['created_at' => 'audit_events.created_at', 'name' => 'users.name', 'action' => 'audit_events.action'],
            defaultSort: 'created_at',
            tieBreaker: 'audit_events.id',
            defaultPeriod: null,
        ))];
    }

    /**
     * The logged actions whose name on screen contains the search, in either language. The
     * table shows that name and never the stored code, so a search must not match the code.
     *
     * @return list<string>
     */
    private function auditActionsNamed(string $search): array
    {
        $words = $this->comparableText($search);

        return DB::table('audit_events')->distinct()->pluck('action')
            ->filter(fn (string $action): bool => collect(config('madina.locales'))
                ->contains(fn (string $locale): bool => str_contains($this->comparableText($this->auditActionName($action, $locale)), $words)))
            ->values()
            ->all();
    }

    /**
     * The same name auditActionLabel() in UI.tsx puts on the row.
     */
    private function auditActionName(string $action, string $locale): string
    {
        $code = str_replace('.', '_', $action);
        foreach (['event_', 'report_cat_', 'label_', 'acct_role_', 'staff_role_', 'settle_kind_', 'jr_account_', 'status_', ''] as $prefix) {
            $key = 'portal.'.$prefix.$code;
            $name = __($key, [], $locale);
            if ($name !== $key) {
                return (string) $name;
            }
        }

        return str_replace('_', ' ', $code);
    }

    /**
     * Lower case, with the Bangla letters that have two spellings in Unicode (য়, ড়, ঢ়) written
     * one way, so words typed on any keyboard match the same words on the page.
     */
    private function comparableText(string $text): string
    {
        return mb_strtolower(strtr($text, ["\u{09DF}" => "\u{09AF}\u{09BC}", "\u{09DC}" => "\u{09A1}\u{09BC}", "\u{09DD}" => "\u{09A2}\u{09BC}"]));
    }
}
