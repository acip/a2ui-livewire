<?php

namespace A2uiLivewire\Support;

/**
 * Unicode Standard Annex #31 identifier checks (`^[\p{XID_Start}_][\p{XID_Continue}]*$`).
 *
 * PCRE has no XID properties, so the ID_Start / ID_Continue approximation from
 * UAX #31 is used: letters and letter numbers start an identifier; marks,
 * decimal digits and connector punctuation may continue it.
 */
final class Uax31
{
    private const PATTERN = '/^[\p{L}\p{Nl}_][\p{L}\p{Nl}\p{Mn}\p{Mc}\p{Nd}\p{Pc}]*$/u';

    private const CONTINUE = '/^[\p{L}\p{Nl}\p{Mn}\p{Mc}\p{Nd}\p{Pc}]$/u';

    public static function isIdentifier(string $value): bool
    {
        return $value !== '' && (bool) preg_match(self::PATTERN, $value);
    }

    public static function isContinue(string $char): bool
    {
        return $char !== '' && (bool) preg_match(self::CONTINUE, $char);
    }
}
