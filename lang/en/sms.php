<?php

return [
    'login_otp' => 'Madina login code: :code. Valid for :minutes minutes. Do not share.',
    'account_created' => 'Madina account created. Username: :username Password: :password. Please sign in and change your password.',
    'account_created_redacted' => 'Madina account created. Username: :username Password: [REDACTED]. Please sign in and change your password.',
    'support_requested' => 'Madina: your support request (:topic) was received. The company will follow up.',
    'withdrawal_requested' => 'Madina: withdrawal of BDT :amount via :channel was requested and reserved for review.',
    'withdrawal_approved' => 'Madina: your withdrawal of BDT :amount via :channel was approved and awaits manual payment.',
    'withdrawal_rejected' => 'Madina: your withdrawal of BDT :amount via :channel was rejected. Reserved funds were released.',
    'withdrawal_paid' => 'Madina: BDT :amount via :channel was recorded as paid. Receipt is in your withdrawal history.',
    'commission_settled' => 'Madina: BDT :amount credited for :kind (:month). Check your earnings.',
    'order_submitted' => 'Madina: order :number was submitted and awaits review.',
    'order_decision' => 'Madina: order :number is now :status. :reason',
    'payment_reviewed' => 'Madina: payment for order :number was :outcome.',
    'shipment_dispatched' => 'Madina: order :number shipped via :courier. Tracking: :tracking.',
    'delivery_confirmed' => 'Madina: order :number delivery was confirmed.',
    'err_otp_expired' => 'The login code expired. Sign in again to receive a new code.',
    'err_otp_too_many' => 'Too many incorrect codes. Sign in again.',
    'err_otp_incorrect' => 'That login code is incorrect.',
];
