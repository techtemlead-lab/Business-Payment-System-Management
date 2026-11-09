<?php

namespace App\Support;

use Mpdf\Config\FontVariables;
use Mpdf\Language\LanguageToFont;
use Mpdf\Language\LanguageToFontInterface;

/**
 * The font settings every PDF uses. FreeSerif's bold file has no Bangla letters, so bold Bangla
 * came out as boxes. Bangla text now uses a font with no bold file, and the PDF maker thickens
 * the regular letters instead.
 */
final class PdfFonts
{
    public const BanglaFamily = 'banglaserif';

    /**
     * @return array{default_font: string, autoScriptToLang: bool, autoLangToFont: bool, fontdata: array<string, array<string, mixed>>, languageToFont: LanguageToFontInterface}
     */
    public static function options(): array
    {
        return [
            'default_font' => 'freeserif',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                self::BanglaFamily => ['R' => 'FreeSerif.ttf', 'useOTL' => 0xFF],
            ],
            'languageToFont' => new class extends LanguageToFont
            {
                /**
                 * @param  string  $llcc
                 * @param  bool  $adobeCJK
                 * @return array{0: bool, 1: string}
                 */
                public function getLanguageOptions($llcc, $adobeCJK): array
                {
                    [$coreSuitable, $font] = parent::getLanguageOptions($llcc, $adobeCJK);
                    $language = strtolower(explode('-', (string) $llcc)[0]);

                    return [$coreSuitable, in_array($language, ['bn', 'ben'], true) ? PdfFonts::BanglaFamily : $font];
                }
            },
        ];
    }
}
