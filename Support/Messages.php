<?php

declare(strict_types=1);

namespace Plugins\Comments\Support;

/**
 * User-facing copy, read from resources/lang/{locale}/messages.php under the
 * `comments` namespace. Same shape as the sibling Payment plugin's
 * `Support\Messages`: the i18n plugin is OPTIONAL. When it is installed its
 * `trans_or()` resolves the key in the active locale; when it is not, the
 * English default passed here is used. Either way `:placeholder` tokens are
 * substituted the same way.
 */
final class Messages
{
    /** @param array<string, string|int|float> $replace */
    public static function get(string $key, string $default, array $replace = []): string
    {
        if (\function_exists('trans_or')) {
            return trans_or('comments::messages.' . $key, $default, $replace);
        }

        if ($replace === []) {
            return $default;
        }

        uksort($replace, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return strtr($default, $pairs);
    }
}
