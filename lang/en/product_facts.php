<?php

/*
 * Per-product facts, health concern tags and product reviews. Kept apart from
 * portal.php and site.php so this work does not clash with other changes there.
 * New keys go at the end.
 */
return [
    // Rows on the public product page.
    'manufacturer' => 'Manufacturer',
    'product_system' => 'Product type',
    'registration_number' => 'DGDA / BSTI registration no.',
    'pack_size' => 'Pack size',
    'ingredients' => 'Ingredients',
    'warnings' => 'Warnings',
    'quality_points' => 'Quality points',

    // Product editor.
    'panel_title' => 'Product facts for the website',
    'panel_hint' => 'Write only what is true for this product and what you can show proof for. Anything left blank stays hidden on the website.',
    'column_bn' => 'Bangla',
    'column_en' => 'English',
    'field_manufacturer' => 'Manufacturer',
    'field_product_system' => 'Product type or system',
    'field_registration_number' => 'DGDA or BSTI registration number',
    'field_pack_size' => 'Pack size',
    'field_ingredients' => 'Ingredients',
    'field_dose' => 'Dose and how to use',
    'field_warnings' => 'Warnings',
    'field_storage' => 'Storage',
    'field_quality_points' => 'Quality points',
    'hint_product_system' => 'For example: Unani, Ayurvedic, herbal, food supplement.',
    'hint_registration_number' => 'Copy it exactly from the certificate. Leave blank if the product has none.',
    'hint_quality_points' => 'One point on each line.',

    // Health concern tags.
    'concerns_title' => 'Health concern tags',
    'concerns_hint' => 'Tick the tags that fit this product. Visitors can browse the catalogue by these tags. Name only the concern, for example "Digestion". Never say a product cures or treats anything.',
    'concerns_empty' => 'No tags yet. Add the first one below.',
    'concern_name_bn' => 'Tag name in Bangla',
    'concern_name_en' => 'Tag name in English',
    'concern_add' => 'Add tag',
    'concern_edit' => 'Edit',
    'concern_save' => 'Save tag',
    'concern_cancel' => 'Cancel',
    'concern_delete' => 'Delete',
    'concern_delete_confirm' => 'Delete the tag ":name"? It will be removed from every product.',
    'flash_concern_added' => 'Tag added.',
    'flash_concern_updated' => 'Tag saved.',
    'flash_concern_deleted' => 'Tag deleted.',
    'err_concern_cure_wording' => 'Please remove any cure or treatment wording. Name only the concern, for example "Digestion".',

    // Reviews.
    'reviews_title' => 'Customer reviews',
    'reviews_empty' => 'No reviews yet. Be the first to write one.',
    'review_form_title' => 'Write a review',
    'review_form_hint' => 'Your review will appear here after the company checks it. Please do not write that a product cured an illness.',
    'review_submit' => 'Send review',
    'review_received' => 'Thank you. Your review will appear after the company checks it.',
    'review_of' => 'Review of :name',
    'moderation_cure_hint' => 'Reject any review that says a product cured or treated an illness.',
    'notice_review_title' => 'New product review',
    'notice_review_body' => ':name wrote a review of :product. It is waiting for your approval.',

    // Website editor.
    'cms_medical_note' => 'General note on product pages',
    'cms_concern_title' => 'Health concern filter title',
];
