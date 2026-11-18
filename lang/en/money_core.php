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

    // MA-26: smaller money cases.
    'notice_debt_recovered_title' => 'Money you owed from a refund was taken',
    'notice_debt_recovered_body' => 'You owed the company money from an earlier refund. ৳:amount of the money coming to you paid that off; the other ৳:credited was added to your money.',

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

    // MA-19
    'readiness_failed' => 'Money check failed. These checks did not pass: :checks',

    // MA-13
    'area_quota_required' => 'Enter the monthly target amount.',
    'area_quota_numeric' => 'Write the monthly target as a number only.',
    'area_quota_min' => 'The monthly target cannot be less than 0.',
    'area_quota_max' => 'The monthly target cannot be more than ৳:max. Please check the number; an extra zero or digit may have slipped in.',
    'area_quota_decimal' => 'Give at most two digits after the decimal point in the monthly target.',
    'flash_area_target_changed' => 'The monthly target for :zone is now ৳:quota.',
    'notice_area_target_changed_title' => 'Your monthly target changed',
    'notice_area_target_changed_body' => 'Your monthly target for :zone is now ৳:quota.',
    'area_seat_target_hint' => 'Amount typed: ৳:amount',
    'area_seat_edit' => 'Change target',
    'area_seat_close' => 'Close seat',
    'area_seat_close_confirm' => 'Close the seat for :zone? Past records and money stay exactly as they are; only new commission will stop coming to this seat.',
    'area_seat_save' => 'Save',
    'area_seat_held_quota' => 'This month the target was not met, so ৳:amount is held. It is released by itself once the target is met; if it is still not met at month end, it goes to company profit.',

    // MA-14, MA-24, money tasks
    'start_books_title' => 'Start the books',
    'start_books_body' => 'Your money records have not started yet, so partners cannot earn commission. If there are no old balances to bring in, press the button and the books start from zero. Old paper records can still be added later.',
    'start_books_confirm' => 'I confirm there are no old balances to bring in.',
    'start_books_button' => 'Start the books from zero',
    'start_books_reason' => 'Starting the books from zero, from the owner home page',
    'start_books_pointer' => 'To start from zero, use the "Start the books" step on the owner home page. Choosing it here does not change anything.',
    'start_books_pointer_link' => 'Go to the owner home page',
    'waiting_for_delivery_title' => 'Received, waiting for delivery',
    'waiting_for_delivery_note' => 'Money received on verified payments for orders that are not delivered yet. It moves into the funds when the goods are delivered.',
    'task_ready_to_pay' => 'Commission ready to pay',
    'task_held' => 'Commission held',
    'task_close_failed' => 'A month could not be closed',

    // MA-03 partner, MA-11, MA-22
    'held_heading' => 'Commission held for you',
    'held_total' => 'Held in total right now',
    'held_note' => 'This money is not in your balance yet.',
    'held_line' => ':what, order :order',
    'held_reason_inactive' => 'Held because your account is suspended. It is added to your money by itself when your account is active again.',
    'held_reason_quota' => 'Your monthly area target was not met. It is released when the target is met; if it is still not met at month end it goes to company profit.',
    'held_more' => ':count more held lines, see the My Money page',
    'net_referrer_held' => 'Held for the referrer (not received yet)',
    'net_you_held' => 'Held for you (not received yet)',
    'salary_heading' => 'How your monthly salary was worked out',
    'salary_paid' => ':month: your share is ৳:amount. The salary pool of your division was ৳:pool, shared equally by :sharers dealers.',
    'salary_rule' => 'Rule: the salary is shared equally among everyone in your division who bought ৳:min or more this month.',
    'salary_none_low' => ':month: no purchase of ৳:min or more this month, so no salary.',
    'salary_none_other' => ':month: you got no salary this month. Please ask the office why.',

    // MA-10: bike and car prizes have a pool each.
    'vehicle_pool_short' => 'The :program pool does not hold enough. Bike and car money are kept apart, so the other pool cannot be used.',
    'vp_title' => 'Bike and car pools are separate',
    'vp_intro' => 'Bike prizes are paid only from the bike pool and car prizes only from the car pool. Money saved from orders before the split counts half for bikes and half for cars.',
    'vp_balance' => 'In the pool now',
    'vp_award' => 'One prize pays',
    'vp_payable' => 'Prizes that can be paid now',
    'vp_not_set' => 'Not set',
    'cs_param_bike_pool_share_pct' => 'Bike pool share (%) — the rest goes to the car pool',
    'pr_prize' => 'Prize',
    'pr_prize_unset' => 'The amount is not decided yet',
    'pr_target' => 'Target (your team\'s total purchases)',
    'pr_deadline' => 'Deadline',
    'pr_deadline_none' => 'None; counted from the start',
    'pr_cap_example' => 'Example: if one of your direct lines buys ৳:example, only up to ৳:cap of it counts toward the target. So one big order in one line cannot reach the target alone.',
    'pr_claim' => 'How to get it: no separate application is needed. When the target is reached the office will contact you, and once the office approves, the prize money is added to your wallet.',
];
