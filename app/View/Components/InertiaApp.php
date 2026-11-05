<?php

namespace App\View\Components;

use Inertia\Ssr\SsrState;
use Inertia\View\Components\App;

/**
 * Same as Inertia's <x-inertia::app />, but the page data keeps Bangla letters as they are
 * instead of \uXXXX escapes, which makes the first page load of a portal much smaller.
 * JSON_HEX_TAG stays on so the JSON can never close its own script tag.
 */
class InertiaApp extends App
{
    public function __construct(string $id = 'app')
    {
        parent::__construct($id);
        if ($this->response === null) {
            $this->pageJson = (string) json_encode(
                app(SsrState::class)->page,
                JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }
    }
}
