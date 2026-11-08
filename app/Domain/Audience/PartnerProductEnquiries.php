<?php

namespace App\Domain\Audience;

use App\Domain\Notifications\WebPushNotices;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Enquiries from a product page a partner shared. The partner gets a portal notice with the
 * visitor's name, phone and message; no SMS goes out and nothing is sent to anyone else.
 */
final class PartnerProductEnquiries
{
    /** One visitor can write to one partner this many times a day. */
    public const MaxPerVisitorPerDay = 3;

    /** A partner receives at most this many enquiries a day, so a shared link cannot flood them. */
    public const MaxPerPartnerPerDay = 50;

    private const DayInSeconds = 86400;

    /** The active partner a shared link's code belongs to, by their refer code or dealer code. */
    public function partnerForCode(string $code): ?User
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->where(function ($query) use ($code): void {
                $query->whereRaw('UPPER(refer_code) = ?', [$code])
                    ->orWhereRaw('UPPER(dealer_code) = ?', [$code]);
            })
            ->first();
    }

    /**
     * @param  array{name: string, phone: string, message: string}  $enquiry
     *
     * @throws ValidationException when this visitor or this partner has reached today's limit
     */
    public function submit(User $partner, Product $product, array $enquiry): void
    {
        $this->assertWithinDailyLimits($partner, $enquiry['phone']);
        $locale = $partner->preferredLocale();
        $title = __('dealer_tools.enquiry_notice_title', ['product' => $product->name], $locale);
        DB::table('account_notices')->insert([
            'user_id' => $partner->id,
            'title' => $title,
            'body' => __('dealer_tools.enquiry_notice_body', [
                'product' => $product->name,
                'name' => $enquiry['name'],
                'phone' => $enquiry['phone'],
                'message' => $enquiry['message'],
            ], $locale),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // A push can appear on a locked phone, so it says only that an enquiry arrived.
        app(WebPushNotices::class)->send([[
            'user_id' => $partner->id,
            'title' => $title,
            'body' => __('dealer_tools.enquiry_push_body', [], $locale),
        ]]);
    }

    private function assertWithinDailyLimits(User $partner, string $phone): void
    {
        $visitorKey = 'partner-enquiry:visitor:'.$partner->id.':'.$phone;
        $partnerKey = 'partner-enquiry:partner:'.$partner->id;
        if (RateLimiter::tooManyAttempts($visitorKey, self::MaxPerVisitorPerDay)
            || RateLimiter::tooManyAttempts($partnerKey, self::MaxPerPartnerPerDay)) {
            throw ValidationException::withMessages(['phone' => __('dealer_tools.err_enquiry_limit')]);
        }
        RateLimiter::hit($visitorKey, self::DayInSeconds);
        RateLimiter::hit($partnerKey, self::DayInSeconds);
    }
}
