<?php

/*
 * Courier auto-booking and live tracking (stream F). Kept apart from portal.php so the
 * courier work does not clash with other changes there. New keys go at the end.
 */
return [
    'company_decides' => 'Company decides',
    'place_code_in_address' => 'Place code :code',

    // Where the parcel is with the courier.
    'status_waiting_pickup' => 'Waiting for pickup',
    'status_picked_up' => 'Picked up',
    'status_in_transit' => 'On the way',
    'status_delivered' => 'Delivered',
    'status_returned' => 'Returned',

    // Notices.
    'notice_status_title' => 'Parcel update for order :number',
    'notice_status_body' => 'Your parcel (:courier · :tracking) is now: :status.',
    'notice_staff_finished_title' => 'Courier update for order :number',
    'notice_staff_finished_body_delivered' => ':courier says the parcel for order :number was delivered. Please check and confirm the delivery.',
    'notice_staff_finished_body_returned' => ':courier says the parcel for order :number was returned. Please check with the courier.',
    'notice_booking_failed_title' => 'Courier booking failed: :number',
    'notice_booking_failed_body' => ':courier did not accept the parcel for order :number (:message). Open the order to try again or change the courier.',
    'notice_auto_skipped_title' => 'Order :number needs a manual dispatch',
    'notice_auto_skipped_body_courier_off' => 'The dealer chose :courier, but that courier is switched off in Integrations. Change the courier or dispatch by hand.',
    'notice_auto_skipped_body_no_branch' => 'Order :number could not be booked with :courier because no active branch holds stock. Dispatch it by hand.',
    'notice_auto_skipped_body_stock' => 'Order :number could not be booked with :courier because the branch does not have enough stock. Dispatch it by hand when stock arrives.',
    'notice_changed_title' => 'Courier changed for order :number',
    'notice_changed_body' => 'We changed the courier for order :number from :from to :to.',
    'notice_changed_body_with_note' => 'We changed the courier for order :number from :from to :to. Note: :note',

    // Errors.
    'err_courier_not_enabled' => 'This courier is not switched on. Turn it on in Integrations first.',
    'err_change_after_booking' => 'The courier has already been booked. It can only be changed before booking or after a failed booking.',
    'err_change_closed_order' => 'The courier can no longer be changed for this order.',
    'err_same_courier' => 'This order already uses that courier.',
    'err_retry_not_failed' => 'Only a failed booking can be tried again.',
    'err_pickup_not_waiting' => 'This parcel is not waiting for pickup.',

    // Messages after an action.
    'flash_changed' => 'Courier changed. The dealer has been told.',
    'flash_retried' => 'Booking sent to the courier again.',
    'flash_picked_up' => 'Marked as picked up.',

    // Order form.
    'order_form_label' => 'Courier',
    'order_form_hint' => 'Choose the courier for your parcel, or let the company choose.',

    // Order page: tracking panel.
    'tracking_title' => 'Parcel tracking',
    'chosen_courier' => 'Courier chosen',
    'tracking_number' => 'Tracking number',
    'copy' => 'Copy',
    'copied' => 'Copied',
    'open_courier_page' => 'Track on the courier website',
    'last_update' => 'Last update',
    'last_checked' => 'Last checked with the courier',
    'courier_said' => 'Courier says: :text',
    'waiting_for_booking' => 'The parcel will be booked with the courier after the order is approved.',
    'booking_pending' => 'Booking with the courier. The tracking number will show here soon.',
    'booking_failed' => 'The courier did not accept this booking. Our team is fixing it.',
    'booking_failed_staff' => 'The courier did not accept this booking: :message',
    'changed_from_to' => 'Courier changed from :from to :to',
    'changed_note' => 'Note: :note',
    'retry' => 'Try booking again',
    'mark_picked_up' => 'Mark picked up',
    'change_title' => 'Change courier',
    'change_hint' => 'Use this before booking, or when the courier did not accept the parcel.',
    'change_label' => 'New courier',
    'change_note_label' => 'Note for the dealer (optional)',
    'change_note_hint' => 'For example: this courier is closed today, or does not serve your area.',
    'change_submit' => 'Change courier',
    'no_other_courier' => 'No other courier is switched on.',

    // Order history.
    'event_order_courier_changed' => 'Courier changed',
    'event_order_courier_booking_retried' => 'Courier booking tried again',
    'event_order_courier_auto_skipped' => 'Courier not booked automatically',
    'event_shipment_courier_status' => 'Parcel status changed',

    // Deliveries board.
    'board_title' => 'Courier parcels',
    'board_hint' => 'Every parcel booked with a courier. Filter by step or courier.',
    'filter_status' => 'Step',
    'filter_courier' => 'Courier',
    'filter_all' => 'All',
    'filter_booking' => 'Not booked yet',
    'filter_apply' => 'Show',
    'failed_title' => 'Failed bookings',
    'failed_hint' => 'The courier did not accept these parcels. Open the order to try again or change the courier.',
    'failed_empty' => 'No failed bookings.',
    'board_empty' => 'No courier parcels match.',
    'col_order' => 'Order',
    'col_partner' => 'Dealer',
    'col_courier' => 'Courier',
    'col_tracking' => 'Tracking',
    'col_status' => 'Step',
    'col_updated' => 'Updated',
    'col_problem' => 'Problem',
    'open_order' => 'Open',

    // Manual dispatch form.
    'dispatch_provider_label' => 'Book with a courier (optional)',
    'dispatch_provider_none' => 'No, I will type the tracking number',

    // A booking that stopped before the courier gave a clear answer.
    'booking_outcome_unknown' => 'No clear answer from the courier, so this parcel may already be booked. Check the courier\'s own panel first. If it is there, do not book it again.',

    // A parcel the courier sent back.
    'returned_cannot_confirm' => 'The courier returned this parcel, so it cannot be confirmed as delivered. No commission is paid for a returned parcel.',
];
