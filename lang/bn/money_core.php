<?php

/*
 * Money-core fixes after the 2026-11-18 company test (stream B). Plain words for every reader.
 */
return [
    // Where commission with nobody to receive it goes (D1, D3, MA-26).
    'reason_no_upline' => 'এই স্তরে কোনো রেফারার নেই, তাই এই অংশ কোম্পানির লাভে গেছে।',
    'reason_two_area_seats' => 'প্লেস কোড :place-এ এই স্তরে দুজন এরিয়া ডিলার আছেন, তাই এই অংশ কাউকে না দিয়ে কোম্পানির লাভে গেছে। অতিরিক্ত আসনটি বন্ধ করুন।',
    'reason_closed_account' => 'বন্ধ অ্যাকাউন্টের আটকে থাকা কমিশন ৩ মাসের বেশি পড়ে ছিল, তাই কোম্পানির লাভে গেছে।',
    'release_reason_reactivated' => 'অ্যাকাউন্ট আবার চালু হয়েছে, আটকে থাকা কমিশন নিজে থেকে দেওয়া হলো।',

    // MA-15: owner switch for paying matured commission on its own.
    'arm_title' => 'অপেক্ষার দিন শেষ হওয়া কমিশন',
    'arm_switch' => 'অপেক্ষার দিন শেষ হলে কমিশন নিজে থেকে পার্টনারের টাকায় যাবে',
    'arm_on' => 'চালু: প্রতি ১৫ মিনিটে নিজে থেকে দেওয়া হয়।',
    'arm_off' => 'বন্ধ: সেটেলমেন্ট পাতায় "এখন দিন" বোতাম চাপলে তবেই দেওয়া হয়।',
    'arm_off_server' => 'বন্ধ (সার্ভারের সেটিং থেকে): সেটেলমেন্ট পাতায় "এখন দিন" বোতাম চাপলে তবেই দেওয়া হয়। এখানে চালু করে সংরক্ষণ করলে এটিই মানা হবে।',
    'arm_owner_only' => 'শুধু মালিক এটি বদলাতে পারেন।',

    // MA-01 and MA-23: month close status on the Settlements page.
    'mc_failed_title' => 'মাসের হিসাব বন্ধ করা যায়নি',
    'mc_failed_line' => ':month-এর :kind নিজে থেকে বন্ধ হয়নি (:error)। প্রতি ঘণ্টায় আবার চেষ্টা হয়; নিচে থেকে নিজেও বন্ধ করতে পারেন।',
    'mc_waiting_title' => 'যে মাসগুলোর হিসাব এখনো বন্ধ হয়নি',
    'mc_waiting_auto' => 'এক ঘণ্টার মধ্যে নিজে থেকে বন্ধ হবে',
    'mc_waiting_person' => 'পুরনো মাস: আপনার অনুমোদন লাগবে',
    'mc_close_button' => 'এই মাস বন্ধ করুন',
    'mc_closed_title' => 'যে মাসগুলোর হিসাব বন্ধ হয়েছে',
    'mc_closed_line' => ':month-এর :kind বন্ধ হয়েছে :date তারিখে। পার্টনারদের দেওয়া হয়েছে ৳:paid; কোম্পানির লাভে গেছে ৳:company।',
    'mc_none_waiting' => 'কোনো মাস অপেক্ষায় নেই।',
    'mc_kind_salary' => 'মাসিক বেতন',
    'mc_kind_royalty' => 'র‍্যাংক রয়্যালটি',
    'mc_kind_area_quota' => 'এরিয়া লক্ষ্যের মাস শেষ',
    'mc_kind_additional_rank' => 'সাব-ডিলার র‍্যাংক ফান্ডের মাস শেষ',
    'mc_month_label' => 'মাস',
    'flash_already_closed' => ':month-এর :kind আগেই :date তারিখে বন্ধ হয়েছে। দেওয়ার মতো আর কিছু বাকি নেই।',
];
