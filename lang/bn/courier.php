<?php

/*
 * কুরিয়ার অটো-বুকিং ও লাইভ ট্র্যাকিং (স্ট্রিম F)। portal.php থেকে আলাদা রাখা হয়েছে।
 * নতুন কী শেষে যোগ করুন।
 */
return [
    'company_decides' => 'কোম্পানি ঠিক করবে',
    'place_code_in_address' => 'প্লেস কোড :code',

    // কুরিয়ারের কাছে পার্সেল কোথায়।
    'status_waiting_pickup' => 'পিকআপের অপেক্ষায়',
    'status_picked_up' => 'পিকআপ হয়েছে',
    'status_in_transit' => 'পথে আছে',
    'status_delivered' => 'ডেলিভারি হয়েছে',
    'status_returned' => 'ফেরত এসেছে',

    // নোটিশ।
    'notice_status_title' => 'অর্ডার :number এর পার্সেলের খবর',
    'notice_status_body' => 'আপনার পার্সেল (:courier · :tracking) এখন: :status।',
    'notice_staff_finished_title' => 'অর্ডার :number এর কুরিয়ার খবর',
    'notice_staff_finished_body_delivered' => ':courier বলছে অর্ডার :number এর পার্সেল ডেলিভারি হয়েছে। দেখে ডেলিভারি নিশ্চিত করুন।',
    'notice_staff_finished_body_returned' => ':courier বলছে অর্ডার :number এর পার্সেল ফেরত এসেছে। কুরিয়ারের সাথে কথা বলুন।',
    'notice_booking_failed_title' => 'কুরিয়ার বুকিং হয়নি: :number',
    'notice_booking_failed_body' => ':courier অর্ডার :number এর পার্সেল নেয়নি (:message)। অর্ডার খুলে আবার চেষ্টা করুন বা কুরিয়ার বদলান।',
    'notice_auto_skipped_title' => 'অর্ডার :number হাতে পাঠাতে হবে',
    'notice_auto_skipped_body_courier_off' => 'ডিলার :courier বেছে নিয়েছেন, কিন্তু ইন্টিগ্রেশনে এই কুরিয়ার বন্ধ। কুরিয়ার বদলান বা হাতে পাঠান।',
    'notice_auto_skipped_body_no_branch' => 'কোনো চালু ব্রাঞ্চে স্টক নেই, তাই অর্ডার :number :courier এ বুক হয়নি। হাতে পাঠান।',
    'notice_auto_skipped_body_stock' => 'ব্রাঞ্চে যথেষ্ট স্টক নেই, তাই অর্ডার :number :courier এ বুক হয়নি। স্টক এলে হাতে পাঠান।',
    'notice_changed_title' => 'অর্ডার :number এর কুরিয়ার বদলানো হয়েছে',
    'notice_changed_body' => 'অর্ডার :number এর কুরিয়ার :from থেকে বদলে :to করা হয়েছে।',
    'notice_changed_body_with_note' => 'অর্ডার :number এর কুরিয়ার :from থেকে বদলে :to করা হয়েছে। নোট: :note',

    // ভুল।
    'err_courier_not_enabled' => 'এই কুরিয়ার চালু নেই। আগে ইন্টিগ্রেশনে চালু করুন।',
    'err_change_after_booking' => 'কুরিয়ার আগেই বুক হয়ে গেছে। বুকিংয়ের আগে বা বুকিং না হলে তবেই বদলানো যায়।',
    'err_change_closed_order' => 'এই অর্ডারের কুরিয়ার আর বদলানো যাবে না।',
    'err_same_courier' => 'এই অর্ডারে এই কুরিয়ারই আছে।',
    'err_retry_not_failed' => 'শুধু যে বুকিং হয়নি সেটাই আবার চেষ্টা করা যায়।',
    'err_pickup_not_waiting' => 'এই পার্সেল পিকআপের অপেক্ষায় নেই।',

    // কাজের পরে বার্তা।
    'flash_changed' => 'কুরিয়ার বদলানো হয়েছে। ডিলারকে জানানো হয়েছে।',
    'flash_retried' => 'কুরিয়ারে আবার বুকিং পাঠানো হয়েছে।',
    'flash_picked_up' => 'পিকআপ হয়েছে বলে রাখা হলো।',

    // অর্ডার ফর্ম।
    'order_form_label' => 'কুরিয়ার',
    'order_form_hint' => 'আপনার পার্সেলের কুরিয়ার বেছে নিন, অথবা কোম্পানিকে ঠিক করতে দিন।',

    // অর্ডার পাতা: ট্র্যাকিং।
    'tracking_title' => 'পার্সেল ট্র্যাকিং',
    'chosen_courier' => 'বেছে নেওয়া কুরিয়ার',
    'tracking_number' => 'ট্র্যাকিং নম্বর',
    'copy' => 'কপি',
    'copied' => 'কপি হয়েছে',
    'open_courier_page' => 'কুরিয়ারের ওয়েবসাইটে দেখুন',
    'last_update' => 'শেষ খবর',
    'last_checked' => 'কুরিয়ারের কাছে শেষ খোঁজ',
    'courier_said' => 'কুরিয়ার বলছে: :text',
    'waiting_for_booking' => 'অর্ডার অনুমোদন হলে পার্সেল কুরিয়ারে বুক হবে।',
    'booking_pending' => 'কুরিয়ারে বুকিং হচ্ছে। ট্র্যাকিং নম্বর একটু পরে এখানে দেখা যাবে।',
    'booking_failed' => 'কুরিয়ার এই বুকিং নেয়নি। আমাদের টিম ঠিক করছে।',
    'booking_failed_staff' => 'কুরিয়ার এই বুকিং নেয়নি: :message',
    'changed_from_to' => 'কুরিয়ার :from থেকে বদলে :to করা হয়েছে',
    'changed_note' => 'নোট: :note',
    'retry' => 'আবার বুক করুন',
    'mark_picked_up' => 'পিকআপ হয়েছে',
    'change_title' => 'কুরিয়ার বদলান',
    'change_hint' => 'বুকিংয়ের আগে, অথবা কুরিয়ার পার্সেল না নিলে এটা ব্যবহার করুন।',
    'change_label' => 'নতুন কুরিয়ার',
    'change_note_label' => 'ডিলারের জন্য নোট (ইচ্ছা হলে)',
    'change_note_hint' => 'যেমন: আজ এই কুরিয়ার বন্ধ, বা আপনার এলাকায় যায় না।',
    'change_submit' => 'কুরিয়ার বদলান',
    'no_other_courier' => 'আর কোনো কুরিয়ার চালু নেই।',

    // অর্ডারের ইতিহাস।
    'event_order_courier_changed' => 'কুরিয়ার বদলানো হয়েছে',
    'event_order_courier_booking_retried' => 'আবার কুরিয়ার বুকিং চেষ্টা',
    'event_order_courier_auto_skipped' => 'নিজে থেকে কুরিয়ার বুক হয়নি',
    'event_shipment_courier_status' => 'পার্সেলের অবস্থা বদলেছে',

    // ডেলিভারি বোর্ড।
    'board_title' => 'কুরিয়ার পার্সেল',
    'board_hint' => 'কুরিয়ারে বুক করা সব পার্সেল। ধাপ বা কুরিয়ার দিয়ে বাছাই করুন।',
    'filter_status' => 'ধাপ',
    'filter_courier' => 'কুরিয়ার',
    'filter_all' => 'সব',
    'filter_booking' => 'এখনো বুক হয়নি',
    'filter_apply' => 'দেখুন',
    'failed_title' => 'যে বুকিং হয়নি',
    'failed_hint' => 'কুরিয়ার এই পার্সেলগুলো নেয়নি। অর্ডার খুলে আবার চেষ্টা করুন বা কুরিয়ার বদলান।',
    'failed_empty' => 'কোনো ব্যর্থ বুকিং নেই।',
    'board_empty' => 'মিলে যায় এমন কুরিয়ার পার্সেল নেই।',
    'col_order' => 'অর্ডার',
    'col_partner' => 'ডিলার',
    'col_courier' => 'কুরিয়ার',
    'col_tracking' => 'ট্র্যাকিং',
    'col_status' => 'ধাপ',
    'col_updated' => 'আপডেট',
    'col_problem' => 'সমস্যা',
    'open_order' => 'খুলুন',

    // হাতে পাঠানোর ফর্ম।
    'dispatch_provider_label' => 'কুরিয়ারে বুক করুন (ইচ্ছা হলে)',
    'dispatch_provider_none' => 'না, আমি ট্র্যাকিং নম্বর লিখব',

    // কুরিয়ারের পরিষ্কার উত্তর পাওয়ার আগেই বুকিং থেমে গেলে।
    'booking_outcome_unknown' => 'কুরিয়ার থেকে পরিষ্কার উত্তর আসেনি, তাই পার্সেলটি হয়তো আগেই বুক হয়ে গেছে। আগে কুরিয়ারের নিজের প্যানেলে দেখে নিন। সেখানে থাকলে আবার বুক করবেন না।',

    // কুরিয়ার যে পার্সেল ফেরত দিয়েছে।
    'returned_cannot_confirm' => 'কুরিয়ার এই পার্সেলটি ফেরত দিয়েছে, তাই এটি ডেলিভারি হয়েছে বলে নিশ্চিত করা যাবে না। ফেরত আসা পার্সেলের জন্য কোনো কমিশন দেওয়া হয় না।',
    'received_back_action' => 'পার্সেল ফেরত পেয়েছি',
    'received_back_note_label' => 'নোট (ইচ্ছা হলে, শুধু অফিসের জন্য)',
    'received_back_hint' => 'পার্সেলটি শাখায় হাতে পেলে এটি চাপুন। পণ্য আবার স্টকে যোগ হবে, আর এই অর্ডারে অন্য কিছু বাকি না থাকলে অর্ডারটি বন্ধ হবে।',
    'received_back_money_note' => 'এই অর্ডারে ডিলার কোনো টাকা দিয়ে থাকলে তা সিস্টেমের বাইরে হাতে ফেরত দিতে হবে।',
    'received_back_reason' => 'কুরিয়ার পার্সেলটি ফেরত দিয়েছে এবং আমরা তা হাতে পেয়েছি।',
    'err_received_back_not_returned' => 'কুরিয়ার ফেরত দিয়েছে বলে জানানো পার্সেলই শুধু ফেরত নেওয়া যাবে।',
    'flash_received_back' => 'পার্সেল ফেরত নেওয়া হয়েছে এবং পণ্য স্টকে যোগ হয়েছে।',
    'notice_received_back_title' => 'পার্সেল ফেরত এসেছে: :number',
    'notice_received_back_body' => 'অর্ডার :number এর পার্সেলটি আমাদের কাছে ফেরত এসেছে। এই অর্ডার নিয়ে জানতে অফিসে যোগাযোগ করুন।',
    'event_shipment_received_back' => 'পার্সেল ফেরত পাওয়া গেছে',
    // ডেলিভারি বোর্ড: একটি পার্সেলের ধাপ।
    'step_booking_failed' => 'বুকিং হয়নি',
];
