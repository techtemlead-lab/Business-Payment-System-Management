<?php

namespace App\Domain\Payouts;

use App\Support\LocalDigits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks on what finance records when paying a partner (MA-25): the transaction reference must look
 * like one from the method used and must not have been used before for any payment in or out, and a
 * receipt photo must not be a blank or tiny picture.
 */
final class PayoutReceiptCheck
{
    /** The smallest a receipt photo or screenshot may be on its longer side, in pixels. */
    public const MinimumImageSide = 200;

    /** Pictures bigger than this are not opened for the blank check, to keep memory low. */
    private const MaximumCheckedPixels = 40_000_000;

    /** bKash and Nagad transaction IDs are 8 to 12 letters and digits, nothing else. */
    private const MobileMoneyReference = '/^[A-Z0-9]{8,12}$/D';

    /** Bank and other references: letters and digits, with dashes, slashes or dots between them. */
    private const OtherReference = '/^[A-Z0-9][A-Z0-9\-\/\.]{5,99}$/D';

    /** @throws ValidationException */
    public static function refuseBadReference(string $method, string $reference): void
    {
        if ($method === 'cash') {
            return;
        }
        $reference = Str::upper(trim($reference));
        $pattern = in_array($method, ['bkash', 'nagad'], true) ? self::MobileMoneyReference : self::OtherReference;
        if (preg_match($pattern, $reference) !== 1) {
            throw ValidationException::withMessages(['payment_reference' => __(in_array($method, ['bkash', 'nagad'], true) ? 'payouts.err_reference_mobile' : 'payouts.err_reference_format')]);
        }
    }

    /** The payout method that matches each channel a partner may ask to be paid by. */
    private const MethodForChannel = ['Bank' => 'bank', 'bKash' => 'bkash', 'Nagad' => 'nagad', 'Rocket' => 'other'];

    /**
     * Money goes where the partner asked (F-verify-01): the same method as the request, and the wallet
     * or account typed must be the partner's own number. Cash handed over at the office is not checked
     * here (the receiver's name is recorded instead).
     *
     * @param  array{method: string, wallet_number?: ?string, account_number?: ?string}  $payout
     *
     * @throws ValidationException
     */
    public static function refuseOtherDestination(array $payout, string $channel, string $requested): void
    {
        $method = $payout['method'];
        if ($method === 'cash') {
            return;
        }
        $expected = self::MethodForChannel[$channel] ?? null;
        if ($expected !== null && $method !== $expected) {
            throw ValidationException::withMessages(['method' => __('payouts.err_method_differs', ['channel' => __('portal.pay_channel_'.strtolower($channel))])]);
        }
        $field = in_array($method, ['bkash', 'nagad'], true) ? 'wallet_number' : 'account_number';
        $typed = self::digits((string) ($payout[$field] ?? ''));
        $wanted = self::digits($requested);
        if ($typed === '' || $wanted === '') {
            return;
        }
        $same = $field === 'wallet_number'
            ? self::localPhone($typed) === self::localPhone($wanted)
            : (strlen($typed) >= 4 && (str_contains($wanted, $typed) || str_contains($typed, $wanted)));
        if (! $same) {
            throw ValidationException::withMessages([$field => __('payouts.err_destination_differs', ['number' => str_repeat('•', max(0, strlen($wanted) - 4)).substr($wanted, -4)])]);
        }
    }

    private static function digits(string $text): string
    {
        return (string) preg_replace('/\D+/', '', LocalDigits::latin($text));
    }

    /** A Bangladesh mobile number without the 88 country code. */
    private static function localPhone(string $digits): string
    {
        return strlen($digits) === 13 && str_starts_with($digits, '88') ? substr($digits, 2) : $digits;
    }

    /** True when this reference already belongs to a payout, a recorded company payment or a partner's payment. */
    public static function referenceUsed(string $reference): bool
    {
        $reference = Str::upper(trim($reference));

        return DB::table('withdrawals')->whereRaw('UPPER(TRIM(payment_reference)) = ?', [$reference])->exists()
            || DB::table('manual_payment_references')->where('reference', $reference)->exists()
            || DB::table('payment_claims')->whereRaw('UPPER(reference) = ?', [$reference])->where('status', '<>', 'rejected')->exists()
            || DB::table('receipts')->whereRaw('UPPER(reference) = ?', [$reference])->exists();
    }

    /**
     * Refuses a receipt picture that is tiny or one flat colour (a blank page or an empty screenshot).
     * PDFs are not opened.
     *
     * @throws ValidationException
     */
    public static function refuseBlankImage(UploadedFile $file): void
    {
        $problem = self::imageProblem($file);
        if ($problem !== null) {
            throw ValidationException::withMessages(['receipt' => __($problem === 'too_small' ? 'payouts.err_receipt_too_small' : 'payouts.err_receipt_blank')]);
        }
    }

    /**
     * 'too_small' or 'blank' when a picture cannot be proof of anything, else null. A partner's own
     * payment proof is only flagged for the checker with this (F-verify-02); a payout receipt is refused.
     *
     * @return 'too_small'|'blank'|null
     */
    public static function imageProblem(UploadedFile $file): ?string
    {
        $size = @getimagesize($file->getRealPath());
        if ($size === false) {
            return null;
        }
        [$width, $height] = $size;
        if (max($width, $height) < self::MinimumImageSide) {
            return 'too_small';
        }
        if ($width * $height > self::MaximumCheckedPixels || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            return null;
        }
        $lowest = 255;
        $highest = 0;
        $steps = 40;
        for ($x = 0; $x < $steps; $x++) {
            for ($y = 0; $y < $steps; $y++) {
                $rgb = imagecolorat($image, intdiv($x * ($width - 1), $steps - 1), intdiv($y * ($height - 1), $steps - 1));
                $colour = imagecolorsforindex($image, $rgb);
                $light = (int) round(0.299 * $colour['red'] + 0.587 * $colour['green'] + 0.114 * $colour['blue']);
                $lowest = min($lowest, $light);
                $highest = max($highest, $light);
            }
        }
        imagedestroy($image);

        return $highest - $lowest < 12 ? 'blank' : null;
    }
}
