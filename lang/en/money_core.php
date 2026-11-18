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

    // MA-15: owner switch for paying matured commission on its own.
    'arm_title' => 'Commission whose waiting days are over',
    'arm_switch' => 'Pay commission into the partner\'s money on its own when its waiting days are over',
    'arm_on' => 'On: paid on its own every 15 minutes.',
    'arm_off' => 'Off: paid only when someone presses "Pay now" on the Settlements page.',
    'arm_off_server' => 'Off (from the server setting): paid only when someone presses "Pay now" on the Settlements page. Turn it on here and save, and this setting is followed instead.',
    'arm_owner_only' => 'Only the owner can change this.',

    // MA-01 and MA-23: month close status on the Settlements page.
    'mc_failed_title' => 'A month could not be closed',
    'mc_failed_line' => ':kind for :month did not close on its own (:error). It is tried again every hour; you can also close it below.',
    'mc_waiting_title' => 'Months not closed yet',
    'mc_waiting_auto' => 'Closes on its own within the hour',
    'mc_waiting_person' => 'Older month: needs your approval',
    'mc_close_button' => 'Close this month',
    'mc_closed_title' => 'Months already closed',
    'mc_closed_line' => ':kind for :month was closed on :date. Paid to partners ৳:paid; to company profit ৳:company.',
    'mc_none_waiting' => 'No month is waiting.',
    'mc_kind_salary' => 'Monthly salary',
    'mc_kind_royalty' => 'Rank royalty',
    'mc_kind_area_quota' => 'Area target month end',
    'mc_kind_additional_rank' => 'Sub-dealer rank fund month end',
    'mc_month_label' => 'Month',
    'flash_already_closed' => ':kind for :month was already closed on :date. Nothing was left to pay.',
];
