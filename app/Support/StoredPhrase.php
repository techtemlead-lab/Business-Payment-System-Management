<?php

namespace App\Support;

/**
 * Turns a translation key that was saved as itself (the name was missing when the
 * sentence was written) into the words the reader should see now.
 */
final class StoredPhrase
{
    public static function readable(string $text): string
    {
        $readable = preg_replace_callback('/portal\.[a-z0-9_]+/', function (array $match): string {
            $label = __($match[0]);

            return is_string($label) && $label !== $match[0] ? $label : $match[0];
        }, $text);

        return is_string($readable) ? $readable : $text;
    }
}
