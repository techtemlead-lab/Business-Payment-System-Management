<?php

namespace App\Domain\Support;

use App\Models\User;

final class UserManual
{
    /**
     * @return list<array{
     *   key: string,
     *   audience: list<string>,
     *   title: string,
     *   summary: string,
     *   steps: list<string>,
     *   tips: list<string>,
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
                $haystack = mb_strtolower(implode(' ', [
                    $chapter['key'],
                    __('portal.'.$chapter['title_key']),
                    __('portal.'.$chapter['summary_key']),
                    implode(' ', $chapter['keywords']),
                    implode(' ', array_map(fn (string $key): string => __('portal.'.$key), $chapter['step_keys'])),
                ]));

                return str_contains($haystack, $needle);
            })
            ->map(fn (array $chapter): array => $this->present($chapter))
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, title: string, summary: string, steps: list<string>, tips: list<string>}|null
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
     * @return array{key: string, title: string, summary: string, steps: list<string>, tips: list<string>, keywords: list<string>}
     */
    private function present(array $chapter): array
    {
        return [
            'key' => $chapter['key'],
            'title' => __('portal.'.$chapter['title_key']),
            'summary' => __('portal.'.$chapter['summary_key']),
            'steps' => array_map(fn (string $key): string => __('portal.'.$key), $chapter['step_keys']),
            'tips' => array_map(fn (string $key): string => __('portal.'.$key), $chapter['tip_keys']),
            'keywords' => $chapter['keywords'],
        ];
    }

    /**
     * Complete, comprehensive 17-chapter user manual covering every major and minor feature.
     *
     * @return list<array<string, mixed>>
     */
    private function chapters(): array
    {
        return [
            [
                'key' => 'getting_started',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_getting_started_title',
                'summary_key' => 'manual_ch_getting_started_summary',
                'step_keys' => [
                    'manual_ch_getting_started_s1',
                    'manual_ch_getting_started_s2',
                    'manual_ch_getting_started_s3',
                    'manual_ch_getting_started_s4',
                ],
                'tip_keys' => ['manual_ch_getting_started_t1'],
                'keywords' => ['start', 'login', 'dashboard', 'tour', 'overview', 'navigation', 'language', 'শুরু', 'লগইন', 'ড্যাশবোর্ড', 'নেভিগেশন', 'ভাষা'],
            ],
            [
                'key' => 'profile_security',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_profile_title',
                'summary_key' => 'manual_ch_profile_summary',
                'step_keys' => [
                    'manual_ch_profile_s1',
                    'manual_ch_profile_s2',
                    'manual_ch_profile_s3',
                    'manual_ch_profile_s4',
                    'manual_ch_profile_s5',
                ],
                'tip_keys' => ['manual_ch_profile_t1', 'manual_ch_profile_t2'],
                'keywords' => ['profile', 'password', 'security', 'phone', 'kyc', 'id_card', 'poster', 'totp', '2fa', 'session', 'privacy', 'প্রোফাইল', 'পাসওয়ার্ড', 'নিরাপত্তা', 'আইডি কার্ড'],
            ],
            [
                'key' => 'catalog_orders',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_orders_title',
                'summary_key' => 'manual_ch_orders_summary',
                'step_keys' => [
                    'manual_ch_orders_s1',
                    'manual_ch_orders_s2',
                    'manual_ch_orders_s3',
                    'manual_ch_orders_s4',
                    'manual_ch_orders_s5',
                ],
                'tip_keys' => ['manual_ch_orders_t1', 'manual_ch_orders_t2'],
                'keywords' => ['order', 'catalog', 'purchase', 'cart', 'invoice', 'stock', 'discount', 'mrp', 'payment', 'bkash', 'nagad', 'bank', 'অর্ডার', 'ক্যাটালগ', 'ইনভয়েস', 'পেমেন্ট'],
            ],
            [
                'key' => 'deliveries_dues',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_deliveries_title',
                'summary_key' => 'manual_ch_deliveries_summary',
                'step_keys' => [
                    'manual_ch_deliveries_s1',
                    'manual_ch_deliveries_s2',
                    'manual_ch_deliveries_s3',
                    'manual_ch_deliveries_s4',
                ],
                'tip_keys' => ['manual_ch_deliveries_t1', 'manual_ch_deliveries_t2'],
                'keywords' => ['delivery', 'due', 'payment', 'claim', 'dispatch', 'courier', 'invoice_pdf', 'ডেলিভারি', 'বাকি', 'চালান'],
            ],
            [
                'key' => 'network_genealogy',
                'audience' => ['dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_network_title',
                'summary_key' => 'manual_ch_network_summary',
                'step_keys' => [
                    'manual_ch_network_s1',
                    'manual_ch_network_s2',
                    'manual_ch_network_s3',
                    'manual_ch_network_s4',
                ],
                'tip_keys' => ['manual_ch_network_t1', 'manual_ch_network_t2'],
                'keywords' => ['network', 'referral', 'tree', 'genealogy', 'downline', 'depth', 'invite', 'qr', 'export', 'নেটওয়ার্ক', 'রেফারাল', 'ডাউনলাইন'],
            ],
            [
                'key' => 'area_dealers_places',
                'audience' => ['dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_places_title',
                'summary_key' => 'manual_ch_places_summary',
                'step_keys' => [
                    'manual_ch_places_s1',
                    'manual_ch_places_s2',
                    'manual_ch_places_s3',
                ],
                'tip_keys' => ['manual_ch_places_t1'],
                'keywords' => ['place_code', 'area_dealer', 'territory', 'division', 'district', 'upazila', 'union', 'bangladesh', 'থানা', 'জেলা', 'প্লেস কোড'],
            ],
            [
                'key' => 'commissions_pool',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_earnings_title',
                'summary_key' => 'manual_ch_earnings_summary',
                'step_keys' => [
                    'manual_ch_earnings_s1',
                    'manual_ch_earnings_s2',
                    'manual_ch_earnings_s3',
                    'manual_ch_earnings_s4',
                ],
                'tip_keys' => ['manual_ch_earnings_t1', 'manual_ch_earnings_t2'],
                'keywords' => ['commission', 'earnings', 'rate', 'ledger', 'source', '40%', '60%', 'audit', 'কমিশন', 'আয়', 'লেজার'],
            ],
            [
                'key' => 'ranks_royalties',
                'audience' => ['dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_ranks_title',
                'summary_key' => 'manual_ch_ranks_summary',
                'step_keys' => [
                    'manual_ch_ranks_s1',
                    'manual_ch_ranks_s2',
                    'manual_ch_ranks_s3',
                ],
                'tip_keys' => ['manual_ch_ranks_t1'],
                'keywords' => ['rank', 'royalty', 'mo', 'am', 'sm', 'me', 'qualification', 'turnover', 'পদবী', 'র‍্যাঙ্ক', 'রয়্যালটি'],
            ],
            [
                'key' => 'rewards_milestones',
                'audience' => ['dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_rewards_title',
                'summary_key' => 'manual_ch_rewards_summary',
                'step_keys' => [
                    'manual_ch_rewards_s1',
                    'manual_ch_rewards_s2',
                    'manual_ch_rewards_s3',
                ],
                'tip_keys' => ['manual_ch_rewards_t1'],
                'keywords' => ['reward', 'bike', 'car', 'milestone', 'incentive', 'asset', 'পুরস্কার', 'বাইক', 'গাড়ি', 'মাইলফলক'],
            ],
            [
                'key' => 'subdealer_salary_tour',
                'audience' => ['sub_dealer', 'dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_salary_title',
                'summary_key' => 'manual_ch_salary_summary',
                'step_keys' => [
                    'manual_ch_salary_s1',
                    'manual_ch_salary_s2',
                    'manual_ch_salary_s3',
                ],
                'tip_keys' => ['manual_ch_salary_t1'],
                'keywords' => ['subdealer', 'salary', 'tour', 'reserve', 'allowance', '5%', '2%', 'sponsor', 'বেতন', 'ট্যুর রিজার্ভ', 'সাব-ডিলার'],
            ],
            [
                'key' => 'withdrawals_wallets',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_withdrawals_title',
                'summary_key' => 'manual_ch_withdrawals_summary',
                'step_keys' => [
                    'manual_ch_withdrawals_s1',
                    'manual_ch_withdrawals_s2',
                    'manual_ch_withdrawals_s3',
                    'manual_ch_withdrawals_s4',
                ],
                'tip_keys' => ['manual_ch_withdrawals_t1', 'manual_ch_withdrawals_t2'],
                'keywords' => ['withdrawal', 'wallet', 'payout', 'bkash', 'nagad', 'bank', 'rocket', 'threshold', 'statement', 'উত্তোলন', 'ওয়ালেট', 'পেআউট'],
            ],
            [
                'key' => 'reports',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_reports_title',
                'summary_key' => 'manual_ch_reports_summary',
                'step_keys' => [
                    'manual_ch_reports_s1',
                    'manual_ch_reports_s2',
                    'manual_ch_reports_s3',
                ],
                'tip_keys' => ['manual_ch_reports_t1'],
                'keywords' => ['report', 'date', 'pdf', 'summary', 'full_report', 'excel', 'রিপোর্ট'],
            ],
            [
                'key' => 'treasury_capital_flow',
                'audience' => ['staff', 'owner'],
                'title_key' => 'manual_ch_treasury_title',
                'summary_key' => 'manual_ch_treasury_summary',
                'step_keys' => [
                    'manual_ch_treasury_s1',
                    'manual_ch_treasury_s2',
                    'manual_ch_treasury_s3',
                ],
                'tip_keys' => ['manual_ch_treasury_t1'],
                'keywords' => ['treasury', 'capital', 'flow', '60%', 'retained', 'fund', 'split', 'payment_accounts', 'ট্রেজারি', 'মূলধন'],
            ],
            [
                'key' => 'owner_benefits_fund',
                'audience' => ['owner'],
                'title_key' => 'manual_ch_benefits_title',
                'summary_key' => 'manual_ch_benefits_summary',
                'step_keys' => [
                    'manual_ch_benefits_s1',
                    'manual_ch_benefits_s2',
                    'manual_ch_benefits_s3',
                ],
                'tip_keys' => ['manual_ch_benefits_t1'],
                'keywords' => ['benefits', 'reserve', 'equity', 'profit', 'confidential', 'owner_only', 'ওনার ফান্ড'],
            ],
            [
                'key' => 'staff_ops',
                'audience' => ['staff', 'owner'],
                'title_key' => 'manual_ch_staff_title',
                'summary_key' => 'manual_ch_staff_summary',
                'step_keys' => [
                    'manual_ch_staff_s1',
                    'manual_ch_staff_s2',
                    'manual_ch_staff_s3',
                    'manual_ch_staff_s4',
                ],
                'tip_keys' => ['manual_ch_staff_t1'],
                'keywords' => ['staff', 'accounts', 'approve', 'payout', 'support-admin', 'claims', 'inventory', 'dispatch', 'স্টাফ', 'অপারেশন'],
            ],
            [
                'key' => 'website_customization',
                'audience' => ['staff', 'owner'],
                'title_key' => 'manual_ch_website_title',
                'summary_key' => 'manual_ch_website_summary',
                'step_keys' => [
                    'manual_ch_website_s1',
                    'manual_ch_website_s2',
                    'manual_ch_website_s3',
                ],
                'tip_keys' => ['manual_ch_website_t1'],
                'keywords' => ['website', 'cms', 'customization', 'notice', 'banner', 'enquiries', 'partnerships', 'maps', 'ওয়েবসাইট', 'কনটেন্ট'],
            ],
            [
                'key' => 'support',
                'audience' => ['partner', 'dealer', 'sub_dealer', 'staff', 'owner'],
                'title_key' => 'manual_ch_support_title',
                'summary_key' => 'manual_ch_support_summary',
                'step_keys' => [
                    'manual_ch_support_s1',
                    'manual_ch_support_s2',
                    'manual_ch_support_s3',
                ],
                'tip_keys' => ['manual_ch_support_t1'],
                'keywords' => ['support', 'ticket', 'help', 'manual', 'dispute', 'prerequisites', 'tour', 'সাপোর্ট', 'হেল্প', 'টিকেট'],
            ],
        ];
    }
}
