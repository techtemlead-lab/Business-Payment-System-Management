<?php

return [
    'free_line_label' => 'Free',
    'free_line_scheme' => 'Free with :scheme',

    // Owner page: the free goods a scheme gives.
    'scheme_free_product' => 'Free product (optional)',
    'scheme_free_none' => 'None, show the offer only',
    'scheme_free_quantity' => 'Free quantity',
    'scheme_free_hint' => 'When a dealer buys at least the minimum quantity of these products on one order, this free item is added to the order once, at no charge. Free items earn no commission. Leave it empty if the office will give the benefit by hand.',
    'scheme_free_summary' => ':quantity free: :product',

    // Staff decision on an order with one parcel delivered and another returned.
    'decision_delivered_part' => 'Commission on the delivered part only',
    'decision_no_commission' => 'No commission, money goes to the company profit fund',
    'decision_rule_note' => 'If you choose the delivered part, commission is worked out on the delivered goods only. If you choose no commission, the money goes to the company profit fund with your reason. Free items never earn commission. This cannot be changed afterwards.',
    'err_nothing_paid_delivered' => 'Only free items were delivered on this order, so there is no commission to post. Choose no commission.',
    'err_not_paid_in_full' => 'This order is not fully paid, so commission cannot be posted yet.',
];
