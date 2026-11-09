<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PaymentChannel
{
    /** A payment channel in the reader's language; a channel with no translation stays as saved. */
    public static function word(string $channel, ?string $locale = null): string
    {
        $key = 'portal.pay_channel_'.Str::lower(trim($channel));
        $word = (string) __($key, [], $locale);

        return $word === $key ? $channel : $word;
    }
}
