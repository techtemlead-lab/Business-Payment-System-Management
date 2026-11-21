<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Last-resort readable word for a commission code that has no translation. A plain headline of the code
 * would show the retired names ("D2d gen2", "Direct referral"), so those parts are written with the
 * current names first. Real names always come from the lang files; this only keeps a missing one from
 * bringing an old name back.
 */
final class CommissionCodeLabel
{
    /** @var array<string, string> Old code part => current name, longest parts first. */
    private const Parts = [
        'd2d_gen2' => 'team_income_2nd_step',
        'd2d_gen3' => 'team_income_3rd_step',
        'd2d_multitier' => 'team_income',
        'direct_referral' => 'staff_commission',
        'd2d' => 'team_income',
        'gen2' => '2nd_step',
        'gen3' => '3rd_step',
    ];

    /** The code with every retired part written as its current name; any other code is returned as it was. */
    public static function replaceRetired(string $code): string
    {
        $words = strtolower($code);
        foreach (self::Parts as $part => $name) {
            $words = (string) preg_replace('/(?<![a-z0-9])'.preg_quote($part, '/').'(?![a-z0-9])/', $name, $words);
        }

        return $words === strtolower($code) ? $code : $words;
    }

    /** A readable word for a code: current names for retired parts, otherwise a plain headline. */
    public static function fromCode(string $code): string
    {
        $replaced = self::replaceRetired($code);

        return $replaced === $code ? Str::headline($code) : ucfirst(str_replace('_', ' ', $replaced));
    }
}
