<?php

namespace A2uiLivewire\Rendering;

/**
 * Converts between ISO 8601 data-model values and native HTML date/time
 * input values (`date`, `time`, `datetime-local`).
 */
final class DateTimeValues
{
    public static function kind(bool $enableDate, bool $enableTime): string
    {
        return match (true) {
            $enableDate && $enableTime => 'datetime',
            $enableTime => 'time',
            default => 'date',
        };
    }

    public static function inputType(string $kind): string
    {
        return match ($kind) {
            'datetime' => 'datetime-local',
            'time' => 'time',
            default => 'date',
        };
    }

    /**
     * ISO 8601 (from the data model) → HTML input value.
     */
    public static function toInput(string $value, string $kind): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?)?/', $value, $m)) {
            return match ($kind) {
                'date' => $m[1],
                'time' => $m[2] ?? '',
                default => $m[1].'T'.($m[2] ?? '00:00'),
            };
        }

        if ($kind === 'time' && preg_match('/^(\d{2}:\d{2})/', $value, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * HTML input value → ISO 8601 for the data model.
     */
    public static function fromInput(mixed $value, string $kind): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return match ($kind) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            'time' => preg_match('/^(\d{2}:\d{2})(:\d{2})?$/', $value, $m) ? $m[1].($m[2] ?? ':00') : null,
            default => preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2})?$/', $value, $m) ? $m[1].'T'.$m[2].($m[3] ?? ':00') : null,
        };
    }
}
