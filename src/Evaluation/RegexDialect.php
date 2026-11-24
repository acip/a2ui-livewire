<?php

/*
 * Portions of this file are ported from the A2UI project
 * (https://github.com/a2ui-project/a2ui), typescript/core/web_core/src/common/regex_subset.ts,
 * Copyright 2024 Google LLC, licensed under the Apache License, Version 2.0.
 * Modified: translated from TypeScript to PHP and adapted to this package.
 */

namespace A2uiLivewire\Evaluation;

use A2uiLivewire\Exceptions\A2uiException;

/**
 * The A2UI portable regex dialect (spec: "The regex function").
 *
 * Parses a pattern into an AST, applies the four static ReDoS safety rules,
 * then compiles the AST to an equivalent PCRE pattern. Compiling from the AST
 * (instead of handing the raw pattern to PCRE) guarantees the dialect's
 * semantics: ASCII-only \d \w \b, `.` excluding line terminators, and `^`/`$`
 * matching only at the ends of the value.
 */
final class RegexDialect
{
    public const MAX_PATTERN_CODE_POINTS = 256;
    public const MAX_VALUE_CODE_POINTS = 4096;
    private const MAX_QUANTIFIER_BOUND = 65535;
    private const MAX_CODE_POINT = 0x10FFFF;
    private const SURROGATE_START = 0xD800;
    private const SURROGATE_END = 0xDFFF;

    /** @var array<string, string> compiled PCRE patterns keyed by dialect pattern */
    private static array $cache = [];

    /** @var list<int> */
    private array $cps = [];

    private int $pos = 0;

    /**
     * Tests `$value` against `$pattern` with search semantics.
     *
     * @throws A2uiException (EXPRESSION_ERROR) for unsupported or unsafe patterns.
     */
    public static function test(string $pattern, string $value): bool
    {
        $compiled = self::compile($pattern);

        if (mb_strlen($value) > self::MAX_VALUE_CODE_POINTS) {
            return false;
        }

        $result = @preg_match($compiled, $value);

        if ($result === false) {
            // Invalid UTF-8 input or a PCRE resource limit.
            return false;
        }

        return $result === 1;
    }

    /**
     * Returns the PCRE pattern equivalent to a dialect pattern.
     *
     * @throws A2uiException
     */
    public static function compile(string $pattern): string
    {
        if (isset(self::$cache[$pattern])) {
            return self::$cache[$pattern];
        }

        $tree = self::analyze($pattern);
        $compiled = '/'.self::emit($tree).'/u';

        if (count(self::$cache) > 256) {
            array_shift(self::$cache);
        }

        return self::$cache[$pattern] = $compiled;
    }

    /**
     * Parses and safety-checks a pattern, returning its AST.
     *
     * @throws A2uiException
     */
    public static function analyze(string $pattern): array
    {
        if (mb_strlen($pattern) > self::MAX_PATTERN_CODE_POINTS) {
            throw A2uiException::expression('Invalid or unsafe regex pattern (PATTERN_TOO_LONG): pattern exceeds '.self::MAX_PATTERN_CODE_POINTS.' code points');
        }

        if (! mb_check_encoding($pattern, 'UTF-8')) {
            throw A2uiException::expression('Invalid or unsafe regex pattern (UNSUPPORTED_SYNTAX): invalid UTF-8');
        }

        $parser = new self;
        $parser->cps = array_map('mb_ord', mb_str_split($pattern));
        $parser->pos = 0;

        try {
            $tree = $parser->parseDisjunction();
            if ($parser->pos < count($parser->cps)) {
                throw new \InvalidArgumentException('Unmatched closing parenthesis');
            }
        } catch (\InvalidArgumentException $e) {
            throw A2uiException::expression("Invalid or unsafe regex pattern (UNSUPPORTED_SYNTAX): {$e->getMessage()}");
        }

        $rejection = match (true) {
            self::hasNestedQuantifier($tree) => 'NESTED_QUANTIFIER',
            self::hasOverlappingAlternation($tree) => 'OVERLAPPING_ALTERNATION',
            self::hasAdjacentOverlap($tree) => 'ADJACENT_OVERLAP',
            default => null,
        };

        if ($rejection !== null) {
            throw A2uiException::expression("Invalid or unsafe regex pattern ({$rejection}): {$pattern}");
        }

        return $tree;
    }

    // ------------------------------------------------------------------
    // Parser
    // ------------------------------------------------------------------

    private function eof(): bool
    {
        return $this->pos >= count($this->cps);
    }

    private function peek(int $offset = 0): ?int
    {
        return $this->cps[$this->pos + $offset] ?? null;
    }

    private function next(): int
    {
        if ($this->eof()) {
            throw new \InvalidArgumentException('Unexpected end of pattern');
        }

        return $this->cps[$this->pos++];
    }

    private function eat(int $cp): bool
    {
        if (($this->cps[$this->pos] ?? null) === $cp) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function parseDisjunction(): array
    {
        $branches = [$this->parseAlternative()];
        while ($this->eat(0x7C)) {
            $branches[] = $this->parseAlternative();
        }

        return count($branches) === 1 ? $branches[0] : ['kind' => 'alt', 'branches' => $branches];
    }

    private function parseAlternative(): array
    {
        $items = [];
        while (! $this->eof() && $this->peek() !== 0x7C && $this->peek() !== 0x29) {
            $items[] = $this->parseTerm();
        }

        return match (count($items)) {
            0 => ['kind' => 'empty'],
            1 => $items[0],
            default => ['kind' => 'concat', 'items' => $items],
        };
    }

    private function parseTerm(): array
    {
        $atom = $this->parseAtom();

        if (! self::isQuantifierStart($this->peek())) {
            return $atom;
        }

        if ($atom['kind'] === 'anchor' || $atom['kind'] === 'lookahead') {
            throw new \InvalidArgumentException('Quantifier on an assertion is not supported');
        }

        return $this->parseQuantifier($atom);
    }

    private static function isQuantifierStart(?int $cp): bool
    {
        return $cp === 0x2A || $cp === 0x2B || $cp === 0x3F || $cp === 0x7B;
    }

    private function parseQuantifier(array $child): array
    {
        $q = $this->next();
        [$min, $max] = match ($q) {
            0x2A => [0, null],
            0x2B => [1, null],
            0x3F => [0, 1],
            default => $this->parseBracedBounds(),
        };

        $lazy = $this->eat(0x3F);

        if (self::isQuantifierStart($this->peek())) {
            throw new \InvalidArgumentException('Possessive or chained quantifiers are not supported');
        }

        return ['kind' => 'repeat', 'child' => $child, 'min' => $min, 'max' => $max, 'lazy' => $lazy];
    }

    private function parseBracedBounds(): array
    {
        $min = $this->parseDecimal();
        if ($min === null) {
            throw new \InvalidArgumentException('Malformed brace quantifier');
        }
        if ($min > self::MAX_QUANTIFIER_BOUND) {
            throw new \InvalidArgumentException('Quantifier lower bound exceeds '.self::MAX_QUANTIFIER_BOUND);
        }
        if ($this->eat(0x7D)) {
            return [$min, $min];
        }
        if (! $this->eat(0x2C)) {
            throw new \InvalidArgumentException('Malformed brace quantifier');
        }
        if ($this->eat(0x7D)) {
            return [$min, null];
        }
        $max = $this->parseDecimal();
        if ($max === null || ! $this->eat(0x7D)) {
            throw new \InvalidArgumentException('Malformed brace quantifier');
        }
        if ($max > self::MAX_QUANTIFIER_BOUND) {
            throw new \InvalidArgumentException('Quantifier upper bound exceeds '.self::MAX_QUANTIFIER_BOUND);
        }
        if ($max < $min) {
            throw new \InvalidArgumentException('Quantifier minimum exceeds maximum');
        }

        return [$min, $max];
    }

    private function parseDecimal(): ?int
    {
        if (! self::isDigit($this->peek())) {
            return null;
        }
        $value = 0;
        while (self::isDigit($this->peek())) {
            $value = $value * 10 + ($this->next() - 0x30);
            if ($value > 10_000_000) {
                throw new \InvalidArgumentException('Quantifier bound too large');
            }
        }

        return $value;
    }

    private static function isDigit(?int $cp): bool
    {
        return $cp !== null && $cp >= 0x30 && $cp <= 0x39;
    }

    private function parseAtom(): array
    {
        $cp = $this->next();

        return match (true) {
            $cp === 0x5E => ['kind' => 'anchor', 'anchor' => 'start'],
            $cp === 0x24 => ['kind' => 'anchor', 'anchor' => 'end'],
            $cp === 0x2E => ['kind' => 'charSet', 'ranges' => self::dotRanges()],
            $cp === 0x5B => $this->parseClass(),
            $cp === 0x28 => $this->parseGroup(),
            $cp === 0x5C => $this->escapeAtom(),
            $cp === 0x7B || $cp === 0x7D || $cp === 0x5D => throw new \InvalidArgumentException('Unescaped brace or bracket is not supported'),
            $cp === 0x2A || $cp === 0x2B || $cp === 0x3F => throw new \InvalidArgumentException('Nothing to repeat'),
            default => ['kind' => 'charSet', 'ranges' => [[$cp, $cp]]],
        };
    }

    private function escapeAtom(): array
    {
        $esc = $this->parseEscape(false);

        return match ($esc['kind']) {
            'anchor' => ['kind' => 'anchor', 'anchor' => $esc['anchor']],
            'ranges' => ['kind' => 'charSet', 'ranges' => $esc['ranges']],
            default => ['kind' => 'charSet', 'ranges' => [[$esc['cp'], $esc['cp']]]],
        };
    }

    private function parseGroup(): array
    {
        if (! $this->eat(0x3F)) {
            $body = $this->parseDisjunction();
            if (! $this->eat(0x29)) {
                throw new \InvalidArgumentException('Unclosed group');
            }

            return ['kind' => 'group', 'capturing' => true, 'child' => $body];
        }

        $modifier = $this->peek();
        if ($modifier !== 0x3A && $modifier !== 0x3D && $modifier !== 0x21) {
            throw new \InvalidArgumentException('Unsupported group extension');
        }
        $this->next();
        $body = $this->parseDisjunction();
        if (! $this->eat(0x29)) {
            throw new \InvalidArgumentException('Unclosed group');
        }

        if ($modifier === 0x3A) {
            return ['kind' => 'group', 'capturing' => false, 'child' => $body];
        }

        return ['kind' => 'lookahead', 'negated' => $modifier === 0x21, 'child' => $body];
    }

    private function parseClass(): array
    {
        $negated = $this->eat(0x5E);
        $ranges = [];

        while (! $this->eof() && $this->peek() !== 0x5D) {
            array_push($ranges, ...$this->parseClassItem());
        }

        if (! $this->eat(0x5D)) {
            throw new \InvalidArgumentException('Unclosed character class');
        }

        if ($ranges === []) {
            throw new \InvalidArgumentException('Empty character class');
        }

        $normalized = self::normalize($ranges);

        return ['kind' => 'charSet', 'ranges' => $negated ? self::complement($normalized) : $normalized];
    }

    private function parseClassItem(): array
    {
        $first = $this->parseClassAtom();

        if ($this->peek() !== 0x2D || $this->peek(1) === 0x5D) {
            return $first['ranges'];
        }

        $this->next();

        if ($this->peek() === 0x2D) {
            throw new \InvalidArgumentException('Character class subtraction is not supported');
        }

        $second = $this->parseClassAtom();

        if ($first['single'] === null || $second['single'] === null) {
            throw new \InvalidArgumentException('Character class escape cannot form a range');
        }

        if ($first['single'] > $second['single']) {
            throw new \InvalidArgumentException('Character class range out of order');
        }

        return [[$first['single'], $second['single']]];
    }

    private function parseClassAtom(): array
    {
        $cp = $this->peek();

        if ($cp === 0x5C) {
            $this->next();
            $esc = $this->parseEscape(true);
            if ($esc['kind'] === 'single') {
                return ['single' => $esc['cp'], 'ranges' => [[$esc['cp'], $esc['cp']]]];
            }
            if ($esc['kind'] === 'ranges') {
                return ['single' => null, 'ranges' => $esc['ranges']];
            }
            throw new \InvalidArgumentException('Assertions are not valid in a character class');
        }

        if ($cp === 0x5B) {
            throw new \InvalidArgumentException('Nested character class is not supported');
        }

        if ($cp === 0x26 && $this->peek(1) === 0x26) {
            throw new \InvalidArgumentException('Character class intersection is not supported');
        }

        $literal = $this->next();

        return ['single' => $literal, 'ranges' => [[$literal, $literal]]];
    }

    private function parseEscape(bool $inClass): array
    {
        if ($this->eof()) {
            throw new \InvalidArgumentException('Trailing backslash in pattern');
        }

        $cp = $this->next();

        if ($cp === 0x62) { // b
            return $inClass ? ['kind' => 'single', 'cp' => 0x08] : ['kind' => 'anchor', 'anchor' => 'wordBoundary'];
        }

        if ($cp === 0x42) { // B
            if ($inClass) {
                throw new \InvalidArgumentException('\\B is not supported inside a character class');
            }

            return ['kind' => 'anchor', 'anchor' => 'notWordBoundary'];
        }

        $classRanges = match ($cp) {
            0x64 => self::digitRanges(),
            0x44 => self::complement(self::digitRanges()),
            0x77 => self::wordRanges(),
            0x57 => self::complement(self::wordRanges()),
            0x73 => self::spaceRanges(),
            0x53 => self::complement(self::spaceRanges()),
            default => null,
        };

        if ($classRanges !== null) {
            return ['kind' => 'ranges', 'ranges' => $classRanges];
        }

        $control = [0x74 => 0x09, 0x6E => 0x0A, 0x76 => 0x0B, 0x66 => 0x0C, 0x72 => 0x0D][$cp] ?? null;

        if ($control !== null) {
            return ['kind' => 'single', 'cp' => $control];
        }

        if ($cp === 0x30) {
            if (self::isDigit($this->peek())) {
                throw new \InvalidArgumentException('Octal escape is not supported');
            }

            return ['kind' => 'single', 'cp' => 0];
        }

        if ($cp >= 0x31 && $cp <= 0x39) {
            throw new \InvalidArgumentException('Backreference or octal escape is not supported');
        }

        if ($cp === 0x78) { // x
            return ['kind' => 'single', 'cp' => $this->parseFixedHex(2)];
        }

        if ($cp === 0x75) { // u
            return ['kind' => 'single', 'cp' => $this->parseUnicodeEscape()];
        }

        $syntax = [0x5E, 0x24, 0x5C, 0x2E, 0x2A, 0x2B, 0x3F, 0x28, 0x29, 0x5B, 0x5D, 0x7B, 0x7D, 0x7C, 0x2F];

        if (in_array($cp, $syntax, true) || ($inClass && $cp === 0x2D)) {
            return ['kind' => 'single', 'cp' => $cp];
        }

        throw new \InvalidArgumentException('Unsupported escape sequence');
    }

    private function parseFixedHex(int $digits): int
    {
        $value = 0;
        for ($i = 0; $i < $digits; $i++) {
            $hv = self::hexValue($this->peek());
            if ($hv < 0) {
                throw new \InvalidArgumentException('Invalid hexadecimal escape');
            }
            $this->next();
            $value = ($value << 4) | $hv;
        }

        return $value;
    }

    private function parseUnicodeEscape(): int
    {
        if (! $this->eat(0x7B)) {
            $cp = $this->parseFixedHex(4);
            if ($cp >= self::SURROGATE_START && $cp <= self::SURROGATE_END) {
                throw new \InvalidArgumentException('Surrogate code point escape is not supported');
            }

            return $cp;
        }

        $value = 0;
        $count = 0;
        while (! $this->eof() && $this->peek() !== 0x7D) {
            $hv = self::hexValue($this->peek());
            if ($hv < 0) {
                throw new \InvalidArgumentException('Invalid braced Unicode escape');
            }
            $this->next();
            $value = $value * 16 + $hv;
            $count++;
            if ($value > self::MAX_CODE_POINT) {
                throw new \InvalidArgumentException('Unicode escape exceeds U+10FFFF');
            }
        }

        if ($count === 0 || ! $this->eat(0x7D)) {
            throw new \InvalidArgumentException('Malformed braced Unicode escape');
        }

        if ($value >= self::SURROGATE_START && $value <= self::SURROGATE_END) {
            throw new \InvalidArgumentException('Surrogate code point escape is not supported');
        }

        return $value;
    }

    private static function hexValue(?int $cp): int
    {
        return match (true) {
            $cp === null => -1,
            $cp >= 0x30 && $cp <= 0x39 => $cp - 0x30,
            $cp >= 0x41 && $cp <= 0x46 => $cp - 0x41 + 10,
            $cp >= 0x61 && $cp <= 0x66 => $cp - 0x61 + 10,
            default => -1,
        };
    }

    // ------------------------------------------------------------------
    // Character sets
    // ------------------------------------------------------------------

    private static function digitRanges(): array
    {
        return [[0x30, 0x39]];
    }

    private static function wordRanges(): array
    {
        return [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]];
    }

    private static function spaceRanges(): array
    {
        return [[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0], [0x1680, 0x1680], [0x2000, 0x200A], [0x2028, 0x2029], [0x202F, 0x202F], [0x205F, 0x205F], [0x3000, 0x3000], [0xFEFF, 0xFEFF]];
    }

    private static function dotRanges(): array
    {
        return self::complement([[0x0A, 0x0A], [0x0D, 0x0D], [0x2028, 0x2029]]);
    }

    private static function excludeSurrogates(array $r): array
    {
        [$start, $end] = $r;
        if ($start > $end) {
            return [];
        }
        if ($end < self::SURROGATE_START || $start > self::SURROGATE_END) {
            return [$r];
        }
        $out = [];
        if ($start < self::SURROGATE_START) {
            $out[] = [$start, self::SURROGATE_START - 1];
        }
        if ($end > self::SURROGATE_END) {
            $out[] = [self::SURROGATE_END + 1, $end];
        }

        return $out;
    }

    private static function normalize(array $ranges): array
    {
        $split = [];
        foreach ($ranges as $r) {
            array_push($split, ...self::excludeSurrogates($r));
        }

        if (count($split) <= 1) {
            return $split;
        }

        usort($split, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [$split[0]];

        for ($i = 1, $n = count($split); $i < $n; $i++) {
            $prev = &$merged[count($merged) - 1];
            if ($split[$i][0] <= $prev[1] + 1) {
                $prev[1] = max($prev[1], $split[$i][1]);
            } else {
                $merged[] = $split[$i];
            }
            unset($prev);
        }

        return $merged;
    }

    private static function complement(array $ranges): array
    {
        $excluded = self::normalize([...$ranges, [self::SURROGATE_START, self::SURROGATE_END]]);
        // normalize() strips surrogates, so re-add the surrogate block as an exclusion.
        $excluded = self::mergeRaw([...$excluded, [self::SURROGATE_START, self::SURROGATE_END]]);
        $result = [];
        $cursor = 0;

        foreach ($excluded as [$start, $end]) {
            if ($start > $cursor) {
                $result[] = [$cursor, $start - 1];
            }
            $cursor = $end + 1;
        }

        if ($cursor <= self::MAX_CODE_POINT) {
            $result[] = [$cursor, self::MAX_CODE_POINT];
        }

        return $result;
    }

    private static function mergeRaw(array $ranges): array
    {
        usort($ranges, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];
        foreach ($ranges as $r) {
            $last = count($merged) - 1;
            if ($last >= 0 && $r[0] <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }

        return $merged;
    }

    private static function overlap(array $a, array $b): bool
    {
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            if ($a[$i][1] < $b[$j][0]) {
                $i++;
            } elseif ($b[$j][1] < $a[$i][0]) {
                $j++;
            } else {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Safety analysis
    // ------------------------------------------------------------------

    private static function unwrap(array $node): array
    {
        while ($node['kind'] === 'group') {
            $node = $node['child'];
        }

        return $node;
    }

    private static function nullable(array $node): bool
    {
        return match ($node['kind']) {
            'empty', 'anchor', 'lookahead' => true,
            'charSet' => false,
            'group' => self::nullable($node['child']),
            'repeat' => $node['min'] === 0 || self::nullable($node['child']),
            'concat' => array_reduce($node['items'], static fn ($carry, $item) => $carry && self::nullable($item), true),
            'alt' => array_reduce($node['branches'], static fn ($carry, $item) => $carry || self::nullable($item), false),
        };
    }

    private static function first(array $node): array
    {
        switch ($node['kind']) {
            case 'empty':
            case 'anchor':
            case 'lookahead':
                return [];
            case 'charSet':
                return $node['ranges'];
            case 'group':
                return self::first($node['child']);
            case 'repeat':
                return $node['max'] === 0 ? [] : self::first($node['child']);
            case 'alt':
                $acc = [];
                foreach ($node['branches'] as $branch) {
                    array_push($acc, ...self::first($branch));
                }

                return self::normalize($acc);
            case 'concat':
                $acc = [];
                foreach ($node['items'] as $item) {
                    array_push($acc, ...self::first($item));
                    if (! self::nullable($item)) {
                        break;
                    }
                }

                return self::normalize($acc);
        }

        return [];
    }

    private static function chars(array $node): array
    {
        switch ($node['kind']) {
            case 'empty':
            case 'anchor':
            case 'lookahead':
                return [];
            case 'charSet':
                return $node['ranges'];
            case 'group':
                return self::chars($node['child']);
            case 'repeat':
                return $node['max'] === 0 ? [] : self::chars($node['child']);
            case 'concat':
            case 'alt':
                $acc = [];
                foreach ($node[$node['kind'] === 'concat' ? 'items' : 'branches'] as $child) {
                    array_push($acc, ...self::chars($child));
                }

                return self::normalize($acc);
        }

        return [];
    }

    private static function hasUnbounded(array $node): bool
    {
        return match ($node['kind']) {
            'empty', 'charSet', 'anchor' => false,
            'group', 'lookahead' => self::hasUnbounded($node['child']),
            'repeat' => $node['max'] === null || self::hasUnbounded($node['child']),
            'concat' => self::any($node['items'], self::hasUnbounded(...)),
            'alt' => self::any($node['branches'], self::hasUnbounded(...)),
        };
    }

    private static function hasNestedQuantifier(array $node): bool
    {
        return match ($node['kind']) {
            'empty', 'charSet', 'anchor' => false,
            'group', 'lookahead' => self::hasNestedQuantifier($node['child']),
            'repeat' => (($node['max'] === null || $node['max'] > 1) && self::hasUnbounded($node['child']))
                || self::hasNestedQuantifier($node['child']),
            'concat' => self::any($node['items'], self::hasNestedQuantifier(...)),
            'alt' => self::any($node['branches'], self::hasNestedQuantifier(...)),
        };
    }

    private static function hasOverlappingAlternation(array $node): bool
    {
        switch ($node['kind']) {
            case 'empty':
            case 'charSet':
            case 'anchor':
                return false;
            case 'group':
            case 'lookahead':
                return self::hasOverlappingAlternation($node['child']);
            case 'repeat':
                $inner = self::unwrap($node['child']);
                if ($node['max'] === null && $inner['kind'] === 'alt') {
                    $firstSets = array_map(static fn ($b) => self::first(self::unwrap($b)), $inner['branches']);
                    for ($i = 0; $i < count($firstSets); $i++) {
                        for ($j = $i + 1; $j < count($firstSets); $j++) {
                            if (self::overlap($firstSets[$i], $firstSets[$j])) {
                                return true;
                            }
                        }
                    }
                }

                return self::hasOverlappingAlternation($node['child']);
            case 'concat':
                return self::any($node['items'], self::hasOverlappingAlternation(...));
            case 'alt':
                return self::any($node['branches'], self::hasOverlappingAlternation(...));
        }

        return false;
    }

    private static function hasAdjacentOverlap(array $node): bool
    {
        switch ($node['kind']) {
            case 'empty':
            case 'charSet':
            case 'anchor':
                return false;
            case 'group':
            case 'lookahead':
            case 'repeat':
                return self::hasAdjacentOverlap($node['child']);
            case 'concat':
                $items = $node['items'];
                for ($i = 0; $i < count($items); $i++) {
                    $ux = self::unwrap($items[$i]);
                    if ($ux['kind'] !== 'repeat' || $ux['max'] !== null) {
                        continue;
                    }
                    $xChars = self::chars($ux['child']);
                    for ($j = $i + 1; $j < count($items); $j++) {
                        $uy = self::unwrap($items[$j]);
                        if ($uy['kind'] === 'repeat' && $uy['max'] === null && self::overlap($xChars, self::chars($uy['child']))) {
                            return true;
                        }
                        if (! self::nullable($items[$j])) {
                            break;
                        }
                    }
                }

                return self::any($items, self::hasAdjacentOverlap(...));
            case 'alt':
                return self::any($node['branches'], self::hasAdjacentOverlap(...));
        }

        return false;
    }

    private static function any(array $nodes, callable $predicate): bool
    {
        foreach ($nodes as $node) {
            if ($predicate($node)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // PCRE emission
    // ------------------------------------------------------------------

    private static function emit(array $node): string
    {
        return match ($node['kind']) {
            'empty' => '',
            'charSet' => self::emitSet($node['ranges']),
            'group' => ($node['capturing'] ? '(' : '(?:').self::emit($node['child']).')',
            'lookahead' => ($node['negated'] ? '(?!' : '(?=').self::emit($node['child']).')',
            'anchor' => match ($node['anchor']) {
                'start' => '\A',
                'end' => '\z',
                'wordBoundary' => '(?:(?<=[0-9A-Za-z_])(?![0-9A-Za-z_])|(?<![0-9A-Za-z_])(?=[0-9A-Za-z_]))',
                'notWordBoundary' => '(?:(?<=[0-9A-Za-z_])(?=[0-9A-Za-z_])|(?<![0-9A-Za-z_])(?![0-9A-Za-z_]))',
            },
            'concat' => implode('', array_map(self::emit(...), $node['items'])),
            'alt' => implode('|', array_map(self::emit(...), $node['branches'])),
            'repeat' => self::emitRepeat($node),
        };
    }

    private static function emitRepeat(array $node): string
    {
        $child = $node['child'];
        $inner = self::emit($child);

        if (! in_array($child['kind'], ['charSet', 'group'], true)) {
            $inner = '(?:'.$inner.')';
        }

        $quantifier = match (true) {
            $node['min'] === 0 && $node['max'] === null => '*',
            $node['min'] === 1 && $node['max'] === null => '+',
            $node['min'] === 0 && $node['max'] === 1 => '?',
            $node['max'] === null => '{'.$node['min'].',}',
            $node['min'] === $node['max'] => '{'.$node['min'].'}',
            default => '{'.$node['min'].','.$node['max'].'}',
        };

        return $inner.$quantifier.($node['lazy'] ? '?' : '');
    }

    private static function emitSet(array $ranges): string
    {
        if ($ranges === []) {
            return '(?!)';
        }

        $out = '';
        foreach ($ranges as [$start, $end]) {
            $out .= $start === $end
                ? sprintf('\x{%X}', $start)
                : sprintf('\x{%X}-\x{%X}', $start, $end);
        }

        return '['.$out.']';
    }
}
