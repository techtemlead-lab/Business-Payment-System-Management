<?php

/*
 * Per-product facts, health concern tags and product reviews. Kept apart from
 * portal.php and site.php so this work does not clash with other changes there.
 * New keys go at the end.
 */
return [
    // Rows on the public product page.
    'manufacturer' => 'প্রস্তুতকারক',
    'product_system' => 'পণ্যের ধরন',
    'registration_number' => 'ডিজিডিএ / বিএসটিআই নিবন্ধন নম্বর',
    'pack_size' => 'প্যাকের পরিমাণ',
    'ingredients' => 'উপাদান',
    'warnings' => 'সতর্কতা',
    'quality_points' => 'মানের তথ্য',

    // Product editor.
    'panel_title' => 'ওয়েবসাইটের জন্য পণ্যের তথ্য',
    'panel_hint' => 'এই পণ্যের জন্য যা সত্যি এবং যার প্রমাণ আছে, শুধু সেটুকুই লিখুন। খালি রাখলে ওয়েবসাইটে দেখাবে না।',
    'column_bn' => 'বাংলা',
    'column_en' => 'ইংরেজি',
    'field_manufacturer' => 'প্রস্তুতকারক',
    'field_product_system' => 'পণ্যের ধরন বা পদ্ধতি',
    'field_registration_number' => 'ডিজিডিএ বা বিএসটিআই নিবন্ধন নম্বর',
    'field_pack_size' => 'প্যাকের পরিমাণ',
    'field_ingredients' => 'উপাদান',
    'field_dose' => 'মাত্রা ও ব্যবহারের নিয়ম',
    'field_warnings' => 'সতর্কতা',
    'field_storage' => 'সংরক্ষণের নিয়ম',
    'field_quality_points' => 'মানের তথ্য',
    'hint_product_system' => 'যেমন: ইউনানি, আয়ুর্বেদিক, ভেষজ, ফুড সাপ্লিমেন্ট।',
    'hint_registration_number' => 'সনদ দেখে হুবহু লিখুন। নম্বর না থাকলে খালি রাখুন।',
    'hint_quality_points' => 'প্রতি লাইনে একটি করে কথা লিখুন।',

    // Health concern tags.
    'concerns_title' => 'স্বাস্থ্য বিষয়ের ট্যাগ',
    'concerns_hint' => 'এই পণ্যের সাথে মেলে এমন ট্যাগে টিক দিন। ক্রেতারা ক্যাটালগে এই ট্যাগ দিয়ে পণ্য খুঁজতে পারবেন। শুধু বিষয়ের নাম লিখুন, যেমন "হজম"। পণ্যে রোগ সারে বা চিকিৎসা হয় এমন কথা কখনো লিখবেন না।',
    'concerns_empty' => 'এখনো কোনো ট্যাগ নেই। নিচে প্রথম ট্যাগটি যোগ করুন।',
    'concern_name_bn' => 'ট্যাগের নাম (বাংলা)',
    'concern_name_en' => 'ট্যাগের নাম (ইংরেজি)',
    'concern_add' => 'ট্যাগ যোগ করুন',
    'concern_edit' => 'ঠিক করুন',
    'concern_save' => 'ট্যাগ সেভ করুন',
    'concern_cancel' => 'বাতিল',
    'concern_delete' => 'মুছে ফেলুন',
    'concern_delete_confirm' => '":name" ট্যাগটি মুছবেন? সব পণ্য থেকে এটি উঠে যাবে।',
    'flash_concern_added' => 'ট্যাগ যোগ হয়েছে।',
    'flash_concern_updated' => 'ট্যাগ সেভ হয়েছে।',
    'flash_concern_deleted' => 'ট্যাগ মুছে ফেলা হয়েছে।',
    'err_concern_cure_wording' => 'রোগ সারানো বা চিকিৎসার কথা বাদ দিন। শুধু বিষয়ের নাম লিখুন, যেমন "হজম"।',

    // Reviews.
    'reviews_title' => 'ক্রেতাদের মতামত',
    'reviews_empty' => 'এখনো কোনো মতামত নেই। আপনিই প্রথম লিখুন।',
    'review_form_title' => 'আপনার মতামত লিখুন',
    'review_form_hint' => 'কোম্পানি দেখে নেওয়ার পর আপনার মতামত এখানে দেখাবে। পণ্যে রোগ সেরেছে এমন কথা লিখবেন না।',
    'review_submit' => 'মতামত পাঠান',
    'review_received' => 'ধন্যবাদ। কোম্পানি দেখে নেওয়ার পর আপনার মতামত দেখাবে।',
    'review_of' => ':name পণ্যের মতামত',
    'moderation_cure_hint' => 'যে মতামতে পণ্যে রোগ সেরেছে বা চিকিৎসা হয়েছে বলা আছে, সেটি বাতিল করুন।',
    'notice_review_title' => 'পণ্যের নতুন মতামত',
    'notice_review_body' => ':name ":product" পণ্যের মতামত দিয়েছেন। এটি আপনার অনুমোদনের অপেক্ষায় আছে।',

    // Website editor.
    'cms_medical_note' => 'পণ্যের পাতার সাধারণ নোট',
    'cms_concern_title' => 'স্বাস্থ্য বিষয় ফিল্টারের শিরোনাম',
];
