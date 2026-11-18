<?php

// Stream D (orders and stock), 2026-11-18. Bangla first; plain words.
return [
    'move_button' => 'Move to another branch',
    'move_title' => ':name: move stock to another branch',
    'move_hint' => 'This sends the goods from one branch to another and keeps both branches\' records matching. When you press Send, the goods leave your branch stock and are on the way. When the other branch presses Receive, they are added to its stock.',
    'move_submit' => 'Request move',
    'move_quantity_hint' => 'This branch has :count now',
    'moving_title' => 'Goods moving between branches',
    'moving_hint' => 'Goods on the way are not counted in any branch stock. They are added when the receiving branch presses Receive.',
    'import_publish_selected' => 'Publish selected products',
    'import_publish_hint' => 'The selected draft products will start showing in the shop. Check the price and photo first.',
    'import_publish_pick' => 'Select products to publish',
    'draft_tag' => 'draft',
    'select_all_shown' => 'Select all on this page',
    'clear_selection' => 'Clear selection',
    'import_publish_done' => ':count products published.',
    'import_publish_skipped' => ':count products could not be published because the price or type is not right.',
    // Stock count approval (F-verify-07)
    'count_now' => 'In stock now',
    'count_moved' => 'Stock changed after the count',
    'count_err_moved' => 'Stock of :count products changed after they were counted. Check those lines and tick the box, or turn the count down and count again.',
    'count_confirm_title' => 'Check before approving',
    'count_confirm_totals' => 'Stock changes on :lines products: :added units more and :removed units fewer in all.',
    'count_confirm_moved' => 'Stock of :count products changed after they were counted (marked yellow). Only the difference between the count and the figure at that time is posted.',
    'count_moved_checked' => 'I have checked the lines that changed',
    'count_confirm_yes' => 'Yes, change the stock',
    'count_confirm_back' => 'Go back',
];
