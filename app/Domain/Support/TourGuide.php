<?php

namespace App\Domain\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TourGuide
{
    /**
     * @return array{
     *   completed: list<string>,
     *   pending: list<array{key: string, title: string, steps: list<array{target: string, title: string, body: string, tip?: string}>}>,
     *   blocked_by: list<array{key: string, title: string, href: string}>
     * }
     */
    public function forPage(User $user, string $pageKey): array
    {
        $definitions = $this->definitionsFor($user);
        $definition = $definitions[$pageKey] ?? null;
        $completed = DB::table('tour_completions')
            ->where('user_id', $user->id)
            ->pluck('page_key')
            ->map(fn ($key) => (string) $key)
            ->all();

        if (! $definition) {
            return ['completed' => $completed, 'pending' => [], 'blocked_by' => []];
        }

        $blockedBy = [];
        foreach ($definition['requires'] ?? [] as $required) {
            if (in_array($required, $completed, true)) {
                continue;
            }
            if (! isset($definitions[$required])) {
                continue;
            }
            $blockedBy[] = [
                'key' => $required,
                'title' => $definitions[$required]['title'],
                'href' => $required === 'overview' ? '/' : '/'.$required,
            ];
        }

        $pending = in_array($pageKey, $completed, true) || $blockedBy !== [] ? [] : [[
            'key' => $pageKey,
            'title' => $definition['title'],
            'audience' => $this->audience($user),
            'steps' => $definition['steps'],
        ]];

        return [
            'completed' => $completed,
            'pending' => $pending,
            'blocked_by' => $blockedBy,
        ];
    }

    public function complete(User $user, string $pageKey): void
    {
        if (! array_key_exists($pageKey, $this->definitionsFor($user))) {
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
     * Audience for first-enter tours: owner, staff, dealer, or sub_dealer.
     */
    public function audience(User $user): string
    {
        if ($user->owner()) {
            return 'owner';
        }
        if ($user->staff() || $user->usingStaffWorkspace()) {
            return 'staff';
        }
        if ($user->role === 'sub_dealer') {
            return 'sub_dealer';
        }

        return 'dealer';
    }

    /**
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string}>}>
     */
    public function definitionsFor(User $user): array
    {
        return match ($this->audience($user)) {
            'owner' => $this->ownerDefinitions(),
            'staff' => $this->staffDefinitions(),
            'sub_dealer' => $this->partnerDefinitions('sub_dealer'),
            default => $this->partnerDefinitions('dealer'),
        };
    }

    /**
     * Partner (dealer) definitions kept for callers that do not pass a user.
     *
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string}>}>
     */
    public function definitions(): array
    {
        return $this->partnerDefinitions('dealer');
    }

    /**
     * @return list<array{key: string, title: string, body: string}>
     */
    public function manualSections(User $user): array
    {
        return app(UserManual::class)->chaptersFor($user);
    }

    /**
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string}>}>
     */
    private function partnerDefinitions(string $role): array
    {
        $defs = [
            'overview' => [
                'title' => __('portal.tour_overview_title'),
                'steps' => [
                    ['target' => '[data-tour="home-profile"]', 'title' => __('portal.tour_overview_welcome_title'), 'body' => __('portal.tour_overview_welcome_body'), 'tip' => __('portal.tour_overview_welcome_tip')],
                    ['target' => '[data-tour="home-metrics"]', 'title' => __('portal.tour_overview_metrics_title'), 'body' => __('portal.tour_overview_metrics_body'), 'tip' => __('portal.tour_overview_metrics_tip')],
                    ['target' => '[data-tour="home-chart"]', 'title' => __('portal.tour_overview_chart_title'), 'body' => __('portal.tour_overview_chart_body')],
                    ['target' => '[data-tour="home-targets"]', 'title' => __('portal.tour_overview_targets_title'), 'body' => __('portal.tour_overview_targets_body'), 'tip' => __('portal.tour_overview_targets_tip')],
                    ['target' => '[data-tour="home-referral"]', 'title' => __('portal.tour_overview_referral_title'), 'body' => __('portal.tour_overview_referral_body')],
                    ['target' => '[data-tour="sidebar-help"]', 'title' => __('portal.tour_overview_help_title'), 'body' => __('portal.tour_overview_help_body'), 'tip' => __('portal.tour_overview_help_tip')],
                ],
            ],
            'profile' => [
                'title' => __('portal.tour_profile_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="profile-panel"]', 'title' => __('portal.tour_profile_panel_title'), 'body' => __('portal.tour_profile_panel_body'), 'tip' => __('portal.tour_profile_panel_tip')],
                    ['target' => '[data-tour="security-link"]', 'title' => __('portal.tour_profile_security_title'), 'body' => __('portal.tour_profile_security_body'), 'tip' => __('portal.tour_profile_security_tip')],
                ],
            ],
            'catalog' => [
                'title' => __('portal.tour_catalog_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="catalog-grid"]', 'title' => __('portal.tour_catalog_grid_title'), 'body' => __('portal.tour_catalog_grid_body'), 'tip' => __('portal.tour_catalog_grid_tip')],
                    ['target' => '[data-tour="catalog-cart"]', 'title' => __('portal.tour_catalog_cart_title'), 'body' => __('portal.tour_catalog_cart_body'), 'tip' => __('portal.tour_catalog_cart_tip')],
                ],
            ],
            'orders' => [
                'title' => __('portal.tour_orders_title'),
                'requires' => ['catalog'],
                'steps' => [
                    ['target' => '[data-tour="orders-table"]', 'title' => __('portal.tour_orders_table_title'), 'body' => __('portal.tour_orders_table_body'), 'tip' => __('portal.tour_orders_table_tip')],
                    ['target' => '[data-tour="invoice-actions"]', 'title' => __('portal.tour_orders_invoice_title'), 'body' => __('portal.tour_orders_invoice_body'), 'tip' => __('portal.tour_orders_invoice_tip')],
                ],
            ],
            'earnings' => [
                'title' => __('portal.tour_earnings_title'),
                'requires' => ['orders'],
                'steps' => [
                    ['target' => '[data-tour="wallet-balances"]', 'title' => __('portal.tour_earnings_balance_title'), 'body' => __('portal.tour_earnings_balance_body'), 'tip' => __('portal.tour_earnings_balance_tip')],
                    ['target' => '[data-tour="commission-ledger"]', 'title' => __('portal.tour_earnings_ledger_title'), 'body' => __('portal.tour_earnings_ledger_body'), 'tip' => __('portal.tour_earnings_ledger_tip')],
                    ['target' => '[data-tour="withdrawal-form"]', 'title' => __('portal.tour_earnings_withdraw_title'), 'body' => __('portal.tour_earnings_withdraw_body'), 'tip' => __('portal.tour_earnings_withdraw_tip')],
                ],
            ],
            'withdrawals' => [
                'title' => __('portal.tour_withdrawals_title'),
                'requires' => ['earnings'],
                'steps' => [
                    ['target' => '[data-tour="wallet-balances"]', 'title' => __('portal.tour_withdrawals_balance_title'), 'body' => __('portal.tour_withdrawals_balance_body'), 'tip' => __('portal.tour_withdrawals_balance_tip')],
                    ['target' => '[data-tour="withdrawal-form"]', 'title' => __('portal.tour_withdrawals_form_title'), 'body' => __('portal.tour_withdrawals_form_body'), 'tip' => __('portal.tour_withdrawals_form_tip')],
                ],
            ],
            'help' => [
                'title' => __('portal.tour_help_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="help-manual"]', 'title' => __('portal.tour_help_manual_title'), 'body' => __('portal.tour_help_manual_body'), 'tip' => __('portal.tour_help_manual_tip')],
                    ['target' => '[data-tour="help-prereq"]', 'title' => __('portal.tour_help_prereq_title'), 'body' => __('portal.tour_help_prereq_body')],
                    ['target' => '[data-tour="help-support"]', 'title' => __('portal.tour_help_support_title'), 'body' => __('portal.tour_help_support_body'), 'tip' => __('portal.tour_help_support_tip')],
                ],
            ],
        ];

        if ($role === 'dealer') {
            $defs['network'] = [
                'title' => __('portal.tour_network_title'),
                'requires' => ['orders'],
                'steps' => [
                    ['target' => '[data-tour="network-panel"]', 'title' => __('portal.tour_network_panel_title'), 'body' => __('portal.tour_network_panel_body'), 'tip' => __('portal.tour_network_panel_tip')],
                ],
            ];
        }

        if ($role === 'sub_dealer') {
            $defs['salary'] = [
                'title' => __('portal.tour_salary_partner_title'),
                'requires' => ['orders'],
                'steps' => [
                    ['target' => '[data-tour="salary-panel"]', 'title' => __('portal.tour_salary_partner_panel_title'), 'body' => __('portal.tour_salary_partner_panel_body'), 'tip' => __('portal.tour_salary_partner_panel_tip')],
                ],
            ];
        }

        return $defs;
    }

    /**
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string}>}>
     */
    private function staffDefinitions(): array
    {
        return [
            'overview' => [
                'title' => __('portal.tour_staff_overview_title'),
                'steps' => [
                    ['target' => '[data-tour="home-metrics"]', 'title' => __('portal.tour_staff_overview_stats_title'), 'body' => __('portal.tour_staff_overview_stats_body'), 'tip' => __('portal.tour_staff_overview_stats_tip')],
                    ['target' => '[data-tour="admin-modules"]', 'title' => __('portal.tour_staff_overview_modules_title'), 'body' => __('portal.tour_staff_overview_modules_body'), 'tip' => __('portal.tour_staff_overview_modules_tip')],
                    ['target' => '[data-tour="sidebar-domains"]', 'title' => __('portal.tour_staff_overview_domains_title'), 'body' => __('portal.tour_staff_overview_domains_body'), 'tip' => __('portal.tour_staff_overview_domains_tip')],
                    ['target' => '[data-tour="sidebar-help"]', 'title' => __('portal.tour_staff_overview_help_title'), 'body' => __('portal.tour_staff_overview_help_body')],
                ],
            ],
            'profile' => [
                'title' => __('portal.tour_profile_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="profile-panel"]', 'title' => __('portal.tour_staff_profile_panel_title'), 'body' => __('portal.tour_staff_profile_panel_body')],
                    ['target' => '[data-tour="security-link"]', 'title' => __('portal.tour_profile_security_title'), 'body' => __('portal.tour_staff_profile_security_body')],
                ],
            ],
            'orders' => [
                'title' => __('portal.tour_staff_orders_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="orders-table"]', 'title' => __('portal.tour_staff_orders_table_title'), 'body' => __('portal.tour_staff_orders_table_body'), 'tip' => __('portal.tour_staff_orders_table_tip')],
                    ['target' => '[data-tour="invoice-actions"]', 'title' => __('portal.tour_staff_orders_invoice_title'), 'body' => __('portal.tour_staff_orders_invoice_body')],
                ],
            ],
            'accounts' => [
                'title' => __('portal.tour_staff_accounts_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="accounts-table"]', 'title' => __('portal.tour_staff_accounts_table_title'), 'body' => __('portal.tour_staff_accounts_table_body'), 'tip' => __('portal.tour_staff_accounts_table_tip')],
                ],
            ],
            'catalog' => [
                'title' => __('portal.tour_staff_catalog_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="catalog-grid"]', 'title' => __('portal.tour_staff_catalog_grid_title'), 'body' => __('portal.tour_staff_catalog_grid_body'), 'tip' => __('portal.tour_staff_catalog_grid_tip')],
                ],
            ],
            'withdrawals' => [
                'title' => __('portal.tour_staff_withdrawals_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="staff-withdrawals"]', 'title' => __('portal.tour_staff_withdrawals_notice_title'), 'body' => __('portal.tour_staff_withdrawals_notice_body'), 'tip' => __('portal.tour_staff_withdrawals_notice_tip')],
                ],
            ],
            'payouts-hub' => [
                'title' => __('portal.tour_staff_payouts_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="payouts-hub"]', 'title' => __('portal.tour_staff_payouts_hub_title'), 'body' => __('portal.tour_staff_payouts_hub_body'), 'tip' => __('portal.tour_staff_payouts_hub_tip')],
                ],
            ],
            'salary' => [
                'title' => __('portal.tour_staff_salary_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="salary-panel"]', 'title' => __('portal.tour_staff_salary_panel_title'), 'body' => __('portal.tour_staff_salary_panel_body'), 'tip' => __('portal.tour_staff_salary_panel_tip')],
                ],
            ],
            'employees' => [
                'title' => __('portal.tour_staff_employees_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="employees-hub"]', 'title' => __('portal.tour_staff_employees_hub_title'), 'body' => __('portal.tour_staff_employees_hub_body'), 'tip' => __('portal.tour_staff_employees_hub_tip')],
                ],
            ],
            'help' => [
                'title' => __('portal.tour_help_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="help-manual"]', 'title' => __('portal.tour_help_manual_title'), 'body' => __('portal.tour_staff_help_manual_body'), 'tip' => __('portal.tour_help_manual_tip')],
                    ['target' => '[data-tour="help-support"]', 'title' => __('portal.tour_staff_help_support_title'), 'body' => __('portal.tour_staff_help_support_body'), 'tip' => __('portal.tour_staff_help_support_tip')],
                ],
            ],
            'support-admin' => [
                'title' => __('portal.tour_staff_support_admin_title'),
                'requires' => ['help'],
                'steps' => [
                    ['target' => '[data-tour="support-admin"]', 'title' => __('portal.tour_staff_support_admin_panel_title'), 'body' => __('portal.tour_staff_support_admin_panel_body'), 'tip' => __('portal.tour_staff_support_admin_panel_tip')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string}>}>
     */
    private function ownerDefinitions(): array
    {
        return [
            ...$this->staffDefinitions(),
            'overview' => [
                'title' => __('portal.tour_owner_overview_title'),
                'steps' => [
                    ['target' => '[data-tour="home-metrics"]', 'title' => __('portal.tour_owner_overview_stats_title'), 'body' => __('portal.tour_owner_overview_stats_body'), 'tip' => __('portal.tour_owner_overview_stats_tip')],
                    ['target' => '[data-tour="admin-modules"]', 'title' => __('portal.tour_owner_overview_modules_title'), 'body' => __('portal.tour_owner_overview_modules_body'), 'tip' => __('portal.tour_owner_overview_modules_tip')],
                    ['target' => '[data-tour="sidebar-domains"]', 'title' => __('portal.tour_owner_overview_domains_title'), 'body' => __('portal.tour_owner_overview_domains_body'), 'tip' => __('portal.tour_owner_overview_domains_tip')],
                    ['target' => '[data-tour="sidebar-help"]', 'title' => __('portal.tour_owner_overview_help_title'), 'body' => __('portal.tour_owner_overview_help_body')],
                ],
            ],
            'treasury' => [
                'title' => __('portal.tour_owner_treasury_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="treasury-panel"]', 'title' => __('portal.tour_owner_treasury_panel_title'), 'body' => __('portal.tour_owner_treasury_panel_body'), 'tip' => __('portal.tour_owner_treasury_panel_tip')],
                ],
            ],
            'benefits-fund' => [
                'title' => __('portal.tour_owner_benefits_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="benefits-fund"]', 'title' => __('portal.tour_owner_benefits_panel_title'), 'body' => __('portal.tour_owner_benefits_panel_body'), 'tip' => __('portal.tour_owner_benefits_panel_tip')],
                ],
            ],
            'employees' => [
                'title' => __('portal.tour_owner_employees_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="employees-hub"]', 'title' => __('portal.tour_owner_employees_hub_title'), 'body' => __('portal.tour_owner_employees_hub_body'), 'tip' => __('portal.tour_owner_employees_hub_tip')],
                ],
            ],
        ];
    }
}
