<?php

/*
 * Money-core fixes after the 2026-11-18 company test (stream B). Plain words for every reader.
 */
return [
    // Where commission with nobody to receive it goes (D1, D3, MA-26).
    'reason_no_upline' => 'No referrer at this level, so this share went to company profit.',
    'reason_two_area_seats' => 'Two area dealers hold place code :place at this level, so this share went to company profit instead of either one. Close the extra seat.',
    'reason_closed_account' => 'Commission held for a closed account for more than 3 months, so it went to company profit.',
    'release_reason_reactivated' => 'The account is active again, so the held commission was paid automatically.',
];
