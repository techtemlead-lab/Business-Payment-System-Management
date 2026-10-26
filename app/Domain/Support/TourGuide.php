<?php

namespace App\Domain\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TourGuide
{
    /**
     * @return array{completed: list<string>, pending: list<array{key: string, title: string, steps: list<array{target: string, title: string, body: string}>}>}
     */
    public function forPage(User $user, string $pageKey): array
    {
        $definition = $this->definitions()[$pageKey] ?? null;
        $completed = DB::table('tour_completions')
            ->where('user_id', $user->id)
            ->pluck('page_key')
            ->map(fn ($key) => (string) $key)
            ->all();

        if (! $definition) {
            return ['completed' => $completed, 'pending' => []];
        }

        $pending = in_array($pageKey, $completed, true) ? [] : [[
            'key' => $pageKey,
            'title' => $definition['title'],
            'steps' => $definition['steps'],
        ]];

        return ['completed' => $completed, 'pending' => $pending];
    }

    public function complete(User $user, string $pageKey): void
    {
        if (! array_key_exists($pageKey, $this->definitions())) {
            return;
        }
        DB::table('tour_completions')->updateOrInsert(
            ['user_id' => $user->id, 'page_key' => $pageKey],
            ['completed_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function restart(User $user, ?string $pageKey = null): void
    {
        $query = DB::table('tour_completions')->where('user_id', $user->id);
        if ($pageKey) {
            $query->where('page_key', $pageKey);
        }
        $query->delete();
    }

    /**
     * @return array<string, array{title: string, steps: list<array{target: string, title: string, body: string}>}>
     */
    public function definitions(): array
    {
        return [
            'overview' => [
                'title' => __('portal.tour_overview_title'),
                'steps' => [
                    ['target' => '[data-tour="home-metrics"]', 'title' => __('portal.tour_overview_metrics_title'), 'body' => __('portal.tour_overview_metrics_body')],
                    ['target' => '[data-tour="home-chart"]', 'title' => __('portal.tour_overview_chart_title'), 'body' => __('portal.tour_overview_chart_body')],
                    ['target' => '[data-tour="sidebar-help"]', 'title' => __('portal.tour_overview_help_title'), 'body' => __('portal.tour_overview_help_body')],
                ],
            ],
            'earnings' => [
                'title' => __('portal.tour_earnings_title'),
                'steps' => [
                    ['target' => '[data-tour="wallet-balances"]', 'title' => __('portal.tour_earnings_balance_title'), 'body' => __('portal.tour_earnings_balance_body')],
                    ['target' => '[data-tour="withdrawal-form"]', 'title' => __('portal.tour_earnings_withdraw_title'), 'body' => __('portal.tour_earnings_withdraw_body')],
                    ['target' => '[data-tour="commission-ledger"]', 'title' => __('portal.tour_earnings_ledger_title'), 'body' => __('portal.tour_earnings_ledger_body')],
                ],
            ],
            'orders' => [
                'title' => __('portal.tour_orders_title'),
                'steps' => [
                    ['target' => '[data-tour="orders-table"]', 'title' => __('portal.tour_orders_table_title'), 'body' => __('portal.tour_orders_table_body')],
                    ['target' => '[data-tour="invoice-actions"]', 'title' => __('portal.tour_orders_invoice_title'), 'body' => __('portal.tour_orders_invoice_body')],
                ],
            ],
            'help' => [
                'title' => __('portal.tour_help_title'),
                'steps' => [
                    ['target' => '[data-tour="help-manual"]', 'title' => __('portal.tour_help_manual_title'), 'body' => __('portal.tour_help_manual_body')],
                    ['target' => '[data-tour="help-support"]', 'title' => __('portal.tour_help_support_title'), 'body' => __('portal.tour_help_support_body')],
                ],
            ],
        ];
    }

    /**
     * @return list<array{key: string, title: string}>
     */
    public function manualSections(User $user): array
    {
        $defs = $this->definitions();
        $keys = array_keys($defs);
        if ($user->staff() || $user->owner()) {
            $keys[] = 'admin_support';
        }

        return collect($keys)->map(fn (string $key) => [
            'key' => $key,
            'title' => $defs[$key]['title'] ?? __('portal.manual_'.$key),
            'body' => __('portal.manual_body_'.$key),
        ])->all();
    }
}
