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
    private const OldNames = [
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
        // English
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
    ];

    /**
     * Deliberate exceptions: "group.key" => why the old wording is correct there.
     *
     * @var array<string, string>
     */
    private const Allowed = [];

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
                    $clean = str_replace('সাব-ডিলার স্যালারি', '', $value);
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
            'sub_name_direct_referral' => ['স্টাফ কমিশন', 'Staff commission'],
            'head_sub_dealer_salary' => ['সাব-ডিলার স্যালারি', 'Sub-dealer salary'],
            'head_sub_dealer_tour' => ['ট্যুর', 'Tour'],
            'head_additional_rank' => ['সাব-ডিলার এক্সট্রা', 'Sub-dealer extra'],
        ];

        foreach ($expected as $key => [$bn, $en]) {
            $this->assertSame($bn, $page['bn'][$key] ?? null, $key.' (bn)');
            $this->assertSame($en, $page['en'][$key] ?? null, $key.' (en)');
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
