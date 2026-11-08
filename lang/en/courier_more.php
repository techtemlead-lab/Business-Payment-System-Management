<?php

return [
    'provider_sundarban' => 'Sundarban Courier',
    'provider_sa_paribahan' => 'SA Paribahan',
    'provider_karatoa' => 'Karatoa Courier (KCS)',
    'provider_janani' => 'Janani Express',
    'provider_continental' => 'Continental Courier',

    // Couriers with no booking system to connect to.
    'err_manual_not_enabled' => 'This courier is switched off in Integrations.',
    'err_not_manual_parcel' => 'Only a parcel sent with a manual courier can be updated by hand.',
    'err_manual_status_backwards' => 'A parcel can only move forward. Pick a later step.',
    'err_manual_nothing' => 'Choose a new step or type the consignment number.',
    'notice_manual_dispatch_title' => 'Send by hand: :number',
    'notice_manual_dispatch_body' => 'Order :number uses :courier. This courier has no automatic booking, so please send the parcel and type the consignment number on the order.',
    'manual_hint' => 'This courier has no automatic booking. Update the step yourself whenever you hear from the courier.',
    'manual_status_label' => 'New step',
    'manual_tracking_label' => 'Consignment number',
    'manual_submit' => 'Save update',
    'flash_manual_updated' => 'Parcel updated.',

    // One parcel delivered and another returned on the same order.
    'decision_title' => 'Needs staff decision',
    'decision_hint' => 'On these orders one parcel was delivered and another came back. Talk to the dealer first, then decide the commission and write the reason.',
    'decision_empty' => 'No orders are waiting for a decision.',
    'decision_col_order' => 'Order',
    'decision_col_partner' => 'Dealer',
    'decision_col_action' => 'Decision',
    'decision_choice_label' => 'Commission',
    'decision_reason_label' => 'Reason (after talking to the dealer)',
    'decision_submit' => 'Save decision',
    'decision_view_only' => 'Someone with the order review permission must record this decision.',
    'err_decision_not_needed' => 'This order does not need a decision.',
    'err_decision_already' => 'A decision has already been recorded for this order.',
    'flash_decision_saved' => 'Decision saved.',
    'notice_decision_title' => 'Decision on order :number',
    'notice_decision_body' => 'Your order :number had one parcel delivered and another returned. The office decided about the commission: :decision. Reason: :reason',
];
