<?php

return [
    'provider_sundarban' => 'সুন্দরবন কুরিয়ার',
    'provider_sa_paribahan' => 'এস এ পরিবহন',
    'provider_karatoa' => 'করতোয়া কুরিয়ার (KCS)',
    'provider_janani' => 'জননী এক্সপ্রেস',
    'provider_continental' => 'কন্টিনেন্টাল কুরিয়ার',

    // যেসব কুরিয়ারে স্বয়ংক্রিয় বুকিং নেই।
    'err_manual_not_enabled' => 'এই কুরিয়ারটি ইন্টিগ্রেশনে বন্ধ করা আছে।',
    'err_not_manual_parcel' => 'শুধু ম্যানুয়াল কুরিয়ারে পাঠানো পার্সেলই হাতে আপডেট করা যাবে।',
    'err_manual_status_backwards' => 'পার্সেল শুধু সামনের ধাপে যেতে পারে। পরের কোনো ধাপ বেছে নিন।',
    'err_manual_nothing' => 'নতুন ধাপ বেছে নিন অথবা কনসাইনমেন্ট নম্বর লিখুন।',
    'notice_manual_dispatch_title' => 'হাতে পাঠাতে হবে: :number',
    'notice_manual_dispatch_body' => 'অর্ডার :number এ :courier বেছে নেওয়া হয়েছে। এই কুরিয়ারে স্বয়ংক্রিয় বুকিং নেই, তাই পার্সেল পাঠিয়ে অর্ডারে কনসাইনমেন্ট নম্বর লিখে দিন।',
    'manual_hint' => 'এই কুরিয়ারে স্বয়ংক্রিয় বুকিং নেই। কুরিয়ার থেকে খবর পেলে ধাপটি নিজে আপডেট করুন।',
    'manual_status_label' => 'নতুন ধাপ',
    'manual_tracking_label' => 'কনসাইনমেন্ট নম্বর',
    'manual_submit' => 'আপডেট সেভ করুন',
    'flash_manual_updated' => 'পার্সেল আপডেট হয়েছে।',

    // একই অর্ডারে একটি পার্সেল ডেলিভারি হয়েছে, আরেকটি ফেরত এসেছে।
    'decision_title' => 'অফিসের সিদ্ধান্ত দরকার',
    'decision_hint' => 'এসব অর্ডারে একটি পার্সেল ডেলিভারি হয়েছে, আরেকটি ফেরত এসেছে। আগে ডিলারের সাথে কথা বলুন, তারপর কমিশনের সিদ্ধান্ত নিন এবং কারণ লিখুন।',
    'decision_empty' => 'কোনো অর্ডার সিদ্ধান্তের অপেক্ষায় নেই।',
    'decision_col_order' => 'অর্ডার',
    'decision_col_partner' => 'ডিলার',
    'decision_col_action' => 'সিদ্ধান্ত',
    'decision_choice_label' => 'কমিশন',
    'decision_reason_label' => 'কারণ (ডিলারের সাথে কথা বলার পর)',
    'decision_submit' => 'সিদ্ধান্ত সেভ করুন',
    'decision_view_only' => 'অর্ডার যাচাইয়ের অনুমতি আছে এমন কাউকে এই সিদ্ধান্ত লিখতে হবে।',
    'err_decision_not_needed' => 'এই অর্ডারে সিদ্ধান্তের দরকার নেই।',
    'err_decision_already' => 'এই অর্ডারের সিদ্ধান্ত আগেই লেখা হয়েছে।',
    'flash_decision_saved' => 'সিদ্ধান্ত সেভ হয়েছে।',
    'notice_decision_title' => 'অর্ডার :number এর সিদ্ধান্ত',
    'notice_decision_body' => 'আপনার অর্ডার :number এ একটি পার্সেল ডেলিভারি হয়েছে এবং আরেকটি ফেরত এসেছে। কমিশনের বিষয়ে অফিসের সিদ্ধান্ত: :decision। কারণ: :reason',
];
