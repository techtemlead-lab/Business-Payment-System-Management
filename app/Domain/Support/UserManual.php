<?php

namespace App\Domain\Support;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Finance\Percentage;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\Money;

final class UserManual
{
    /** @var array<string, string>|null Rates and targets from the live commission rules, worked out once. */
    private ?array $ruleValues = null;

    /** False leaves ":rate"-style placeholders in the text for the browser to fill in. */
    private bool $fillRuleValues = true;

    public function __construct(private CommissionPolicy $policy) {}

    /**
     * The same manual with rate placeholders left in, so building it reads no commission
     * rules. Used for the manual sent with every portal page; see ruleValues().
     */
    public function withoutRuleValues(): static
    {
        $manual = clone $this;
        $manual->fillRuleValues = false;

        return $manual;
    }

    /**
     * @return list<array{
     *   key: string,
     *   badge: ?string,
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   rules: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
     *   faqs: list<array{q: string, a: string}>,
     *   keywords: list<string>
     * }>
     */
    public function chaptersFor(User $user, ?string $query = null): array
    {
        $audience = $this->audience($user);
        $chapters = array_values(array_filter(
            $this->chapters(),
            fn (array $chapter): bool => count(array_intersect($chapter['audience'], $audience)) > 0,
        ));

        $query = trim((string) $query);
        if ($query === '') {
            return array_map(fn (array $chapter): array => $this->present($chapter), $chapters);
        }

        $needle = mb_strtolower($query);

        return collect($chapters)
            ->filter(function (array $chapter) use ($needle): bool {
                $parts = [
                    $chapter['key'],
                    $this->line($chapter['title_key']),
                    $this->line($chapter['summary_key']),
                    $this->line($chapter['purpose_key'] ?? null),
                    $this->line($chapter['why_key'] ?? null),
                    $this->line($chapter['badge_key'] ?? null),
                    implode(' ', $chapter['keywords']),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['step_keys'] ?? [])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['outcome_keys'] ?? [])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['rule_keys'] ?? [])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['tip_keys'] ?? [])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['warning_keys'] ?? [])),
                ];

                return str_contains(mb_strtolower(implode(' ', $parts)), $needle);
            })
            ->map(fn (array $chapter): array => $this->present($chapter))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   key: string,
     *   badge: ?string,
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   rules: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
     *   faqs: list<array{q: string, a: string}>,
     *   keywords: list<string>
     * }|null
     */
    public function chapter(string $key, User $user): ?array
    {
        foreach ($this->chaptersFor($user) as $chapter) {
            if ($chapter['key'] === $key) {
                return $chapter;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function audience(User $user): array
    {
        if ($user->owner() || in_array($user->role, ['super_admin', 'owner', 'md', 'chairman'], true)) {
            return ['owner', 'staff', 'dealer', 'sub_dealer', 'partner'];
        }
        if ($user->staff() || in_array($user->role, ['staff', 'employee', 'admin'], true) || $user->usingStaffWorkspace()) {
            return ['staff', 'dealer', 'sub_dealer', 'partner'];
        }
        if ($user->role === 'sub_dealer') {
            return ['sub_dealer', 'partner'];
        }

        return ['dealer', 'partner'];
    }

    /**
     * @param  array<string, mixed>  $chapter
     * @return array{
     *   key: string,
     *   badge: ?string,
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   rules: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
     *   faqs: list<array{q: string, a: string}>,
     *   keywords: list<string>
     * }
     */
    private function present(array $chapter): array
    {
        return [
            'key' => $chapter['key'],
            'badge' => $this->optionalLine($chapter['badge_key'] ?? null),
            'title' => $this->line($chapter['title_key']),
            'summary' => $this->line($chapter['summary_key']),
            'purpose' => $this->optionalLine($chapter['purpose_key'] ?? null),
            'why' => $this->optionalLine($chapter['why_key'] ?? null),
            'steps' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['step_keys'] ?? []))),
            'outcomes' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['outcome_keys'] ?? []))),
            'rules' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['rule_keys'] ?? []))),
            'tips' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['tip_keys'] ?? []))),
            'warnings' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['warning_keys'] ?? []))),
            'faqs' => array_values(array_filter(array_map(function (array $faq): array {
                return [
                    'q' => $this->line($faq['q_key']),
                    'a' => $this->line($faq['a_key']),
                ];
            }, $chapter['faq_keys'] ?? []))),
            'keywords' => $chapter['keywords'] ?? [],
        ];
    }

    private function line(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $manual = __('manual.'.$key);
        if (is_string($manual) && $manual !== 'manual.'.$key) {
            // Only lines that quote a rate or target pay for reading the live rules.
            return $this->fillRuleValues && preg_match('/:[a-z]/', $manual) === 1
                ? (string) __('manual.'.$key, $this->ruleValues())
                : $manual;
        }

        $portal = __('portal.'.$key);
        if (is_string($portal) && $portal !== 'portal.'.$key) {
            return $portal;
        }

        return '';
    }

    /**
     * The rates and targets the manual quotes, read from the live commission rules so the
     * manual always says what the system really pays. A rule that is switched off shows 0%.
     * The portal shell receives these separately (a deferred prop) and fills them in itself.
     *
     * @return array<string, string>
     */
    public function ruleValues(): array
    {
        return $this->ruleValues ??= $this->readRuleValues();
    }

    /** @return array<string, string> */
    private function readRuleValues(): array
    {
        $rules = $this->policy->snapshot(false)['rules'];
        $percent = fn (int $basisPoints): string => LocalDigits::text(rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.'));
        $rate = fn (string $key): int => CommissionPolicy::rate($rules, $key)->basisPoints;
        $share = fn (string $key, string $parameter): int => $rate($key) > 0 ? Percentage::from((string) $rules[$key]['parameters'][$parameter])->basisPoints : 0;
        $taka = fn (string $amount): string => '৳'.LocalDigits::text(preg_replace('/\.00$/', '', Money::grouped($amount)));
        $dealerFund = array_sum(array_map($rate, CommissionPolicy::Keys));
        $subDealerFund = $dealerFund + $rate('additional_rank') + $rate('sub_dealer_salary') + $rate('sub_dealer_tour');
        $royalty = $rules['rank_royalty']['parameters'];
        $keep = $rules['rank_maintenance']['parameters'];
        $awards = $rules['rank_incentive']['parameters'];
        $rewards = $rules['car_bike_incentive']['parameters'];
        $area = 'area_dealer_pool';

        return [
            'dealer_fund_rate' => $percent($dealerFund),
            'dealer_company_rate' => $percent(10000 - $dealerFund),
            'sub_fund_rate' => $percent($subDealerFund),
            'sub_company_rate' => $percent(10000 - $subDealerFund),
            'sub_salary_rate' => $percent($rate('sub_dealer_salary')),
            'sub_tour_rate' => $percent($rate('sub_dealer_tour')),
            'cashback_rate' => $percent($rate('own_dealer_cashback')),
            'incentive_rate' => $percent($rate('rank_incentive')),
            'marketing_rate' => $percent($rate('company_marketing')),
            'vehicle_rate' => $percent($rate('car_bike_incentive')),
            'referral_rate' => $percent($rate('direct_referral')),
            'gen2_rate' => $percent($share('d2d_multitier', 'gen2_pct')),
            'gen3_rate' => $percent($share('d2d_multitier', 'gen3_pct')),
            'area_rate' => $percent($rate($area)),
            'area_management' => $percent($share($area, 'management_share_pct')),
            'area_division' => $percent($share($area, 'division_share_pct')),
            'area_district' => $percent($share($area, 'district_share_pct')),
            'area_upazila' => $percent($share($area, 'upazila_share_pct')),
            'area_union' => $percent($share($area, 'union_share_pct')),
            'royalty_rate' => $percent($rate('rank_royalty')),
            'mo_target' => $taka((string) $royalty['mo_target']),
            'am_count' => LocalDigits::text((int) $royalty['am_count']),
            'sm_count' => LocalDigits::text((int) $royalty['sm_count']),
            'me_count' => LocalDigits::text((int) $royalty['me_count']),
            'rank_depth' => LocalDigits::text((int) $royalty['qualification_depth']),
            'mo_bonus' => $taka((string) $awards['mo_bonus']),
            'am_bonus' => $taka((string) $awards['am_bonus']),
            'sm_bonus' => $taka((string) $awards['sm_bonus']),
            'me_bonus' => $taka((string) $awards['me_bonus']),
            'mo_keep' => $taka((string) $keep['mo_min_monthly_volume']),
            'am_keep' => $taka((string) $keep['am_min_monthly_volume']),
            'sm_keep' => $taka((string) $keep['sm_min_monthly_volume']),
            'me_keep' => $taka((string) $keep['me_min_monthly_volume']),
            'grace_days' => LocalDigits::text((int) $keep['grace_period_days']),
            'dealer_salary_rate' => $percent($rate('dealer_monthly_salary')),
            'dealer_salary_min' => $taka((string) $rules['dealer_monthly_salary']['parameters']['min_monthly_volume']),
            'bike_target' => $taka((string) $rewards['bike_target']),
            'bike_cap' => $taka((string) $rewards['bike_leg_cap']),
            'car_target' => $taka((string) $rewards['car_target']),
            'car_cap' => $taka((string) $rewards['car_leg_cap']),
        ];
    }

    private function optionalLine(?string $key): ?string
    {
        $val = $this->line($key);

        return $val !== '' ? $val : null;
    }

    /**
     * The user manual chapters, each shown only to the roles listed with it.
     *
     * @return list<array<string, mixed>>
     */
    private function chapters(): array
    {
        return [
            $this->ch('getting_started', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['start', 'login', 'dashboard', 'overview', 'portal', 'শুরু', 'লগইন', 'ড্যাশবোর্ড', 'অনবোর্ডিং']),
            $this->ch('navigation_workspace', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['navigation', 'sidebar', 'language', 'theme', 'day', 'night', 'module', 'নেভিগেশন', 'ভাষা', 'থিম']),
            $this->ch('profile_security', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['profile', 'password', 'security', '2fa', 'totp', 'session', 'id_card', 'প্রোফাইল', 'পাসওয়ার্ড', 'নিরাপত্তা', 'কেওয়াইসি']),
            $this->ch('notifications_circulars', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['notification', 'circular', 'notice', 'alert', 'নোটিশ', 'সার্কুলার', 'ঘোষণা']),
            $this->ch('catalog_orders', ['partner', 'dealer', 'sub_dealer'], 12, 3, 3, 2, ['order', 'catalog', 'purchase', 'cart', 'invoice', 'mrp', 'payment', 'অর্ডার', 'ক্যাটালগ', 'ইনভয়েস', 'পাইকারি']),
            $this->ch('deliveries_dues', ['partner', 'dealer', 'sub_dealer'], 9, 3, 2, 2, ['delivery', 'due', 'dispatch', 'courier', 'claim', 'ডেলিভারি', 'বাকি', 'চালান', 'কুরিয়ার']),
            $this->ch('commissions_pool', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 11, 3, 3, 2, ['commission', 'earnings', 'ledger', '40%', '60%', 'wallet', 'কমিশন', 'আয়', 'লেজার', 'ওয়ালেট']),
            $this->ch('withdrawals', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['withdrawal', 'payout', 'bkash', 'nagad', 'bank', 'rocket', 'উত্তোলন', 'ওয়ালেট', 'পেআউট']),
            $this->ch('payment_receipts', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['receipt', 'payment-receipts', 'payout', 'print', 'cash', 'রসিদ', 'প্রিন্ট', 'পেমেন্ট']),
            $this->ch('reports', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['report', 'pdf', 'full_report', 'analytics', 'রিপোর্ট', 'অডিট', 'বিবরণী']),
            $this->ch('network_genealogy', ['dealer', 'staff', 'owner'], 10, 3, 3, 2, ['network', 'referral', 'tree', 'genealogy', 'downline', 'qr', 'নেটওয়ার্ক', 'রেফারাল', 'ডাউনলাইন', 'টিম']),
            $this->ch('area_dealers_places', ['dealer', 'staff', 'owner'], 8, 2, 2, 2, ['place_code', 'area_dealer', 'territory', 'division', 'district', 'প্লেস কোড', 'এরিয়া', 'টেরিটোরি']),
            $this->ch('ranks_royalties', ['dealer', 'staff', 'owner'], 9, 3, 3, 2, ['rank', 'royalty', 'mo', 'am', 'sm', 'me', 'পদবী', 'রয়্যালটি', 'সম্মাননা']),
            $this->ch('rewards_milestones', ['dealer', 'sub_dealer', 'staff', 'owner'], 7, 2, 2, 1, ['reward', 'bike', 'car', 'milestone', 'পুরস্কার', 'মাইলফলক', 'গাড়ি', 'বাইক']),
            $this->ch('subdealer_salary_tour', ['sub_dealer', 'dealer', 'staff', 'owner'], 9, 3, 2, 2, ['subdealer', 'salary', 'tour', 'tour fund', 'reserve', 'বেতন', 'সাব-ডিলার', 'ট্যুর', 'ট্যুর ফান্ড']),
            $this->ch('staff_people_accounts', ['staff', 'owner'], 11, 3, 3, 2, ['employees', 'accounts', 'onboarding', 'permissions', 'partner', 'স্টাফ', 'অ্যাকাউন্ট', 'পারমিশন']),
            $this->ch('staff_fulfillment', ['staff', 'owner'], 10, 3, 2, 2, ['orders', 'approve', 'dispatch', 'deliveries', 'claims', 'অনুমোদন', 'ডিসপ্যাচ', 'ফুলফিলমেন্ট']),
            $this->ch('inventory_branches', ['staff', 'owner'], 11, 3, 3, 2, ['inventory', 'stock', 'branch', 'warehouse', 'hub', 'স্টক', 'ব্রাঞ্চ', 'গুদাম']),
            $this->ch('branch_performance', ['staff', 'owner'], 9, 3, 2, 1, ['branch-performance', 'profit', 'income', 'today', 'month', 'প্রফিট', 'ইনকাম', 'ব্রাঞ্চ']),
            $this->ch('place_codes_admin', ['staff', 'owner'], 8, 2, 2, 2, ['place-codes', 'geography', 'bangladesh', 'union', 'pourashava', 'upazila', 'ভূগোল', 'ইউনিয়ন', 'পৌরসভা', 'উপজেলা']),
            $this->ch('finance_treasury', ['staff', 'owner'], 10, 3, 3, 2, ['treasury', 'capital', '60%', 'retained', 'collection', 'ট্রেজারি', 'মূলধন', 'ক্যাশ']),
            $this->ch('finance_policies_simulator', ['staff', 'owner'], 9, 3, 2, 2, ['policy', 'commission', 'simulator', 'preview', 'rate', 'পলিসি', 'সিমুলেটর', 'স্যান্ডবক্স']),
            $this->ch('payouts_settlements', ['staff', 'owner'], 10, 3, 3, 2, ['payouts-hub', 'settlement', 'salary', 'rewards', 'পেআউট', 'সেটেলমেন্ট', 'নিষ্পত্তি']),
            $this->ch('ledger_audit_reports', ['staff', 'owner'], 9, 3, 2, 2, ['ledger', 'audit', 'reports', 'integrity', 'লেজার', 'অডিট', 'আইডিপোটেন্সি']),
            $this->ch('website_customization', ['staff', 'owner'], 9, 2, 2, 1, ['website', 'cms', 'banner', 'enquiries', 'partnerships', 'ওয়েবসাইট', 'সিএমএস', 'ব্যানার']),
            $this->ch('owner_benefits_fund', ['owner'], 8, 3, 2, 2, ['benefits-fund', 'owner', 'equity', 'confidential', 'ওনার ফান্ড', 'লভ্যাংশ', 'এক্সিকিউটিভ']),
            $this->ch('dual_dashboard', ['dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['dual', 'switch', 'staff', 'partner', 'ড্যাশবোর্ড সুইচ', 'ডুয়াল']),
            $this->ch('financial_activation_gates', ['staff', 'owner'], 8, 3, 3, 3, ['activation', 'preview', 'opening_balance', 'readiness', 'আর্থিক সক্রিয়করণ', 'গো-লাইভ']),
            $this->ch('support', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['support', 'ticket', 'help', 'manual', 'সাপোর্ট', 'টিকেট', 'হেল্প']),
            $this->ch('money_rules', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 2, 2, 1, ['cashback', 'month', 'close', 'target', 'profit', 'carry', 'ক্যাশব্যাক', 'মাস', 'টার্গেট', 'লাভ']),
            $this->ch('my_progress', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 7, 2, 2, 1, ['progress', 'target', 'popup', 'salary', 'rank', 'অগ্রগতি', 'টার্গেট', 'বেতন', 'র‍্যাঙ্ক']),
            $this->ch('office_chat', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['chat', 'message', 'answer', 'staff', 'চ্যাট', 'মেসেজ', 'অফিস']),
            $this->ch('data_cleanup', ['owner'], 5, 2, 2, 1, ['cleanup', 'delete', 'sms', 'login code', 'enquiry', 'মুছুন', 'পুরোনো', 'এসএমএস']),
            $this->ch('courier_ordering', ['partner', 'dealer', 'sub_dealer'], 7, 2, 2, 1, ['courier', 'company decides', 'tracking', 'parcel', 'কুরিয়ার', 'ট্র্যাকিং', 'পার্সেল']),
            $this->ch('courier_staff', ['staff', 'owner'], 8, 2, 2, 1, ['courier', 'booking', 'automatic', 'change courier', 'dispatch', 'কুরিয়ার', 'বুকিং', 'অনুমোদন']),
            $this->ch('deliveries_board', ['staff', 'owner'], 6, 2, 1, 1, ['deliveries', 'board', 'failed booking', 'parcel', 'ডেলিভারি', 'বুকিং', 'পার্সেল']),
            $this->ch('notification_panel', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 6, 1, 1, 1, ['bell', 'notification', 'notice', 'unread', 'ঘণ্টা', 'নোটিশ']),
            $this->ch('branch_switcher', ['staff', 'owner'], 6, 1, 1, 1, ['branch', 'switcher', 'top bar', 'all branches', 'ব্রাঞ্চ', 'সব ব্রাঞ্চ']),
            $this->ch('website_button', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 4, 1, 1, 1, ['website', 'globe', 'visit', 'ওয়েবসাইট', 'গ্লোব']),
            $this->ch('prescriptions_partner', ['dealer', 'sub_dealer'], 7, 2, 2, 1, ['prescription', 'patient', 'medicine', 'registration', 'প্রেসক্রিপশন', 'রোগী', 'ওষুধ', 'রেজিস্ট্রেশন']),
            $this->ch('prescriptions_verify', ['staff', 'owner'], 6, 1, 1, 1, ['prescription', 'verify', 'registration', 'bmdc', 'প্রেসক্রিপশন', 'যাচাই', 'রেজিস্ট্রেশন']),
            $this->ch('clinic_prescriptions', ['owner'], 7, 3, 2, 1, ['clinic', 'prescription', 'dispense', 'manufacturer', 'mrp', 'চেম্বার', 'প্রেসক্রিপশন', 'তহবিল', 'এমআরপি']),
            $this->ch('menu_office', ['staff', 'owner'], 8, 1, 1, 1, ['menu', 'moved', 'sidebar', 'new names', 'where', 'মেনু', 'নতুন নাম', 'কোথায়']),
            $this->ch('menu_partner', ['dealer', 'sub_dealer'], 8, 1, 1, 1, ['menu', 'moved', 'sidebar', 'where', 'new order', 'my money', 'মেনু', 'আমার টাকা', 'কোথায়']),
            $this->ch('commission_tabs', ['staff', 'owner'], 5, 1, 1, 1, ['commission', 'tabs', 'rules', 'rates', 'calculator', 'special rate', 'কমিশন', 'ট্যাব', 'হার', 'ক্যালকুলেটর']),
            $this->ch('report_centre', ['staff', 'owner'], 4, 1, 1, 1, ['report', 'report centre', 'download', 'excel', 'রিপোর্ট', 'রিপোর্ট সেন্টার', 'ডাউনলোড']),
            $this->ch('my_money_tabs', ['dealer', 'sub_dealer'], 4, 1, 1, 1, ['my money', 'tabs', 'earnings', 'withdraw', 'receipts', 'আমার টাকা', 'ট্যাব', 'আয়', 'টাকা তোলা', 'রসিদ']),
            $this->ch('support_one_form', ['dealer', 'sub_dealer'], 4, 1, 1, 1, ['support', 'help', 'form', 'request', 'nid correction', 'সাপোর্ট', 'সাহায্য', 'ফর্ম', 'অনুরোধ']),
            $this->ch('website_editor', ['staff', 'owner'], 5, 1, 1, 1, ['website', 'editor', 'sections', 'search', 'save', 'videos', 'ওয়েবসাইট', 'খুঁজুন', 'সেভ', 'ভিডিও']),
            $this->ch('stock_batches', ['staff', 'owner'], 6, 1, 1, 1, ['batch', 'expiry', 'near expiry', 'expired', 'stock', 'ব্যাচ', 'মেয়াদ', 'স্টক']),
            $this->ch('stock_recall_transfers', ['staff', 'owner'], 5, 1, 1, 1, ['recall', 'transfer', 'reorder', 'branch', 'রিকল', 'ট্রান্সফার', 'রিঅর্ডার', 'ব্রাঞ্চ']),
            $this->ch('purchasing_goods', ['staff', 'owner'], 6, 1, 1, 1, ['purchasing', 'supplier', 'purchase order', 'goods received', 'ক্রয়', 'সাপ্লায়ার', 'মাল গ্রহণ']),
            $this->ch('stock_counting', ['staff', 'owner'], 5, 1, 1, 1, ['stock count', 'count', 'difference', 'overdue', 'স্টক গণনা', 'গণনা', 'গরমিল']),
            $this->ch('parcel_received_back', ['staff', 'owner'], 5, 1, 1, 1, ['returned', 'parcel', 'received back', 'courier', 'ফেরত', 'পার্সেল', 'পার্সেল ফেরত পেয়েছি', 'কুরিয়ার']),
            $this->ch('mixed_delivery_decision', ['staff', 'owner'], 4, 1, 1, 1, ['mixed', 'decision', 'delivered', 'returned', 'needs staff decision', 'সিদ্ধান্ত', 'ফেরত', 'ডেলিভারি']),
            $this->ch('manual_couriers', ['staff', 'owner'], 5, 1, 1, 1, ['courier', 'manual', 'sundarban', 'sa paribahan', 'karatoa', 'janani', 'continental', 'consignment', 'কুরিয়ার', 'সুন্দরবন', 'করতোয়া', 'জননী', 'কনসাইনমেন্ট']),
            $this->ch('global_search', ['staff', 'owner'], 3, 1, 1, 1, ['search', 'ctrl k', 'find', 'order number', 'sku', 'খুঁজুন', 'অর্ডার নম্বর']),
            $this->ch('company_funds_heads', ['staff', 'owner'], 7, 1, 1, 1, ['company funds', 'expense head', 'spend', 'tour fund', 'report', 'কোম্পানির ফান্ড', 'খরচের খাত', 'ট্যুর ফান্ড']),
            $this->ch('accounting_statements', ['staff', 'owner'], 5, 1, 1, 1, ['accounting', 'trial balance', 'profit and loss', 'balance sheet', 'journal', 'হিসাব', 'ট্রায়াল ব্যালেন্স', 'লাভ-ক্ষতি', 'ব্যালেন্স শিট', 'জার্নাল']),
            $this->ch('statement_matching', ['staff', 'owner'], 6, 1, 1, 1, ['statement', 'bank', 'bkash', 'nagad', 'match', 'csv', 'স্টেটমেন্ট', 'ব্যাংক', 'বিকাশ', 'নগদ', 'মিলানো']),
            $this->ch('payout_file', ['staff', 'owner'], 5, 1, 1, 1, ['payout file', 'csv', 'month end', 'bank', 'bkash', 'withdrawals', 'পেমেন্ট ফাইল', 'মাস শেষ', 'ব্যাংক', 'বিকাশ']),
            $this->ch('repeat_order', ['dealer', 'sub_dealer'], 4, 1, 1, 1, ['repeat', 'order again', 'cart', 'last order', 'আবার অর্ডার', 'কার্ট', 'শেষ অর্ডার']),
            $this->ch('alerts_next_steps', ['dealer', 'sub_dealer'], 4, 1, 1, 1, ['alerts', 'next steps', 'attention', 'target', 'inactive', 'নজর', 'পরের কাজ', 'টার্গেট', 'নিষ্ক্রিয়']),
            $this->ch('sales_kit_partner', ['dealer', 'sub_dealer'], 3, 1, 1, 1, ['sales kit', 'poster', 'price list', 'download', 'সেলস কিট', 'পোস্টার', 'দামের তালিকা']),
            $this->ch('sales_kit_schemes_owner', ['owner'], 4, 1, 1, 1, ['sales kit', 'schemes', 'add file', 'scheme', 'সেলস কিট', 'স্কিম']),
            $this->ch('customer_book', ['dealer', 'sub_dealer'], 4, 1, 1, 1, ['customer', 'book', 'follow-up', 'private', 'কাস্টমার', 'খাতা', 'ফলো-আপ']),
            $this->ch('schemes_partner', ['dealer', 'sub_dealer'], 3, 1, 1, 1, ['scheme', 'offer', 'free goods', 'discount', 'স্কিম', 'অফার', 'ফ্রি', 'ছাড়']),
            $this->ch('product_facts', ['staff', 'owner'], 4, 1, 1, 1, ['product facts', 'concern', 'tags', 'manufacturer', 'registration', 'পণ্যের তথ্য', 'ট্যাগ', 'স্বাস্থ্য বিষয়']),
            $this->ch('product_reviews', ['staff', 'owner'], 3, 1, 1, 1, ['review', 'reviews', 'customer reviews', 'approve', 'মতামত', 'রিভিউ', 'অনুমোদন']),
            $this->ch('trust_pages', ['staff', 'owner'], 6, 1, 1, 1, ['trust', 'policy', 'grievance', 'complaint', 'licence', 'articles', 'বিশ্বাসযোগ্যতা', 'পলিসি', 'অভিযোগ', 'লাইসেন্স']),
            $this->ch('verify_dealer', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 3, 1, 1, 1, ['verify', 'dealer code', 'qr', 'fake', 'id card', 'যাচাই', 'ডিলার কোড', 'ভুয়া']),
            $this->ch('practitioner_directory', ['dealer', 'sub_dealer', 'staff', 'owner'], 5, 1, 1, 1, ['practitioner', 'directory', 'consultation', 'public listing', 'request', 'চিকিৎসক', 'পরামর্শ', 'তালিকা', 'অনুরোধ']),
            $this->ch('patient_records', ['dealer', 'sub_dealer', 'staff', 'owner'], 5, 1, 1, 1, ['patient', 'consent', 'allergy', 'allergies', 'history', 'record', 'রোগী', 'সম্মতি', 'অ্যালার্জি', 'খাতা']),
            $this->ch('prescription_templates_followups', ['dealer', 'sub_dealer', 'staff', 'owner'], 6, 1, 1, 1, ['template', 'follow-up', 'follow up', 'again', 'টেমপ্লেট', 'ফলো-আপ', 'আবার দেখানো']),
            $this->ch('contests_guide', ['dealer', 'sub_dealer', 'owner'], 4, 1, 1, 1, ['contest', 'standings', 'prize', 'competition', 'প্রতিযোগিতা', 'অবস্থান', 'পুরস্কার']),
            $this->ch('partner_scorecard', ['staff', 'owner'], 3, 1, 1, 1, ['scorecard', 'partner', 'returned', 'cancelled', 'payments', 'স্কোরকার্ড', 'পার্টনার', 'ফেরত']),
            $this->ch('duplicate_account_check', ['staff', 'owner'], 3, 1, 1, 1, ['duplicate', 'same person', 'nid', 'phone', 'identity', 'ডুপ্লিকেট', 'একই ব্যক্তি', 'এনআইডি']),
            $this->ch('device_list', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 4, 1, 1, 1, ['device', 'session', 'log out', 'security', 'phone', 'ডিভাইস', 'লগআউট', 'সিকিউরিটি']),
            $this->ch('saved_filters', ['staff', 'owner'], 3, 1, 1, 1, ['saved filter', 'filter', 'columns', 'list', 'সেভ করা ফিল্টার', 'ফিল্টার', 'কলাম']),
            $this->ch('batches_on_hold', ['staff', 'owner'], 4, 1, 1, 1, ['hold', 'batch', 'quarantine', 'release', 'write off', 'হোল্ড', 'ব্যাচ', 'আটকে', 'রিলিজ']),
            $this->ch('prescription_dosing', ['dealer', 'sub_dealer', 'staff', 'owner'], 5, 1, 1, 1, ['dose', 'dosing', '1+0+1', 'morning', 'noon', 'night', 'food', 'মাত্রা', 'সকাল', 'দুপুর', 'রাত', 'খাবার']),
        ];
    }

    /**
     * @param  list<string>  $audience
     * @param  list<string>  $keywords
     * @return array<string, mixed>
     */
    private function ch(string $key, array $audience, int $steps, int $outcomes, int $tips, int $warnings, array $keywords): array
    {
        return [
            'key' => $key,
            'audience' => $audience,
            'badge_key' => $key.'_badge',
            'title_key' => $key.'_title',
            'summary_key' => $key.'_summary',
            'purpose_key' => $key.'_purpose',
            'why_key' => $key.'_why',
            'step_keys' => array_map(fn (int $i): string => $key.'_s'.$i, range(1, $steps)),
            'outcome_keys' => array_map(fn (int $i): string => $key.'_o'.$i, range(1, $outcomes)),
            'tip_keys' => array_map(fn (int $i): string => $key.'_t'.$i, range(1, $tips)),
            'warning_keys' => array_map(fn (int $i): string => $key.'_w'.$i, range(1, $warnings)),
            'keywords' => $keywords,
        ];
    }
}
