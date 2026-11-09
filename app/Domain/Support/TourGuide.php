<?php

namespace App\Domain\Support;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Finance\TreasuryAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TourGuide
{
    /**
     * @return array{
     *   completed: list<string>,
     *   pending: list<array{key: string, title: string, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>,
     *   blocked_by: list<array{key: string, title: string, href: string}>,
     *   is_first_login: bool,
     *   all_tours: list<array{key: string, title: string, step_count: int, completed: bool, steps: list<array<string, string>>}>,
     *   chapters: list<never>,
     *   labels: array{this_page: string, show_me: string}
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

        // Manual chapters ship once via shared `userManualChapters` — keep tour payloads lean.
        return [
            'completed' => $completed,
            'pending' => $pending,
            'blocked_by' => $blockedBy,
            'is_first_login' => $isFirstLogin,
            'all_tours' => $allTours,
            'chapters' => [],
            'labels' => [
                'this_page' => (string) __('manual.tour_this_page'),
                'show_me' => (string) __('manual.tour_show_me'),
            ],
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
            'owner' => $this->ownerDefinitions($user),
            'staff' => $this->staffDefinitions($user),
            'sub_dealer' => $this->partnerDefinitions('sub_dealer', $user),
            default => $this->partnerDefinitions('dealer', $user),
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
    private function partnerDefinitions(string $role, ?User $user = null): array
    {
        $sideMenu = '[data-tour="sidebar-nav"], .workspace-menu-button';
        $courierStep = $this->overviewStep('[data-tour="recent-orders"]', 'tour_partner_courier', 'tour_cat_orders', 'manual');
        // The cards are drawn only when there is something to say; the tour skips them otherwise.
        $alertsStep = $this->overviewStep('[data-testid="partner-alerts"], [data-testid="next-steps"]', 'tour_partner_alerts', 'tour_cat_targets', 'manual');
        $moneyStep = $this->overviewStep($sideMenu, 'tour_partner_money', 'tour_cat_nav', 'manual');
        $buyStep = $this->overviewStep('.ph-actions', 'tour_partner_buy', 'tour_cat_buy', 'manual');
        $incomeStep = $this->overviewStep('.mm-income', 'tour_partner_income', 'tour_cat_metrics', 'manual');
        $sidebarStep = $this->overviewStep($sideMenu, 'tour_partner_sidebar', 'tour_cat_nav', 'manual');
        $prescriptionSteps = $user !== null && PrescriptionAccess::canPrescribe($user)
            ? [$this->overviewStep($sideMenu, 'tour_partner_rx', 'tour_cat_nav', 'manual')]
            : [];

        $dealerOverviewSteps = [
            $this->overviewStep('[data-tour="home-profile"]', 'tour_dealer_step_profile', 'tour_cat_profile'),
            $buyStep,
            $incomeStep,
            $this->overviewStep('[data-tour="home-metrics"]', 'tour_dealer_step_metrics', 'tour_cat_metrics'),
            $alertsStep,
            $this->overviewStep('[data-tour="home-targets"]', 'tour_dealer_step_targets', 'tour_cat_targets'),
            $this->overviewStep('[data-tour="recent-orders"]', 'tour_dealer_step_orders', 'tour_cat_orders'),
            $courierStep,
            $this->overviewStep('[data-tour="home-referral"]', 'tour_dealer_step_referral', 'tour_cat_network'),
            $this->overviewStep('[data-tour="header-actions"]', 'tour_dealer_step_header', 'tour_cat_controls'),
            ...$this->topBarSteps(),
            $sidebarStep,
            $moneyStep,
            ...$prescriptionSteps,
        ];

        $subDealerOverviewSteps = [
            $this->overviewStep('[data-tour="home-profile"]', 'tour_subdealer_step_profile', 'tour_cat_profile'),
            $buyStep,
            $incomeStep,
            $this->overviewStep('[data-tour="sponsor-contact"]', 'tour_subdealer_step_sponsor', 'tour_cat_sponsor'),
            $this->overviewStep('[data-tour="home-metrics"]', 'tour_subdealer_step_metrics', 'tour_cat_metrics'),
            $alertsStep,
            $this->overviewStep('[data-tour="home-targets"]', 'tour_subdealer_step_targets', 'tour_cat_targets'),
            $this->overviewStep('[data-tour="recent-orders"]', 'tour_subdealer_step_orders', 'tour_cat_orders'),
            $courierStep,
            $this->overviewStep('[data-tour="home-referral"]', 'tour_subdealer_step_referral', 'tour_cat_network'),
            $this->overviewStep('[data-tour="header-actions"]', 'tour_subdealer_step_header', 'tour_cat_controls'),
            ...$this->topBarSteps(),
            $sidebarStep,
            $moneyStep,
            ...$prescriptionSteps,
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
                ],
            ],
            'security' => [
                'title' => __('portal.tour_profile_security_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="security-link"]', 'title' => __('portal.tour_profile_security_title'), 'body' => __('portal.tour_profile_security_body'), 'tip' => __('portal.tour_profile_security_tip')],
                ],
            ],
            'catalog' => [
                'title' => __('portal.tour_catalog_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="catalog-grid"]', 'title' => __('portal.tour_catalog_grid_title'), 'body' => __('portal.tour_catalog_grid_body'), 'tip' => __('portal.tour_catalog_grid_tip')],
                ],
            ],
            'purchase' => [
                'title' => __('portal.tour_catalog_cart_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="purchase-matrix"]', 'title' => __('portal.tour_catalog_cart_title'), 'body' => __('portal.tour_catalog_cart_body'), 'tip' => __('portal.tour_catalog_cart_tip')],
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
            'my-money' => [
                'title' => __('portal.tour_earnings_title'),
                'requires' => ['orders'],
                'steps' => [
                    ['target' => '[data-tour="wallet-balances"]', 'title' => __('portal.tour_earnings_balance_title'), 'body' => __('portal.tour_earnings_balance_body'), 'tip' => __('portal.tour_earnings_balance_tip')],
                    ['target' => '[data-tour="commission-ledger"]', 'title' => __('portal.tour_earnings_ledger_title'), 'body' => __('portal.tour_earnings_ledger_body'), 'tip' => __('portal.tour_earnings_ledger_tip')],
                    ['target' => '[data-tour="withdrawal-form"]', 'title' => __('portal.tour_earnings_withdraw_title'), 'body' => __('portal.tour_earnings_withdraw_body'), 'tip' => __('portal.tour_earnings_withdraw_tip')],
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

        return $defs;
    }

    /**
     * @return array<string, array{title: string, requires?: list<string>, steps: list<array{target: string, title: string, body: string, tip?: string, category?: string, location?: string, action?: string}>}>
     */
    private function staffDefinitions(User $user): array
    {
        return [
            'overview' => [
                'title' => __('portal.tour_staff_overview_title'),
                'steps' => $this->officeOverviewSteps('tour_staff_step', $user),
            ],
            'profile' => [
                'title' => __('portal.tour_profile_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="profile-panel"]', 'title' => __('portal.tour_staff_profile_panel_title'), 'body' => __('portal.tour_staff_profile_panel_body')],
                ],
            ],
            'security' => [
                'title' => __('portal.tour_profile_security_title'),
                'requires' => ['overview'],
                'steps' => [
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
            'payouts-hub' => [
                'title' => __('portal.tour_staff_payouts_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="payouts-hub"]', 'title' => __('portal.tour_staff_payouts_hub_title'), 'body' => __('portal.tour_staff_payouts_hub_body'), 'tip' => __('portal.tour_staff_payouts_hub_tip')],
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
    private function ownerDefinitions(User $user): array
    {
        $prescriptionSteps = PrescriptionAccess::canPrescribe($user)
            ? [$this->overviewStep('[data-tour="sidebar-nav"], .workspace-menu-button', 'tour_owner_rx', 'tour_cat_domains', 'manual')]
            : [];

        return [
            ...$this->staffDefinitions($user),
            'overview' => [
                'title' => __('portal.tour_owner_overview_title'),
                'steps' => [...$this->officeOverviewSteps('tour_owner_step', $user), ...$prescriptionSteps],
            ],
            'treasury' => [
                'title' => __('portal.tour_owner_treasury_title'),
                'requires' => ['overview'],
                'steps' => [
                    ['target' => '[data-tour="treasury-panel"]', 'title' => __('portal.tour_owner_treasury_panel_title'), 'body' => __('portal.tour_owner_treasury_panel_body'), 'tip' => __('portal.tour_owner_treasury_panel_tip')],
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

    /**
     * Dashboard tour steps shared by the owner (sadmin) and office staff (manage) homes.
     * A target may list a phone fallback: the side menu is hidden on phones, so the
     * bottom section tabs and the account menu are shown there instead.
     *
     * @return list<array{target: string, title: string, body: string, tip: string, category: string, location: string, action: string}>
     */
    private function officeOverviewSteps(string $prefix, User $user): array
    {
        $sideMenu = '[data-tour="sidebar-nav"], .workspace-menu-button';

        return [
            $this->overviewStep('[data-tour="home-metrics"]', $prefix.'_stats', 'tour_cat_metrics', 'manual'),
            $this->overviewStep('[data-tour="admin-work-queue"]', $prefix.'_work', 'tour_cat_work'),
            $this->overviewStep('[data-tour="admin-sales-trend"]', 'tour_office_trend', 'tour_cat_chart', 'manual'),
            $this->overviewStep($sideMenu, $prefix.'_domains', 'tour_cat_domains', 'manual'),
            ...($user->allows('orders') || $user->allows('finance')
                ? [$this->overviewStep($sideMenu, 'tour_office_courier', 'tour_cat_orders', 'manual')]
                : []),
            ...(TreasuryAccess::mayOpenFunds($user)
                ? [$this->overviewStep($sideMenu, 'tour_office_treasury', 'tour_cat_domains', 'manual')]
                : []),
            ...($user->owner() || $user->allows('finance') || $user->allows('accounts') || $user->allows('audit')
                ? [$this->overviewStep($sideMenu, 'tour_office_settings', 'tour_cat_domains', 'manual')]
                : []),
            $this->overviewStep('[data-tour="header-actions"]', $prefix.'_header', 'tour_cat_controls'),
            $this->overviewStep('.global-search-trigger', 'tour_office_search', 'tour_cat_controls', 'manual'),
            ...$this->topBarSteps(),
            // The switcher is drawn only when the person has a branch; the tour skips it otherwise.
            $this->overviewStep('.branch-workspace-select', 'tour_branch', 'tour_cat_controls', 'manual'),
            $this->overviewStep('.tour-guide-launcher, .profile-menu-trigger', $prefix.'_help', 'tour_cat_help'),
        ];
    }

    /**
     * The bell and the globe. Every audience has them, at every screen width.
     *
     * @return list<array{target: string, title: string, body: string, tip: string, category: string, location: string, action: string}>
     */
    private function topBarSteps(): array
    {
        return [
            $this->overviewStep('.notice-bell', 'tour_bell', 'tour_cat_controls', 'manual'),
            $this->overviewStep('.portal-website-link', 'tour_website', 'tour_cat_controls', 'manual'),
        ];
    }

    /**
     * One dashboard tour step read from the `{namespace}.{prefix}_title|_loc|_body|_action|_tip` keys.
     *
     * @return array{target: string, title: string, body: string, tip: string, category: string, location: string, action: string}
     */
    private function overviewStep(string $target, string $prefix, string $category, string $namespace = 'portal'): array
    {
        return [
            'target' => $target,
            'category' => __('portal.'.$category),
            'location' => __($namespace.'.'.$prefix.'_loc'),
            'title' => __($namespace.'.'.$prefix.'_title'),
            'body' => __($namespace.'.'.$prefix.'_body'),
            'action' => __($namespace.'.'.$prefix.'_action'),
            'tip' => __($namespace.'.'.$prefix.'_tip'),
        ];
    }
}
