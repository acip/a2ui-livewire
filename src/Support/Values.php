<?php

namespace A2uiLivewire\Support;

/**
 * Type coercion rules from the A2UI v1.0 specification.
 */
final class Values
{
    /**
     * Interpolation string conversion: null → "", scalars → standard string,
     * objects/arrays → compact JSON.
     */
    public static function toString(mixed $value): string
    {
        if ($value === null || $value === JsonPointer::MISSING) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            return self::formatFloat($value);
        }

        if (is_array($value) || is_object($value)) {
            return Json::encode($value);
        }

        return (string) $value;
    }

    /**
     * JavaScript-compatible float to string (1.0 → "1", 0.1 → "0.1").
     */
    public static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        if ($value == floor($value) && abs($value) < 1e21) {
            return number_format($value, 0, '.', '');
        }

        $repr = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($repr) ? $repr : (string) $value;
    }

    /**
     * `DynamicBoolean` coercion table (spec: "DynamicBoolean and CheckRule Coercion").
     */
    public static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (self::isValidationResult($value)) {
            return (bool) $value['valid'];
        }

        if (is_string($value)) {
            $lower = strtolower($value);

            return $lower === 'true';
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0 && ! (is_float($value) && is_nan($value));
        }

        return false;
    }

    /**
     * Number coercion used for DynamicNumber properties. Returns null when the
     * value cannot be interpreted as a number.
     */
    public static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return $trimmed + 0;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function toStringList(mixed $value): array
    {
        if ($value === null || $value === JsonPointer::MISSING) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map(static fn ($item) => self::toString($item), $value));
        }

        return [self::toString($value)];
    }

    public static function isValidationResult(mixed $value): bool
    {
        return is_array($value) && array_key_exists('valid', $value) && is_bool($value['valid']);
    }

    /**
     * Normalises a CheckRule condition result into a ValidationResult array.
     *
     * @return array{valid: bool, message?: string, code?: string, severity?: string}
     */
    public static function toValidationResult(mixed $value, ?string $ruleMessage = null, bool $ruleMessageWins = true): array
    {
        if (self::isValidationResult($value)) {
            $result = $value;
        } else {
            $result = ['valid' => self::toBoolean($value)];
        }

        if (! $result['valid']) {
            // An explicit CheckRule.message wins over a function's generic
            // message; for path-bound results the data model's own message is
            // kept and the rule message only fills the gap.
            if ($ruleMessage !== null && $ruleMessage !== '' && ($ruleMessageWins || empty($result['message']))) {
                $result['message'] = $ruleMessage;
            }
            $result['message'] ??= 'Invalid value.';
            $result['severity'] ??= 'error';
        }

        return $result;
    }

    /**
     * True when the value is a JSON object (associative array) as opposed to a list.
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}
