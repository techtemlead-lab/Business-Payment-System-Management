<?php

namespace App\Domain\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TourGuide
{
    /**
     * @return array{
     *   completed: list<string>,
     *   pending: list<array{key: string, title: string, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>,
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

        $isFirstLogin = ! in_array('overview', $completed, true);
        $targetKey = $isFirstLogin ? 'overview' : $pageKey;
        $targetDef = $definitions[$targetKey] ?? $definition ?? null;

        $blockedBy = [];
        if ($targetDef && isset($targetDef['requires'])) {
            foreach ($targetDef['requires'] as $required) {
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
        }

        $pending = [];
        if ($targetDef && ! in_array($targetKey, $completed, true) && $blockedBy === []) {
            $pending[] = [
                'key' => $targetKey,
                'title' => $targetDef['title'],
                'audience' => $this->audience($user),
                'steps' => $targetDef['steps'],
            ];
        }

        $allTours = [];
        foreach ($definitions as $key => $def) {
            $allTours[] = [
                'key' => $key,
                'title' => $def['title'],
                'step_count' => count($def['steps']),
                'completed' => in_array($key, $completed, true),
                'steps' => $def['steps'],
            ];
        }

        return [
            'completed' => $completed,
            'pending' => $pending,
            'blocked_by' => $blockedBy,
            'is_first_login' => $isFirstLogin,
            'all_tours' => $allTours,
            'chapters' => app(UserManual::class)->chaptersFor($user),
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
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
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
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
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
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
     */
    private function partnerDefinitions(string $role): array
    {
        $dealerOverviewSteps = [
            [
                'target' => '[data-tour="home-profile"]',
                'category' => __('portal.tour_cat_profile'),
                'location' => __('portal.tour_dealer_step_profile_loc'),
                'title' => __('portal.tour_dealer_step_profile_title'),
                'body' => __('portal.tour_dealer_step_profile_body'),
                'action' => __('portal.tour_dealer_step_profile_action'),
                'tip' => __('portal.tour_dealer_step_profile_tip'),
            ],
            [
                'target' => '[data-tour="home-metrics"]',
                'category' => __('portal.tour_cat_metrics'),
                'location' => __('portal.tour_dealer_step_metrics_loc'),
                'title' => __('portal.tour_dealer_step_metrics_title'),
                'body' => __('portal.tour_dealer_step_metrics_body'),
                'action' => __('portal.tour_dealer_step_metrics_action'),
                'tip' => __('portal.tour_dealer_step_metrics_tip'),
            ],
            [
                'target' => '[data-tour="home-chart"]',
                'category' => __('portal.tour_cat_chart'),
                'location' => __('portal.tour_dealer_step_chart_loc'),
                'title' => __('portal.tour_dealer_step_chart_title'),
                'body' => __('portal.tour_dealer_step_chart_body'),
                'action' => __('portal.tour_dealer_step_chart_action'),
                'tip' => __('portal.tour_dealer_step_chart_tip'),
            ],
            [
                'target' => '[data-tour="home-targets"]',
                'category' => __('portal.tour_cat_targets'),
                'location' => __('portal.tour_dealer_step_targets_loc'),
                'title' => __('portal.tour_dealer_step_targets_title'),
                'body' => __('portal.tour_dealer_step_targets_body'),
                'action' => __('portal.tour_dealer_step_targets_action'),
                'tip' => __('portal.tour_dealer_step_targets_tip'),
            ],
            [
                'target' => '[data-tour="home-referral"]',
                'category' => __('portal.tour_cat_network'),
                'location' => __('portal.tour_dealer_step_referral_loc'),
                'title' => __('portal.tour_dealer_step_referral_title'),
                'body' => __('portal.tour_dealer_step_referral_body'),
                'action' => __('portal.tour_dealer_step_referral_action'),
                'tip' => __('portal.tour_dealer_step_referral_tip'),
            ],
            [
                'target' => '[data-tour="recent-orders"]',
                'category' => __('portal.tour_cat_orders'),
                'location' => __('portal.tour_dealer_step_orders_loc'),
                'title' => __('portal.tour_dealer_step_orders_title'),
                'body' => __('portal.tour_dealer_step_orders_body'),
                'action' => __('portal.tour_dealer_step_orders_action'),
                'tip' => __('portal.tour_dealer_step_orders_tip'),
            ],
            [
                'target' => '[data-tour="header-actions"]',
                'category' => __('portal.tour_cat_controls'),
                'location' => __('portal.tour_dealer_step_header_loc'),
                'title' => __('portal.tour_dealer_step_header_title'),
                'body' => __('portal.tour_dealer_step_header_body'),
                'action' => __('portal.tour_dealer_step_header_action'),
                'tip' => __('portal.tour_dealer_step_header_tip'),
            ],
            [
                'target' => '[data-tour="sidebar-nav"]',
                'category' => __('portal.tour_cat_nav'),
                'location' => __('portal.tour_dealer_step_sidebar_loc'),
                'title' => __('portal.tour_dealer_step_sidebar_title'),
                'body' => __('portal.tour_dealer_step_sidebar_body'),
                'action' => __('portal.tour_dealer_step_sidebar_action'),
                'tip' => __('portal.tour_dealer_step_sidebar_tip'),
            ],
        ];

        $subDealerOverviewSteps = [
            [
                'target' => '[data-tour="home-profile"]',
                'category' => __('portal.tour_cat_profile'),
                'location' => __('portal.tour_subdealer_step_profile_loc'),
                'title' => __('portal.tour_subdealer_step_profile_title'),
                'body' => __('portal.tour_subdealer_step_profile_body'),
                'action' => __('portal.tour_subdealer_step_profile_action'),
                'tip' => __('portal.tour_subdealer_step_profile_tip'),
            ],
            [
                'target' => '[data-tour="sponsor-contact"]',
                'category' => __('portal.tour_cat_sponsor'),
                'location' => __('portal.tour_subdealer_step_sponsor_loc'),
                'title' => __('portal.tour_subdealer_step_sponsor_title'),
                'body' => __('portal.tour_subdealer_step_sponsor_body'),
                'action' => __('portal.tour_subdealer_step_sponsor_action'),
                'tip' => __('portal.tour_subdealer_step_sponsor_tip'),
            ],
            [
                'target' => '[data-tour="home-metrics"]',
                'category' => __('portal.tour_cat_metrics'),
                'location' => __('portal.tour_subdealer_step_metrics_loc'),
                'title' => __('portal.tour_subdealer_step_metrics_title'),
                'body' => __('portal.tour_subdealer_step_metrics_body'),
                'action' => __('portal.tour_subdealer_step_metrics_action'),
                'tip' => __('portal.tour_subdealer_step_metrics_tip'),
            ],
            [
                'target' => '[data-tour="home-targets"]',
                'category' => __('portal.tour_cat_targets'),
                'location' => __('portal.tour_subdealer_step_targets_loc'),
                'title' => __('portal.tour_subdealer_step_targets_title'),
                'body' => __('portal.tour_subdealer_step_targets_body'),
                'action' => __('portal.tour_subdealer_step_targets_action'),
                'tip' => __('portal.tour_subdealer_step_targets_tip'),
            ],
            [
                'target' => '[data-tour="home-chart"]',
                'category' => __('portal.tour_cat_chart'),
                'location' => __('portal.tour_subdealer_step_chart_loc'),
                'title' => __('portal.tour_subdealer_step_chart_title'),
                'body' => __('portal.tour_subdealer_step_chart_body'),
                'action' => __('portal.tour_subdealer_step_chart_action'),
                'tip' => __('portal.tour_subdealer_step_chart_tip'),
            ],
            [
                'target' => '[data-tour="home-referral"]',
                'category' => __('portal.tour_cat_network'),
                'location' => __('portal.tour_subdealer_step_referral_loc'),
                'title' => __('portal.tour_subdealer_step_referral_title'),
                'body' => __('portal.tour_subdealer_step_referral_body'),
                'action' => __('portal.tour_subdealer_step_referral_action'),
                'tip' => __('portal.tour_subdealer_step_referral_tip'),
            ],
            [
                'target' => '[data-tour="recent-orders"]',
                'category' => __('portal.tour_cat_orders'),
                'location' => __('portal.tour_subdealer_step_orders_loc'),
                'title' => __('portal.tour_subdealer_step_orders_title'),
                'body' => __('portal.tour_subdealer_step_orders_body'),
                'action' => __('portal.tour_subdealer_step_orders_action'),
                'tip' => __('portal.tour_subdealer_step_orders_tip'),
            ],
            [
                'target' => '[data-tour="header-actions"]',
                'category' => __('portal.tour_cat_controls'),
                'location' => __('portal.tour_subdealer_step_header_loc'),
                'title' => __('portal.tour_subdealer_step_header_title'),
                'body' => __('portal.tour_subdealer_step_header_body'),
                'action' => __('portal.tour_subdealer_step_header_action'),
                'tip' => __('portal.tour_subdealer_step_header_tip'),
            ],
            [
                'target' => '[data-tour="sidebar-nav"]',
                'category' => __('portal.tour_cat_nav'),
                'location' => __('portal.tour_subdealer_step_sidebar_loc'),
                'title' => __('portal.tour_subdealer_step_sidebar_title'),
                'body' => __('portal.tour_subdealer_step_sidebar_body'),
                'action' => __('portal.tour_subdealer_step_sidebar_action'),
                'tip' => __('portal.tour_subdealer_step_sidebar_tip'),
            ],
        ];

        $defs = [
            'overview' => [
                'title' => $role === 'sub_dealer' ? __('portal.tour_subdealer_overview_title') : __('portal.tour_overview_title'),
                'steps' => $role === 'sub_dealer' ? $subDealerOverviewSteps : $dealerOverviewSteps,
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
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
     */
    private function staffDefinitions(): array
    {
        return [
            'overview' => [
                'title' => __('portal.tour_staff_overview_title'),
                'steps' => [
                    [
                        'target' => '[data-tour="home-metrics"]',
                        'category' => __('portal.tour_cat_metrics'),
                        'location' => __('portal.tour_staff_step_stats_loc'),
                        'title' => __('portal.tour_staff_step_stats_title'),
                        'body' => __('portal.tour_staff_step_stats_body'),
                        'action' => __('portal.tour_staff_step_stats_action'),
                        'tip' => __('portal.tour_staff_step_stats_tip'),
                    ],
                    [
                        'target' => '[data-tour="admin-modules"]',
                        'category' => __('portal.tour_cat_command'),
                        'location' => __('portal.tour_staff_step_modules_loc'),
                        'title' => __('portal.tour_staff_step_modules_title'),
                        'body' => __('portal.tour_staff_step_modules_body'),
                        'action' => __('portal.tour_staff_step_modules_action'),
                        'tip' => __('portal.tour_staff_step_modules_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-domains"]',
                        'category' => __('portal.tour_cat_domains'),
                        'location' => __('portal.tour_staff_step_domains_loc'),
                        'title' => __('portal.tour_staff_step_domains_title'),
                        'body' => __('portal.tour_staff_step_domains_body'),
                        'action' => __('portal.tour_staff_step_domains_action'),
                        'tip' => __('portal.tour_staff_step_domains_tip'),
                    ],
                    [
                        'target' => '[data-tour="recent-orders"]',
                        'category' => __('portal.tour_cat_orders'),
                        'location' => __('portal.tour_staff_step_orders_loc'),
                        'title' => __('portal.tour_staff_step_orders_title'),
                        'body' => __('portal.tour_staff_step_orders_body'),
                        'action' => __('portal.tour_staff_step_orders_action'),
                        'tip' => __('portal.tour_staff_step_orders_tip'),
                    ],
                    [
                        'target' => '[data-tour="header-actions"]',
                        'category' => __('portal.tour_cat_controls'),
                        'location' => __('portal.tour_staff_step_header_loc'),
                        'title' => __('portal.tour_staff_step_header_title'),
                        'body' => __('portal.tour_staff_step_header_body'),
                        'action' => __('portal.tour_staff_step_header_action'),
                        'tip' => __('portal.tour_staff_step_header_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-nav"]',
                        'category' => __('portal.tour_cat_nav'),
                        'location' => __('portal.tour_staff_step_sidebar_loc'),
                        'title' => __('portal.tour_staff_step_sidebar_title'),
                        'body' => __('portal.tour_staff_step_sidebar_body'),
                        'action' => __('portal.tour_staff_step_sidebar_action'),
                        'tip' => __('portal.tour_staff_step_sidebar_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-help"]',
                        'category' => __('portal.tour_cat_ops'),
                        'location' => __('portal.tour_staff_step_help_loc'),
                        'title' => __('portal.tour_staff_step_help_title'),
                        'body' => __('portal.tour_staff_step_help_body'),
                        'action' => __('portal.tour_staff_step_help_action'),
                        'tip' => __('portal.tour_staff_step_help_tip'),
                    ],
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
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
     */
    private function ownerDefinitions(): array
    {
        return [
            ...$this->staffDefinitions(),
            'overview' => [
                'title' => __('portal.tour_owner_overview_title'),
                'steps' => [
                    [
                        'target' => '[data-tour="home-metrics"]',
                        'category' => __('portal.tour_cat_metrics'),
                        'location' => __('portal.tour_owner_step_stats_loc'),
                        'title' => __('portal.tour_owner_step_stats_title'),
                        'body' => __('portal.tour_owner_step_stats_body'),
                        'action' => __('portal.tour_owner_step_stats_action'),
                        'tip' => __('portal.tour_owner_step_stats_tip'),
                    ],
                    [
                        'target' => '[data-tour="admin-modules"]',
                        'category' => __('portal.tour_cat_command'),
                        'location' => __('portal.tour_owner_step_modules_loc'),
                        'title' => __('portal.tour_owner_step_modules_title'),
                        'body' => __('portal.tour_owner_step_modules_body'),
                        'action' => __('portal.tour_owner_step_modules_action'),
                        'tip' => __('portal.tour_owner_step_modules_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-domains"]',
                        'category' => __('portal.tour_cat_domains'),
                        'location' => __('portal.tour_owner_step_domains_loc'),
                        'title' => __('portal.tour_owner_step_domains_title'),
                        'body' => __('portal.tour_owner_step_domains_body'),
                        'action' => __('portal.tour_owner_step_domains_action'),
                        'tip' => __('portal.tour_owner_step_domains_tip'),
                    ],
                    [
                        'target' => '[data-tour="recent-orders"]',
                        'category' => __('portal.tour_cat_orders'),
                        'location' => __('portal.tour_owner_step_orders_loc'),
                        'title' => __('portal.tour_owner_step_orders_title'),
                        'body' => __('portal.tour_owner_step_orders_body'),
                        'action' => __('portal.tour_owner_step_orders_action'),
                        'tip' => __('portal.tour_owner_step_orders_tip'),
                    ],
                    [
                        'target' => '[data-tour="header-actions"]',
                        'category' => __('portal.tour_cat_controls'),
                        'location' => __('portal.tour_owner_step_header_loc'),
                        'title' => __('portal.tour_owner_step_header_title'),
                        'body' => __('portal.tour_owner_step_header_body'),
                        'action' => __('portal.tour_owner_step_header_action'),
                        'tip' => __('portal.tour_owner_step_header_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-nav"]',
                        'category' => __('portal.tour_cat_nav'),
                        'location' => __('portal.tour_owner_step_sidebar_loc'),
                        'title' => __('portal.tour_owner_step_sidebar_title'),
                        'body' => __('portal.tour_owner_step_sidebar_body'),
                        'action' => __('portal.tour_owner_step_sidebar_action'),
                        'tip' => __('portal.tour_owner_step_sidebar_tip'),
                    ],
                    [
                        'target' => '[data-tour="sidebar-help"]',
                        'category' => __('portal.tour_cat_ops'),
                        'location' => __('portal.tour_owner_step_help_loc'),
                        'title' => __('portal.tour_owner_step_help_title'),
                        'body' => __('portal.tour_owner_step_help_body'),
                        'action' => __('portal.tour_owner_step_help_action'),
                        'tip' => __('portal.tour_owner_step_help_tip'),
                    ],
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
