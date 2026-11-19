<?php

namespace App\Domain\Accounts;

/**
 * The partner-side join link (link, QR, WhatsApp share, poster). The client says partners never
 * join anyone: the office sets the sponsor when it makes the account. Off unless the switch is on.
 * Referral commission does not depend on it.
 */
final class PartnerJoinLink
{
    public static function enabled(): bool
    {
        return (bool) config('madina.partner_join_link_enabled', false);
    }
}
