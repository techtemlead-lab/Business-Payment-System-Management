<?php

namespace App\Rules;

use App\Domain\Notifications\SmsText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Text typed for an SMS must have Bangla letters. The error is always in Bangla,
 * because the rule it explains is about Bangla and comes from the SMS company.
 */
class BanglaSmsText implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SmsText::hasBanglaLetters($value)) {
            $fail(SmsText::make('portal.sms_err_bangla_required'));
        }
    }
}
