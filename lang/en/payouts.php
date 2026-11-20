<?php

/*
 * Withdrawals, payouts and payment checks (fix stream C, 2026-11-18).
 */
return [
    // Refusals
    'err_nid_first' => 'Before your first withdrawal we need your National ID (NID). Send your NID number and a photo of the card from your profile. Withdrawals open once the office has checked it.',
    'err_over_balance' => 'You cannot withdraw that much. The most you can withdraw now is :balance.',
    'err_channel_limit' => 'You can withdraw at most :limit at a time by :channel. You can withdraw :balance now. For more, choose bank or withdraw in parts.',
    'err_approve_over_limit' => 'A single :channel payment can be at most :limit, so this cannot be approved. Reject it and ask the partner to request a bank payment.',
    'err_office_cash_limit' => 'Cash at the office is accepted up to :limit. Send :amount by bank or mobile banking and give the TrxID.',
    'err_payer_not_allowed' => 'This person cannot pay this withdrawal. Choose someone else in finance (not the person who approved it).',
    'err_cancel_not_pending' => 'Only a request that is still waiting to be checked can be cancelled. Once it is approved, call the office.',
    'err_receipt_not_due' => 'This payment already has its receipt.',
    'err_partner_not_active' => 'The partner\'s account is not active (suspended or closed). Nothing can be approved or paid until it is active again.',
    'err_on_hold' => 'This request is on hold because the partner\'s account was suspended. It can go ahead once the account is active again.',
    'err_security_wait' => 'The partner changed their phone number or password a short while ago. For safety, approve or pay after :time.',
    'err_reference_mobile' => 'A bKash/Nagad TrxID has 8 to 12 letters and digits, with no signs or spaces. Copy it from the message.',
    'err_reference_format' => 'The reference is not right. Write at least 6 letters or digits (only - / . may sit between them).',
    'err_receipt_too_small' => 'The receipt picture is too small to read. Upload the full screenshot or a clear photo.',
    'err_receipt_blank' => 'The receipt picture looks blank. Upload the real screenshot or photo of the transfer.',
    'err_amount_digits' => 'Write the amount in digits only, for example 5000 or 5000.50.',
    'err_tin_format' => 'A TIN has 12 digits. Please check it.',
    'err_cash_count_mismatch' => 'The cash counted (:counted) is not the payment amount (:amount). Confirm only when the full amount is in hand; otherwise reject.',
    'err_cash_count_needed' => 'Count the cash and write how much you received.',
    'err_method_differs' => 'The partner asked to be paid by :channel. Send it that way, or hand over cash at the office. For another method, ask the partner for a new request.',
    'err_destination_differs' => 'This is not the number the partner gave (:number). Send it to the partner\'s own number.',
    'proof_looks_blank' => 'Careful: the payment picture looks blank or very small. Confirm only after matching the TrxID in the bank or mobile banking account.',

    // Withdrawal history lines
    'event_cancelled_by_partner' => 'The partner cancelled the request. The money went back to the wallet.',
    'event_receipt_due' => 'Money sent; the receipt will be added later.',
    'event_hold_suspended' => 'The partner\'s account was suspended, so the request is on hold.',
    'event_hold_reactivated' => 'The partner\'s account is active again; the request goes ahead.',
    'event_hold_closed' => 'The partner\'s account was closed, so the request is on hold.',

    // Partner notice
    'notice_cancelled_title' => 'Withdrawal request cancelled',
    'notice_cancelled_body' => 'You cancelled your request to withdraw :amount. The money is back in your wallet.',

    // Flash messages
    'flash_cancelled' => 'Request cancelled. The money is back in your wallet.',
    'flash_payer_named' => 'The person who will pay is saved.',
    'flash_receipt_attached' => 'Receipt added.',
    'flash_tin_saved' => 'TIN saved.',

    // Statuses, filters and the dashboard
    'status_cancelled' => 'Cancelled (partner)',
    'filter_follow_up' => 'Still to do',
    'follow_up_overdue' => 'Overdue payouts',
    'follow_up_on_hold' => 'On hold',
    'follow_up_receipt_due' => 'Receipt missing',
    'dash_payouts_overdue' => 'Approved but not sent for over :days days',
    'ops_warn_payout_tax_rate_zero' => 'No tax is withheld from payouts (0%). Confirm the rate with an accountant or lawyer and set it in the commission rules.',

    // Facts finance checks (review and payment boxes)
    'facts_title' => 'Check before approving',
    'facts_loading' => 'Loading details…',
    'facts_failed' => 'Could not load the details. Close the box and open it again.',
    'fact_partner' => 'Partner',
    'fact_status' => 'Account',
    'account_active' => 'Active',
    'account_suspended' => 'Suspended',
    'account_closed' => 'Closed',
    'fact_balance' => 'Balance to withdraw',
    'fact_held' => 'Money held',
    'fact_other_open' => 'Other open requests',
    'fact_other_open_value' => ':count, total :amount',
    'fact_none' => 'None',
    'fact_destination' => 'Where the money goes (full number)',
    'fact_channel_limit' => 'Most per payment by this method',
    'fact_no_limit' => 'No separate limit',
    'fact_over_limit' => 'This amount is above the limit',
    'fact_credentials' => 'Last password or phone change',
    'fact_never' => 'Never changed',
    'fact_changed_near' => 'Changed close to this request. Call the partner on the old number to confirm.',
    'fact_nid' => 'National ID (NID)',
    'fact_tin' => 'TIN (tax number)',
    'fact_on_file' => 'On file',
    'fact_missing' => 'Missing',
    'fact_history' => 'Earlier requests (last 5)',
    'fact_history_none' => 'No earlier requests',
    'blocked_title' => 'Cannot be approved or paid now',
    'amount_words' => 'In words: :words',

    // Who pays and by when (MA-06)
    'payer_label' => 'Who sends the money',
    'payer_choose' => 'Choose…',
    'payer_none' => 'Nobody named yet',
    'payer_named' => 'Paid by: :name',
    'payer_take' => 'I will pay',
    'waiting_days' => 'Waiting :days days',
    'waiting_today' => 'Today',
    'overdue_badge' => 'Overdue',
    'on_hold_badge' => 'On hold (account suspended)',
    'receipt_due_badge' => 'Receipt missing',
    'pay_by' => 'Send by: :date',
    'kpi_overdue' => 'Overdue payouts',
    'kpi_overdue_hint' => ':count approvals older than :days days',
    'kpi_followups' => ':hold on hold · :receipt payout receipts missing',

    // Receipt without a desktop file chooser
    'receipt_later' => 'Money sent, receipt later',
    'receipt_later_hint' => 'It is recorded without the receipt and shows as "Receipt missing". Add it later with "Add receipt".',
    'receipt_paste_hint' => 'Copy the screenshot and press Ctrl+V here, or take a photo with the phone camera.',
    'receipt_pasted' => 'Added: :name',
    'attach_receipt' => 'Add receipt',
    'attach_save' => 'Save receipt',

    // Partner side
    'cancel_request' => 'Cancel request',
    'cancel_confirm' => 'Cancel this request? The money goes back to your wallet.',
    'partner_pay_by' => 'The office will send the money by :date.',
    'partner_on_hold' => 'This request is on hold because your account is suspended. Please contact the office.',
    'stage_in_review' => 'Being checked',
    'withdraw_limit_hint' => 'Most per withdrawal by this method: :limit',
    'withdraw_over_limit' => 'This amount is above the :channel limit. Choose bank or write less.',
    'tin_title' => 'TIN (tax number)',
    'tin_hint' => 'Add it if you have one. It is used for the tax record. 12 digits.',
    'tin_save' => 'Save TIN',
    'tin_saved' => 'Saved TIN ending in :last',

    // Owner limits (finance settings)
    'limits_title' => 'Withdrawal and office cash limits',
    'limits_body' => 'The most one withdrawal can be by each method, the most cash the office takes for one order, and how many days after approval the money must be sent.',
    'limit_channel' => 'Most per :channel withdrawal (empty = no separate limit)',
    'limit_office_cash' => 'Most cash at the office (one order)',
    'limit_due_days' => 'Days to send after approval',
    'limits_save' => 'Save limits',
    'limits_owner_only' => 'Only the owner can change these limits.',

    // Payment checks (MA-17, MA-21)
    'cash_counted' => 'Cash counted',
    'cash_counted_hint' => 'Count the cash and write it. Confirm only when the full :amount is in hand.',
    'verify_next' => 'Save and open the next',
    'queue_oldest_first' => 'Oldest first',
    'filter_checked_by' => 'Checked by',
    'cash_hint_rejected' => 'The cash payment was rejected. Choose "Cash at office" again and bring the money to the office.',
    'payroll_owner_alone' => 'Prepared and approved by the owner alone (allowed by the owner rule, recorded in the log)',
    'suspend_hold_note' => 'Suspending puts every open withdrawal of this partner on hold. Nothing can be approved or sent until the account is active again.',
];
