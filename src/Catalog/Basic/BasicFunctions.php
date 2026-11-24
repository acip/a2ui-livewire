<?php

/*
 * Portions of this file are ported from the A2UI project
 * (https://github.com/a2ui-project/a2ui), typescript/core/web_core/src/common/basic_functions.ts,
 * Copyright 2024 Google LLC, licensed under the Apache License, Version 2.0.
 * Modified: translated from TypeScript to PHP and adapted to this package.
 */

namespace A2uiLivewire\Catalog\Basic;

use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Evaluation\ExpressionParser;
use A2uiLivewire\Evaluation\FunctionContext;
use A2uiLivewire\Evaluation\RegexDialect;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Support\UrlSanitizer;
use A2uiLivewire\Support\Values;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * Renderer-side implementations of the Basic Catalog functions.
 */
final class BasicFunctions
{
    private const EMAIL = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';

    public static function register(Catalog $catalog): Catalog
    {
        return $catalog
            ->withFunction('required', static fn (array $a) => self::required($a['value'] ?? null))
            ->withFunction('regex', static fn (array $a) => self::regex($a['value'] ?? null, (string) ($a['pattern'] ?? '')))
            ->withFunction('length', static fn (array $a) => self::length($a['value'] ?? null, $a['min'] ?? null, $a['max'] ?? null))
            ->withFunction('numeric', static fn (array $a) => self::numeric($a['value'] ?? null, $a['min'] ?? null, $a['max'] ?? null))
            ->withFunction('email', static fn (array $a) => self::email($a['value'] ?? null))
            ->withFunction('formatString', static fn (array $a, FunctionContext $c) => self::formatString(Values::toString($a['value'] ?? ''), $c))
            ->withFunction('formatNumber', static fn (array $a, FunctionContext $c) => self::formatNumber($a['value'] ?? null, $a['decimals'] ?? null, $a['grouping'] ?? null, $c->locale()))
            ->withFunction('formatCurrency', static fn (array $a, FunctionContext $c) => self::formatCurrency($a['value'] ?? null, (string) ($a['currency'] ?? 'USD'), $a['decimals'] ?? null, $a['grouping'] ?? null, $c->locale()))
            ->withFunction('formatDate', static fn (array $a, FunctionContext $c) => self::formatDate($a['value'] ?? null, Values::toString($a['format'] ?? ''), $c->locale()))
            ->withFunction('pluralize', static fn (array $a, FunctionContext $c) => self::pluralize($a, $c->locale()))
            ->withFunction('openUrl', static fn (array $a, FunctionContext $c) => self::openUrl($a['url'] ?? null, $c))
            ->withFunction('and', static fn (array $a) => self::logical('and', $a['values'] ?? null))
            ->withFunction('or', static fn (array $a) => self::logical('or', $a['values'] ?? null))
            ->withFunction('not', static fn (array $a) => ! Values::toBoolean($a['value'] ?? null));
    }

    // -- Validation --------------------------------------------------------

    public static function required(mixed $value): array
    {
        $valid = ! ($value === null || $value === '' || $value === []);

        return $valid ? ['valid' => true] : ['valid' => false, 'message' => 'This field is required.'];
    }

    public static function regex(mixed $value, string $pattern): array
    {
        $string = Values::toString($value);

        if (mb_strlen($string) > RegexDialect::MAX_VALUE_CODE_POINTS) {
            RegexDialect::compile($pattern);

            return ['valid' => false];
        }

        return ['valid' => RegexDialect::test($pattern, $string)];
    }

    public static function length(mixed $value, mixed $min, mixed $max): array
    {
        $length = match (true) {
            is_string($value) => mb_strlen($value),
            is_array($value) => count($value),
            default => 0,
        };

        $min = Values::toNumber($min);
        $max = Values::toNumber($max);

        if ($min !== null && $length < $min) {
            return ['valid' => false, 'message' => "Minimum length is {$min}."];
        }

        if ($max !== null && $length > $max) {
            return ['valid' => false, 'message' => "Maximum length is {$max}."];
        }

        return ['valid' => true];
    }

    public static function numeric(mixed $value, mixed $min, mixed $max): array
    {
        $number = is_bool($value) ? null : Values::toNumber($value);

        if ($number === null) {
            return ['valid' => false, 'message' => 'Value must be a valid number.'];
        }

        $min = Values::toNumber($min);
        $max = Values::toNumber($max);

        if ($min !== null && $number < $min) {
            return ['valid' => false, 'message' => 'Minimum value is '.Values::toString($min).'.'];
        }

        if ($max !== null && $number > $max) {
            return ['valid' => false, 'message' => 'Maximum value is '.Values::toString($max).'.'];
        }

        return ['valid' => true];
    }

    public static function email(mixed $value): array
    {
        if (is_string($value) && preg_match(self::EMAIL, $value)) {
            return ['valid' => true];
        }

        return [
            'valid' => false,
            'code' => 'INVALID_EMAIL',
            'severity' => 'error',
            'message' => 'Please enter a valid email address (e.g. user@example.com).',
        ];
    }

    // -- Logic ---------------------------------------------------------------

    public static function logical(string $operator, mixed $values): bool
    {
        if (! is_array($values) || count($values) < 2) {
            throw A2uiException::expression("{$operator} requires at least 2 values");
        }

        foreach ($values as $value) {
            $truthy = Values::toBoolean($value);

            if ($operator === 'and' && ! $truthy) {
                return false;
            }

            if ($operator === 'or' && $truthy) {
                return true;
            }
        }

        return $operator === 'and';
    }

    // -- Formatting ----------------------------------------------------------

    /**
     * Interpolates `${...}` expressions. Function calls inside the template
     * resolve against the catalog the `formatString` call itself resolved to
     * and are never routed to the agent.
     */
    public static function formatString(string $template, FunctionContext $context): string
    {
        $parts = (new ExpressionParser)->parse($template);
        $scope = $context->data->forTemplate($context->catalog->id);
        $out = '';

        foreach ($parts as $part) {
            if (is_string($part)) {
                $out .= $part;

                continue;
            }

            // A failing part fails the whole call; the binding resolves to
            // null and the error is reported by the caller.
            $out .= Values::toString($scope->resolve($part));
        }

        return $out;
    }

    public static function formatNumber(mixed $value, mixed $decimals, mixed $grouping, string $locale = 'en-US'): string
    {
        $number = self::requireNumber($value, 'formatNumber');
        $decimals = $decimals === null ? null : (int) Values::toNumber($decimals);
        $grouping = $grouping === null ? true : Values::toBoolean($grouping);

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(self::icuLocale($locale), NumberFormatter::DECIMAL);
            if ($decimals !== null) {
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            }
            $formatter->setAttribute(NumberFormatter::GROUPING_USED, $grouping ? 1 : 0);
            $formatted = $formatter->format($number);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $places = $decimals ?? (floor($number) == $number ? 0 : min(3, strlen(substr(strrchr((string) $number, '.') ?: '.', 1))));

        return number_format($number, $places, '.', $grouping ? ',' : '');
    }

    public static function formatCurrency(mixed $value, string $currency, mixed $decimals, mixed $grouping, string $locale = 'en-US'): string
    {
        $number = self::requireNumber($value, 'formatCurrency');
        $currency = strtoupper($currency);
        $decimals = $decimals === null ? 2 : (int) Values::toNumber($decimals);
        $grouping = $grouping === null ? true : Values::toBoolean($grouping);

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(self::icuLocale($locale), NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::GROUPING_USED, $grouping ? 1 : 0);
            $formatted = $formatter->formatCurrency($number, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];
        $prefix = $symbols[$currency] ?? $currency.' ';
        $sign = $number < 0 ? '-' : '';

        return $sign.$prefix.number_format(abs($number), $decimals, '.', $grouping ? ',' : '');
    }

    /**
     * Formats an ISO 8601 timestamp with Unicode TR35 tokens. The written
     * wall-clock fields (and offset) are kept; impossible dates yield "".
     */
    public static function formatDate(mixed $value, string $pattern, string $locale = 'en-US'): string
    {
        if ($value === null || $value === '' || $value === false) {
            return '';
        }

        $parsed = self::parseTimestamp(Values::toString($value));

        if ($parsed === null) {
            return '';
        }

        [$fields, $instant] = $parsed;
        $pattern = $pattern !== '' ? $pattern : 'yyyy-MM-dd';

        if ($pattern === 'ISO') {
            return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }

        $names = self::dateNames($locale);
        $weekday = (int) (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $fields['y'], $fields['m'], $fields['d']), new DateTimeZone('UTC')))->format('N') - 1;
        $hours = $fields['H'];
        $hours12 = $hours % 12 === 0 ? 12 : $hours % 12;

        return preg_replace_callback(
            '/yyyy|yy|MMMM|MMM|MM|M|EEEE|E|dd|d|HH|H|hh|h|mm|ss|a/',
            static fn (array $m) => match ($m[0]) {
                'yyyy' => (string) $fields['y'],
                'yy' => substr((string) $fields['y'], -2),
                'MMMM' => $names['monthsLong'][$fields['m'] - 1],
                'MMM' => $names['monthsShort'][$fields['m'] - 1],
                'MM' => sprintf('%02d', $fields['m']),
                'M' => (string) $fields['m'],
                'EEEE' => $names['weekdaysLong'][$weekday],
                'E' => $names['weekdaysShort'][$weekday],
                'dd' => sprintf('%02d', $fields['d']),
                'd' => (string) $fields['d'],
                'HH' => sprintf('%02d', $hours),
                'H' => (string) $hours,
                'hh' => sprintf('%02d', $hours12),
                'h' => (string) $hours12,
                'mm' => sprintf('%02d', $fields['i']),
                'ss' => sprintf('%02d', $fields['s']),
                'a' => $hours < 12 ? 'AM' : 'PM',
            },
            $pattern,
        ) ?? '';
    }

    public static function pluralize(array $args, string $locale = 'en-US'): string
    {
        $number = Values::toNumber($args['value'] ?? null) ?? 0;

        $category = match (true) {
            $number == 0 && array_key_exists('zero', $args) => 'zero',
            $number == 1 && array_key_exists('one', $args) => 'one',
            $number == 2 && array_key_exists('two', $args) => 'two',
            default => self::pluralCategory($number, $locale),
        };

        if (! array_key_exists($category, $args)) {
            $category = 'other';
        }

        return Values::toString($args[$category] ?? '');
    }

    public static function openUrl(mixed $url, FunctionContext $context): null
    {
        $resolved = UrlSanitizer::navigation(
            $url,
            $context->option('baseUrl'),
            $context->option('openUrlSchemes', ['http', 'https']),
        );

        if ($resolved === null) {
            throw A2uiException::expression('openUrl rejected URL with a disallowed or invalid scheme: '.Values::toString($url));
        }

        if (! $context->isUserActivated()) {
            throw A2uiException::expression('openUrl requires user activation.');
        }

        $context->effect(['type' => 'openUrl', 'url' => $resolved]);

        return null;
    }

    // -- Helpers -------------------------------------------------------------

    private static function requireNumber(mixed $value, string $function): int|float
    {
        $number = is_bool($value) ? null : Values::toNumber($value);

        if ($number === null) {
            throw A2uiException::expression("{$function} expects a numeric 'value', got ".get_debug_type($value).'.');
        }

        return $number;
    }

    private static function pluralCategory(int|float $number, string $locale): string
    {
        if (class_exists(MessageFormatter::class)) {
            $formatter = MessageFormatter::create(
                self::icuLocale($locale),
                '{0, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}',
            );
            $category = $formatter?->format([$number]);

            if (is_string($category) && $category !== '') {
                return $category;
            }
        }

        return $number == 1 ? 'one' : 'other';
    }

    /**
     * @return array{0: array{y: int, m: int, d: int, H: int, i: int, s: int}, 1: DateTimeImmutable}|null
     */
    private static function parseTimestamp(string $value): ?array
    {
        $pattern = '/^([+-]?\d{4,6})-(\d{2})-(\d{2})(?:[T ](\d{2})(?::(\d{2})(?::(\d{2})(\.\d+)?)?)?)?(Z|[+-]\d{2}:?\d{2})?$/i';

        if (! preg_match($pattern, trim($value), $m)) {
            return null;
        }

        $fields = [
            'y' => (int) $m[1],
            'm' => (int) $m[2],
            'd' => (int) $m[3],
            'H' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0,
            'i' => isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0,
            's' => isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0,
        ];

        if (! checkdate($fields['m'], $fields['d'], $fields['y']) || $fields['H'] > 23 || $fields['i'] > 59 || $fields['s'] > 59) {
            return null;
        }

        $offset = $m[8] ?? '';
        $zone = $offset === '' || strtoupper($offset) === 'Z' ? '+00:00' : (strlen($offset) === 5 ? substr($offset, 0, 3).':'.substr($offset, 3) : $offset);
        $fraction = isset($m[7]) && $m[7] !== '' ? substr($m[7].'000', 1, 3) : '000';

        $instant = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.v P',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d.%s %s', $fields['y'], $fields['m'], $fields['d'], $fields['H'], $fields['i'], $fields['s'], $fraction, $zone),
        );

        return $instant === false ? null : [$fields, $instant];
    }

    /**
     * @return array{monthsLong: list<string>, monthsShort: list<string>, weekdaysLong: list<string>, weekdaysShort: list<string>}
     */
    private static function dateNames(string $locale): array
    {
        static $cache = [];

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $english = [
            'monthsLong' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'monthsShort' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'weekdaysLong' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'weekdaysShort' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ];

        if (str_starts_with(strtolower($locale), 'en') || ! class_exists(IntlDateFormatter::class)) {
            return $cache[$locale] = $english;
        }

        $render = static function (string $pattern, array $dates) use ($locale): array {
            $formatter = new IntlDateFormatter(self::icuLocale($locale), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $pattern);

            return array_map(static fn (DateTimeImmutable $d) => (string) $formatter->format($d), $dates);
        };

        $utc = new DateTimeZone('UTC');
        $months = array_map(static fn (int $i) => new DateTimeImmutable(sprintf('2021-%02d-15', $i), $utc), range(1, 12));
        $weekdays = array_map(static fn (int $i) => new DateTimeImmutable(sprintf('2021-03-%02d', $i), $utc), range(1, 7));

        return $cache[$locale] = [
            'monthsLong' => $render('LLLL', $months),
            'monthsShort' => $render('LLL', $months),
            'weekdaysLong' => $render('EEEE', $weekdays),
            'weekdaysShort' => $render('EEE', $weekdays),
        ];
    }

    private static function icuLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }
}
