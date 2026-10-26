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
            return ['owner', 'staff', 'dealer', 'sub_dealer'];
        }
        if ($user->staff() || $user->usingStaffWorkspace()) {
            return ['staff', 'dealer', 'sub_dealer'];
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
                'keywords' => ['start', 'login', 'dashboard', 'tour', 'overview', 'শুরু', 'লগইন'],
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
                ],
                'tip_keys' => ['manual_ch_profile_t1'],
                'keywords' => ['profile', 'password', 'security', 'phone', 'প্রোফাইল', 'পাসওয়ার্ড'],
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
                ],
                'tip_keys' => ['manual_ch_orders_t1', 'manual_ch_orders_t2'],
                'keywords' => ['order', 'catalog', 'purchase', 'cart', 'invoice', 'অর্ডার', 'ক্যাটালগ', 'ইনভয়েস'],
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
                ],
                'tip_keys' => ['manual_ch_deliveries_t1'],
                'keywords' => ['delivery', 'due', 'payment', 'claim', 'ডেলিভারি', 'বাকি'],
            ],
            [
                'key' => 'commissions_earnings',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_earnings_title',
                'summary_key' => 'manual_ch_earnings_summary',
                'step_keys' => [
                    'manual_ch_earnings_s1',
                    'manual_ch_earnings_s2',
                    'manual_ch_earnings_s3',
                    'manual_ch_earnings_s4',
                ],
                'tip_keys' => ['manual_ch_earnings_t1'],
                'keywords' => ['commission', 'earnings', 'rate', 'ledger', 'source', 'কমিশন', 'আয়'],
            ],
            [
                'key' => 'withdrawals',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_withdrawals_title',
                'summary_key' => 'manual_ch_withdrawals_summary',
                'step_keys' => [
                    'manual_ch_withdrawals_s1',
                    'manual_ch_withdrawals_s2',
                    'manual_ch_withdrawals_s3',
                    'manual_ch_withdrawals_s4',
                ],
                'tip_keys' => ['manual_ch_withdrawals_t1'],
                'keywords' => ['withdrawal', 'payout', 'bkash', 'nagad', 'bank', 'উত্তোলন', 'পেআউট'],
            ],
            [
                'key' => 'network_ranks',
                'audience' => ['dealer'],
                'title_key' => 'manual_ch_network_title',
                'summary_key' => 'manual_ch_network_summary',
                'step_keys' => [
                    'manual_ch_network_s1',
                    'manual_ch_network_s2',
                    'manual_ch_network_s3',
                ],
                'tip_keys' => ['manual_ch_network_t1'],
                'keywords' => ['network', 'referral', 'rank', 'invite', 'নেটওয়ার্ক', 'র‍্যাঙ্ক'],
            ],
            [
                'key' => 'reports',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_reports_title',
                'summary_key' => 'manual_ch_reports_summary',
                'step_keys' => [
                    'manual_ch_reports_s1',
                    'manual_ch_reports_s2',
                    'manual_ch_reports_s3',
                ],
                'tip_keys' => ['manual_ch_reports_t1'],
                'keywords' => ['report', 'date', 'pdf', 'summary', 'রিপোর্ট'],
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
                'keywords' => ['support', 'ticket', 'help', 'manual', 'সাপোর্ট', 'হেল্প'],
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
                'keywords' => ['staff', 'accounts', 'approve', 'payout', 'support-admin', 'স্টাফ'],
            ],
            [
                'key' => 'dual_dashboard',
                'audience' => ['partner', 'dealer', 'sub_dealer'],
                'title_key' => 'manual_ch_dual_title',
                'summary_key' => 'manual_ch_dual_summary',
                'step_keys' => [
                    'manual_ch_dual_s1',
                    'manual_ch_dual_s2',
                    'manual_ch_dual_s3',
                ],
                'tip_keys' => ['manual_ch_dual_t1'],
                'keywords' => ['switch', 'staff', 'partner', 'dashboard', 'স্যুইচ'],
            ],
        ];
    }
}
