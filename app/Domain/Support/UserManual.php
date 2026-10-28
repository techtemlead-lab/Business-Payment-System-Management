<?php

namespace App\Domain\Support;

use App\Models\User;

final class UserManual
{
    /**
     * @return list<array{
     *   key: string,
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
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
                    implode(' ', $chapter['keywords']),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['step_keys'])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['outcome_keys'] ?? [])),
                    implode(' ', array_map(fn (string $key): string => $this->line($key), $chapter['tip_keys'])),
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
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
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
        if ($user->owner()) {
            return ['owner', 'staff', 'dealer', 'sub_dealer', 'partner'];
        }
        if ($user->staff() || $user->usingStaffWorkspace()) {
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
     *   title: string,
     *   summary: string,
     *   purpose: ?string,
     *   why: ?string,
     *   steps: list<string>,
     *   outcomes: list<string>,
     *   tips: list<string>,
     *   warnings: list<string>,
     *   keywords: list<string>
     * }
     */
    private function present(array $chapter): array
    {
        return [
            'key' => $chapter['key'],
            'title' => $this->line($chapter['title_key']),
            'summary' => $this->line($chapter['summary_key']),
            'purpose' => $this->optionalLine($chapter['purpose_key'] ?? null),
            'why' => $this->optionalLine($chapter['why_key'] ?? null),
            'steps' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['step_keys']))),
            'outcomes' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['outcome_keys'] ?? []))),
            'tips' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['tip_keys']))),
            'warnings' => array_values(array_filter(array_map(fn (string $key): string => $this->line($key), $chapter['warning_keys'] ?? []))),
            'keywords' => $chapter['keywords'],
        ];
    }

    private function line(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $manual = __('manual.'.$key);
        if (is_string($manual) && $manual !== 'manual.'.$key) {
            return $manual;
        }

        // Backward-compatible fallback while older portal keys remain.
        return (string) __('portal.'.$key);
    }

    private function optionalLine(?string $key): ?string
    {
        $value = $this->line($key);

        return $value === '' ? null : $value;
    }

    /**
     * Enterprise product manual catalog. Content lives in lang/{locale}/manual.php.
     * Live TourGuide definitions are separate and must not be edited here.
     *
     * @return list<array<string, mixed>>
     */
    private function chapters(): array
    {
        return [
            $this->ch('getting_started', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['start', 'login', 'dashboard', 'overview', 'portal', 'শুরু', 'লগইন', 'ড্যাশবোর্ড']),
            $this->ch('navigation_workspace', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['navigation', 'sidebar', 'language', 'theme', 'day', 'night', 'module', 'নেভিগেশন', 'ভাষা', 'থিম']),
            $this->ch('profile_security', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['profile', 'password', 'security', '2fa', 'totp', 'session', 'id_card', 'প্রোফাইল', 'পাসওয়ার্ড', 'নিরাপত্তা']),
            $this->ch('notifications_circulars', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['notification', 'circular', 'notice', 'alert', 'নোটিশ', 'সার্কুলার']),
            $this->ch('catalog_orders', ['partner', 'dealer', 'sub_dealer'], 12, 3, 3, 2, ['order', 'catalog', 'purchase', 'cart', 'invoice', 'mrp', 'payment', 'অর্ডার', 'ক্যাটালগ', 'ইনভয়েস']),
            $this->ch('deliveries_dues', ['partner', 'dealer', 'sub_dealer'], 9, 3, 2, 2, ['delivery', 'due', 'dispatch', 'courier', 'claim', 'ডেলিভারি', 'বাকি', 'চালান']),
            $this->ch('commissions_pool', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 11, 3, 3, 2, ['commission', 'earnings', 'ledger', '40%', '60%', 'wallet', 'কমিশন', 'আয়', 'লেজার']),
            $this->ch('withdrawals', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 10, 3, 3, 2, ['withdrawal', 'payout', 'bkash', 'nagad', 'bank', 'rocket', 'উত্তোলন', 'ওয়ালেট']),
            $this->ch('reports', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['report', 'pdf', 'full_report', 'analytics', 'রিপোর্ট']),
            $this->ch('network_genealogy', ['dealer', 'staff', 'owner'], 10, 3, 3, 2, ['network', 'referral', 'tree', 'genealogy', 'downline', 'qr', 'নেটওয়ার্ক', 'রেফারাল']),
            $this->ch('area_dealers_places', ['dealer', 'staff', 'owner'], 8, 2, 2, 2, ['place_code', 'area_dealer', 'territory', 'division', 'district', 'প্লেস কোড', 'এরিয়া']),
            $this->ch('ranks_royalties', ['dealer', 'staff', 'owner'], 9, 3, 2, 2, ['rank', 'royalty', 'mo', 'am', 'sm', 'me', 'পদবী', 'রয়্যালটি']),
            $this->ch('rewards_milestones', ['dealer', 'staff', 'owner'], 7, 2, 2, 1, ['reward', 'bike', 'car', 'milestone', 'পুরস্কার', 'মাইলফলক']),
            $this->ch('subdealer_salary_tour', ['sub_dealer', 'dealer', 'staff', 'owner'], 9, 3, 2, 2, ['subdealer', 'salary', 'tour', 'reserve', '5%', '2%', 'বেতন', 'সাব-ডিলার']),
            $this->ch('staff_people_accounts', ['staff', 'owner'], 11, 3, 3, 2, ['employees', 'accounts', 'onboarding', 'permissions', 'partner', 'স্টাফ', 'অ্যাকাউন্ট']),
            $this->ch('staff_fulfillment', ['staff', 'owner'], 10, 3, 2, 2, ['orders', 'approve', 'dispatch', 'deliveries', 'dues', 'claims', 'অনুমোদন', 'ডিসপ্যাচ']),
            $this->ch('inventory_branches', ['staff', 'owner'], 11, 3, 3, 2, ['inventory', 'stock', 'branch', 'warehouse', 'hub', 'স্টক', 'ব্রাঞ্চ']),
            $this->ch('branch_performance', ['staff', 'owner'], 9, 3, 2, 1, ['branch-performance', 'profit', 'income', 'today', 'month', 'প্রফিট', 'ইনকাম']),
            $this->ch('place_codes_admin', ['staff', 'owner'], 8, 2, 2, 2, ['place-codes', 'geography', 'bangladesh', 'union', 'upazila', 'ভূগোল']),
            $this->ch('finance_treasury', ['staff', 'owner'], 10, 3, 3, 2, ['treasury', 'capital', '60%', 'retained', 'collection', 'ট্রেজারি', 'মূলধন']),
            $this->ch('finance_policies_simulator', ['staff', 'owner'], 9, 3, 2, 2, ['policy', 'commission', 'simulator', 'preview', 'rate', 'পলিসি', 'সিমুলেটর']),
            $this->ch('payouts_settlements', ['staff', 'owner'], 10, 3, 3, 2, ['payouts-hub', 'settlement', 'salary', 'rewards', 'পেআউট', 'সেটেলমেন্ট']),
            $this->ch('ledger_audit_reports', ['staff', 'owner'], 9, 3, 2, 2, ['ledger', 'audit', 'reports', 'integrity', 'লেজার', 'অডিট']),
            $this->ch('website_customization', ['staff', 'owner'], 9, 2, 2, 1, ['website', 'cms', 'banner', 'enquiries', 'partnerships', 'ওয়েবসাইট']),
            $this->ch('owner_benefits_fund', ['owner'], 8, 3, 2, 2, ['benefits-fund', 'owner', 'equity', 'confidential', 'ওনার ফান্ড']),
            $this->ch('dual_dashboard', ['dealer', 'sub_dealer', 'staff', 'owner'], 6, 2, 2, 1, ['dual', 'switch', 'staff', 'partner', 'ড্যাশবোর্ড সুইচ']),
            $this->ch('financial_activation_gates', ['staff', 'owner'], 8, 3, 3, 3, ['activation', 'preview', 'opening_balance', 'readiness', 'আর্থিক সক্রিয়করণ']),
            $this->ch('support', ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'], 8, 2, 2, 1, ['support', 'ticket', 'help', 'manual', 'সাপোর্ট', 'টিকেট', 'হেল্প']),
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
