<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Every translation group the screens read, as one versioned file per language.
 *
 * The groups used to travel inside every first page (about 1 MB of Bangla). They are now served
 * once from /i18n/{locale}/{version}.json with a one-year cache; the version changes whenever a
 * language file changes, so a browser downloads them again only after a new release.
 */
final class TranslationBundle
{
    /** Key the screens use => language file. */
    public const Groups = [
        'portal' => 'portal', 'auth' => 'auth_ui', 'courier' => 'courier', 'courier_more' => 'courier_more',
        'schemes_commission' => 'schemes_commission', 'prescription' => 'prescription',
        'prescription_letterhead' => 'prescription_letterhead', 'prescription_notepad' => 'prescription_notepad',
        'partner_form' => 'partner_form', 'website_admin' => 'website_admin', 'partner_hub' => 'partner_hub',
        'partner_profile' => 'partner_profile', 'product_facts' => 'product_facts', 'dealer_tools' => 'dealer_tools',
        'prescriber_public' => 'prescriber_public', 'accounting' => 'accounting', 'purchasing' => 'purchasing',
        'funds' => 'funds', 'reward_settings' => 'reward_settings', 'self_approval' => 'self_approval',
        'cr15' => 'cr15',
        'owner_security' => 'owner_security', 'withdrawal_wait' => 'withdrawal_wait', 'productivity' => 'productivity',
        'clinic_records' => 'clinic_records', 'stock_lots' => 'stock_lots', 'stock_print' => 'stock_print',
        'courier_areas' => 'courier_areas', 'wave3_ops' => 'wave3_ops', 'order_payment' => 'order_payment',
        'order_split' => 'order_split', 'shipping_label' => 'shipping_label', 'treasury' => 'treasury',
        'my_money' => 'my_money', 'income' => 'income', 'office_shell' => 'office_shell', 'office_menu' => 'office_menu',
        'office_orders' => 'office_orders', 'office_home' => 'office_home', 'office_stock' => 'office_stock',
        'size_groups' => 'size_groups', 'order_search' => 'order_search', 'office_partners' => 'office_partners',
        'partner_file' => 'partner_file', 'office_staff' => 'office_staff', 'office_website' => 'office_website',
        'office_settings' => 'office_settings', 'office_reports' => 'office_reports', 'office_visitors' => 'office_visitors',
        'office_errors' => 'office_errors', 'reason_fields' => 'reason_fields', 'partner_ux' => 'partner_ux',
        'money_core' => 'money_core', 'joining' => 'joining', 'orders_stock' => 'orders_stock',
        'stock_screens' => 'stock_screens', 'purchase_page' => 'purchase_page', 'payouts' => 'payouts', 'cr_partner' => 'cr_partner', 'cr14' => 'cr14', 'cr17' => 'cr17', 'cr18' => 'cr18', 'cr21_income' => 'cr21_income', 'cr21_identity' => 'cr21_identity', 'appointment_lines' => 'appointment_lines', 'office_profile' => 'office_profile', 'leftovers_office' => 'leftovers_office', 'leftovers_partner' => 'leftovers_partner', 'catalog_cart' => 'catalog_cart', 'qa_fixes' => 'qa_fixes', 'findability' => 'findability', 'message_templates' => 'message_templates',
    ];

    /** @var array<string, string> */
    private static array $versions = [];

    /**
     * @return array<string, mixed>
     */
    public static function for(string $locale): array
    {
        $bundle = [];
        foreach (self::Groups as $key => $file) {
            $bundle[$key] = Lang::get($file, [], $locale);
        }

        return $bundle;
    }

    /** A short fingerprint of the language files; it changes only when a file does. */
    public static function version(string $locale): string
    {
        return self::$versions[$locale] ??= substr(md5(implode('|', array_map(function (string $file) use ($locale): string {
            $path = lang_path($locale.'/'.$file.'.php');

            return is_file($path) ? filemtime($path).':'.filesize($path) : 'none';
        }, array_values(self::Groups))).'|'.$locale), 0, 16);
    }

    public static function url(string $locale): string
    {
        return '/i18n/'.$locale.'/'.self::version($locale).'.json';
    }
}
