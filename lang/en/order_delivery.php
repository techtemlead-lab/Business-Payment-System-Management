<?php

return [
    'method_label' => 'How will you get the goods',
    'method_courier' => 'By courier',
    'method_office' => 'I will collect from the office',
    'method_office_hint' => 'Once the order is approved, come to the office and take the goods. No courier or address is needed.',
    'method_courier_staff' => 'By courier',
    'method_office_staff' => 'Collect from the office',

    'collect_from_office' => 'Collect from the office',
    'detail_title' => 'How the goods are received',
    'detail_office_waiting' => 'The goods for this order are collected at the office, once it is approved.',
    'detail_office_ready' => 'The order is approved. The goods can be collected at the office now.',
    'detail_office_done' => 'The goods were collected at the office.',

    'handover_title' => 'Hand over the goods at the office',
    'handover_hint' => 'Confirm here when the partner takes the goods at the office. No courier or tracking number is needed. The goods leave the stock and the order counts as delivered.',
    'handover_courier_order_note' => 'The partner asked for a courier for this order. Handing it over makes it an office pickup and the partner is told.',
    'handover_branch' => 'From the stock of',
    'handover_note' => 'Who took the goods (note)',
    'handover_button' => 'Goods handed over',
    'handover_default_reason' => 'Handed to the partner at the office',

    'change_title' => 'Change how the goods go',
    'change_hint' => 'This can change before anything is sent. The partner is told.',
    'change_label' => 'New way',
    'change_note' => 'Note for the partner',
    'change_submit' => 'Change',

    'notice_approved_pickup_title' => 'Collect your goods at the office',
    'notice_approved_pickup_body' => 'Order :number is approved. Come to the office and take the goods.',
    'notice_changed_title' => 'How you get the goods changed',
    'notice_changed_body' => 'Order :number: changed from ":from" to ":to".',
    'notice_changed_body_with_note' => 'Order :number: changed from ":from" to ":to". Note: :note',
    'notice_handed_title' => 'Goods handed over at the office',
    'notice_handed_body' => 'The goods for order :number were handed to you at the office.',

    'flash_approved_pickup' => 'Order approved. The partner collects the goods at the office; no courier is needed.',
    'flash_changed' => 'The way the goods go was changed. The partner was told.',
    'flash_handed' => 'Goods handed over. The order is delivered.',

    'err_pickup_no_courier' => 'The partner collects this order at the office. To send it by courier, change how the goods go first.',
    'err_same_method' => 'The order is already set this way.',
    'err_change_too_late' => 'Goods for this order were already sent, so this cannot change now.',
    'err_handover_status' => 'Only an approved order can be handed over.',
    'err_handover_nothing' => 'Nothing is left to hand over on this order.',

    'event_order_delivery_changed' => 'How the goods go changed',
    'event_order_office_handover' => 'Goods handed over at the office',
    'changed_from_to' => 'from ":from" to ":to"',
];
