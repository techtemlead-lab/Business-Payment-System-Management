<?php

return [
    'demo_enabled' => (bool) env('DEMO_ENABLED', false),
    'timezone' => 'Asia/Dhaka',
    // No approved sale workflow, recipient rules, or payout provider exists yet.
    'posting_enabled' => false,
    'provider' => 'unconfigured',
];
