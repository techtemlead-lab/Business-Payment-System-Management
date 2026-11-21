<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The client renamed several commissions (2026-11-20). People must never see the old names again:
 * this scans every Bangla and English lang value for them. Internal rule keys are not names and are
 * not scanned (only values are).
 *
 * New names: Doctor Honorarium (was own purchase cashback), Dealer Acquisition Target Bonus (was
 * dealer monthly salary pool), Staff commission (a sub-dealer's direct referral), Sub-dealer salary,
 * Tour, Sub-dealer extra.
 */
class CommissionNamesInLangTest extends TestCase
{
    /** @var list<string> Old spellings, matched case-insensitively against every lang value. */
    public const OldNames = [
        // Bangla
        'ওন ডিলার',
        'নিজের কেনায় ক্যাশব্যাক',
        'নিজের ডিলার ক্যাশব্যাক',
        'ডিলার ক্যাশব্যাক',
        'ডিলার স্যালারি',
        'ডিলার মাসিক বেতন',
        'ডিলার বেতন ফান্ড',
        'মাসিক বেতন ফান্ড',
        'সাব-ডিলার মাসিক বেতন ফান্ড',
        'সাব-ডিলার অতিরিক্ত র‍্যাঙ্ক',
        'রেফার ১০',
        // The referral commission is now Staff commission for everyone (the plain word for the act of referring is fine)
        'রেফারেল আয়',
        'রেফার কমিশন',
        'রেফারেল কমিশন',
        'সরাসরি রেফার',
        'রেফার বোনাস',
        // English
        'direct referral',
        'referral income',
        'referral commission',
        'referral bonus',
        'referral money',
        'refer commission',
        'old referral',
        'own dealer commission',
        'own dealer cashback',
        'own purchase cashback',
        'own purchase income',
        'dealer monthly salary',
        'dealer salary pool',
        'dealer salary fund',
        'dealer salary payment fund',
        'monthly salary fund',
        'monthly salary pool',
        'sub-dealer monthly salary',
        'sub-dealer additional rank',
        // Second sweep: generic spellings of the same heads
        'cashback',
        'ক্যাশব্যাক',
        'back on own purchase',
        'নিজের কেনায় ফেরত',
        'dealer salary',
        'salary pool',
        'salary fund',
        'salary reserve',
        'সরাসরি রেফারেল',
        'বেতন ফান্ড',
        'বেতনের জমা ফান্ড',
        'বেতন রিজার্ভ',
        // The owners' fund is now Commission extra (client, 2026-11-21)
        'মালিক পক্ষের ফান্ড',
        'মালিক পক্ষের বেনিফিট',
        'মালিকদের সুবিধা',
        'বিশেষ বেনিফিট',
        'বেনিফিট ফান্ড',
        'অতিরিক্ত সুবিধা ফান্ড',
        'owners\' fund',
        'owners\' benefits',
        'owner benefits fund',
        'special benefit',
        'benefits fund',
        'owner commission',
    ];

    /**
     * Deliberate exceptions: "group.key" => why the old wording is correct there.
     *
     * @var array<string, string>
     */
    private const Allowed = [
        // The people a partner referred, or the referral relationship: not the commission.
        'portal.direct_referrals' => 'count of directly referred partners',
        'portal.net_direct_referrals' => 'count of level 1 referred partners',
        'portal.net_company_tree' => 'the referral tree (who referred whom)',
        'portal.dash_card_network_desc' => 'the people referred directly',
        // Search words people may still type, and the generic word in the manual's formula line.
        'findability.kw_my_income' => 'search keywords: people may still type the old words',
        'cr_partner.card_direct' => 'how many people the partner referred directly',
        'manual.network_genealogy_t2' => 'the direct referrer (a person), whose commission is named Staff commission',
    ];

    public function test_no_old_commission_name_is_left_in_any_lang_value(): void
    {
        $found = [];

        foreach (['bn', 'en'] as $locale) {
            foreach (glob(dirname(__DIR__, 2).'/lang/'.$locale.'/*.php') ?: [] as $file) {
                $group = basename($file, '.php');
                $messages = require $file;
                if (! is_array($messages)) {
                    continue;
                }
                foreach ($this->flatten($messages) as $key => $value) {
                    if (isset(self::Allowed[$group.'.'.$key])) {
                        continue;
                    }
                    // Placeholder names such as :cashback_rate are code, not words people read.
                    $clean = str_ireplace(['সাব-ডিলার স্যালারি', 'sub-dealer salary'], '', (string) preg_replace('/:[a-z_]+/i', '', $value));
                    foreach (self::OldNames as $old) {
                        if (mb_stripos($clean, $old) !== false) {
                            $found[] = $locale.'/'.$group.'.'.$key.'  ['.$old.']';
                        }
                    }
                }
            }
        }

        $this->assertSame([], $found, "Old commission names found. Use the new names:\n".implode("\n", $found));
    }

    public function test_every_renamed_head_has_its_new_name_in_both_languages(): void
    {
        $root = dirname(__DIR__, 2);
        $page = ['bn' => require $root.'/lang/bn/commission_page.php', 'en' => require $root.'/lang/en/commission_page.php'];
        $expected = [
            'head_own_dealer_cashback' => ['ডক্টর অনারারিয়াম', 'Doctor Honorarium'],
            'head_dealer_monthly_salary' => ['ডিলার একুইজিশন টার্গেট বোনাস', 'Dealer Acquisition Target Bonus'],
            'head_direct_referral' => ['স্টাফ কমিশন', 'Staff commission'],
            'head_sub_dealer_salary' => ['সাব-ডিলার স্যালারি', 'Sub-dealer salary'],
            'head_sub_dealer_tour' => ['ট্যুর', 'Tour'],
            'head_additional_rank' => ['সাব-ডিলার এক্সট্রা', 'Sub-dealer extra'],
        ];

        foreach ($expected as $key => [$bn, $en]) {
            $this->assertSame($bn, $page['bn'][$key] ?? null, $key.' (bn)');
            $this->assertSame($en, $page['en'][$key] ?? null, $key.' (en)');
        }
    }

    public function test_the_owners_fund_is_called_commission_extra_wherever_its_name_is_shown(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = ['bn' => 'কমিশন এক্সট্রা ফান্ড', 'en' => 'Commission extra fund'];
        $names = [
            'treasury.fund_benefits', 'funds.fund_benefits', 'orders_stock.fund_benefits',
            'portal.benefits_fund', 'portal.benefits_fund_title', 'portal.tr_kpi_benefits',
            'portal.tour_owner_benefits_title', 'portal.report_cat_company_commission_benefits_fund',
            'portal.retained_fund_benefits', 'manual.owner_benefits_fund_badge', 'manual.owner_benefits_fund_title',
        ];

        foreach ($expected as $locale => $name) {
            foreach ($names as $path) {
                [$group, $key] = explode('.', $path, 2);
                $messages = require $root.'/lang/'.$locale.'/'.$group.'.php';
                $this->assertSame($name, $messages[$key] ?? null, $locale.'/'.$path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } elseif (is_string($value)) {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
