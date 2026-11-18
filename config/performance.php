<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slow query log
    |--------------------------------------------------------------------------
    |
    | Any single database query that takes longer than this many milliseconds is written to the
    | log with its SQL (never the bound values, which can hold personal data) and where it was
    | called from. Set to 0 to switch the log off.
    |
    */

    'slow_query_ms' => (int) env('SLOW_QUERY_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Lazy loading guard
    |--------------------------------------------------------------------------
    |
    | Outside production a relationship that is loaded one row at a time (the classic N+1) is
    | reported: "log" writes a warning, "throw" fails the request or test so the missing eager
    | load is fixed, "off" disables the check. Production never checks, so a missed case there
    | only costs speed and never breaks a page.
    |
    */

    'lazy_loading' => env('LAZY_LOADING_GUARD', 'log'),

];
