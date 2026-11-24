<?php

/*
 * Portions of this file are ported from the A2UI project
 * (https://github.com/a2ui-project/a2ui), typescript/core/web_core/src/expressions/expression_parser.ts,
 * Copyright 2024 Google LLC, licensed under the Apache License, Version 2.0.
 * Modified: translated from TypeScript to PHP and adapted to this package.
 */

namespace A2uiLivewire\Evaluation;

use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Support\Uax31;

/**
 * Parses `formatString` templates (`Hello ${/user/name}!`) into a list of
 * literal strings and dynamic values (`{"@path": ...}` / `{"@call": ..., "args": ...}`).
 *
 * Port of the reference `ExpressionParser` in @a2ui/web_core so expressions
 * mean the same thing in every renderer:
 *
 * - `${/abs/path}` and `${rel/path}` are data bindings;
 * - `${fn(arg: value, other: ${/nested})}` are function calls with named args;
 * - quoted strings, numbers, true/false/null are literals;
 * - `\${` escapes a literal `${`.
 */
final class ExpressionParser
{
    public const MAX_DEPTH = 100;
    public const MAX_TEMPLATE_LENGTH = 10_000;
    public const MAX_PARTS = 1_000;

    private const NUMBER_LITERAL = '/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/';

    /** @var list<string> */
    private array $chars = [];

    private int $pos = 0;

    private int $length = 0;

    /**
     * Parses a template into parts.
     *
     * @return list<mixed>
     *
     * @throws A2uiException
     */
    public function parse(string $input, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw A2uiException::expression('Max recursion depth reached in parse');
        }

        if (mb_strlen($input) > self::MAX_TEMPLATE_LENGTH) {
            throw A2uiException::expression('Expression template length ('.mb_strlen($input).') exceeds maximum limit ('.self::MAX_TEMPLATE_LENGTH.')');
        }

        if ($input === '') {
            return [];
        }

        if (! str_contains($input, '${')) {
            return [$input];
        }

        $scanner = new self;
        $scanner->load($input);
        $parts = [];

        while (! $scanner->atEnd()) {
            if (count($parts) >= self::MAX_PARTS) {
                throw A2uiException::expression('Expression parts count exceeds maximum limit ('.self::MAX_PARTS.')');
            }

            if ($scanner->matches('${')) {
                $scanner->advance(2);
                $content = $scanner->extractInterpolationContent();
                $parts[] = (new self)->parseExpression($content, $depth + 1);
            } elseif ($scanner->peek() === '\\' && $scanner->peek(1) === '$' && $scanner->peek(2) === '{') {
                $scanner->advance(3);
                $parts[] = '${';
            } else {
                $start = $scanner->pos;
                while (! $scanner->atEnd()) {
                    if ($scanner->matches('${')) {
                        break;
                    }
                    if ($scanner->peek() === '\\' && $scanner->peek(1) === '$' && $scanner->peek(2) === '{') {
                        break;
                    }
                    $scanner->advance();
                }
                $parts[] = $scanner->substring($start, $scanner->pos);
            }
        }

        return array_values(array_filter($parts, static fn ($part) => $part !== null && $part !== ''));
    }

    /**
     * Parses a single expression (the content of one `${...}` block).
     */
    public function parseExpression(string $expression, int $depth = 0): mixed
    {
        $expression = trim($expression);

        if ($expression === '') {
            return '';
        }

        $this->load($expression);
        $result = $this->parseExpressionInternal($depth);

        if (! $this->atEnd()) {
            throw A2uiException::expression("Unexpected characters at end of expression: '".$this->substring($this->pos, $this->length)."'");
        }

        return $result;
    }

    private function parseExpressionInternal(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw A2uiException::expression('Max recursion depth reached in parse');
        }

        $this->skipWhitespace();

        if ($this->atEnd()) {
            return '';
        }

        if ($this->matches('${')) {
            $this->advance(2);
            $content = $this->extractInterpolationContent();

            return (new self)->parseExpression($content, $depth + 1);
        }

        $c = $this->peek();

        if ($c === "'" || $c === '"') {
            return $this->parseStringLiteral();
        }

        if ($this->isNumberStart()) {
            return $this->parseNumberLiteral();
        }

        if ($this->matchesKeyword('true')) {
            return true;
        }

        if ($this->matchesKeyword('false')) {
            return false;
        }

        if ($this->matchesKeyword('null')) {
            return '';
        }

        $token = $this->scanPathOrIdentifier();
        $this->skipWhitespace();

        if ($this->peek() === '(') {
            return $this->parseFunctionCall($token, $depth);
        }

        if (str_starts_with($token, '@')) {
            throw A2uiException::expression("Expected '(' after function name '{$token}'");
        }

        if ($token === '') {
            if (! $this->atEnd()) {
                throw A2uiException::expression("Unexpected character '".$this->peek()."' in expression");
            }

            return '';
        }

        return ['@path' => $token];
    }

    private function scanPathOrIdentifier(): string
    {
        $start = $this->pos;

        if ($this->peek() === '@') {
            $next = $this->peek(1);
            if ($next === '' || ! preg_match('/^[A-Za-z_]$/', $next)) {
                throw A2uiException::expression("Invalid identifier starting with '@' in expression");
            }
            $this->advance();
            while (! $this->atEnd() && preg_match('/^[A-Za-z0-9_]$/', $this->peek())) {
                $this->advance();
            }

            return $this->substring($start, $this->pos);
        }

        while (! $this->atEnd()) {
            $c = $this->peek();
            if (Uax31::isContinue($c) || $c === '/' || $c === '.' || $c === '-') {
                $this->advance();
            } else {
                break;
            }
        }

        return $this->substring($start, $this->pos);
    }

    private function parseFunctionCall(string $name, int $depth): array
    {
        $this->advance(); // (
        $this->skipWhitespace();

        $args = [];

        while (! $this->atEnd() && $this->peek() !== ')') {
            $argName = $this->scanIdentifier();
            $this->skipWhitespace();

            if ($this->peek() !== ':') {
                throw A2uiException::expression("Expected ':' after argument name '{$argName}' in function '{$name}'");
            }

            $this->advance();
            $this->skipWhitespace();
            $args[$argName] = $this->parseExpressionInternal($depth + 1);
            $this->skipWhitespace();

            if ($this->peek() === ',') {
                $this->advance();
                $this->skipWhitespace();
            }
        }

        if ($this->peek() !== ')') {
            throw A2uiException::expression("Expected ')' after function arguments for '{$name}'");
        }

        $this->advance();

        return ['@call' => $name, 'args' => $args];
    }

    private function scanIdentifier(): string
    {
        $start = $this->pos;
        while (! $this->atEnd() && Uax31::isContinue($this->peek())) {
            $this->advance();
        }

        return $this->substring($start, $this->pos);
    }

    private function parseStringLiteral(): string
    {
        $quote = $this->advance();
        $result = '';
        $closed = false;

        while (! $this->atEnd()) {
            $c = $this->advance();
            if ($c === '\\') {
                if ($this->atEnd()) {
                    break;
                }
                $next = $this->advance();
                $result .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $next,
                };
            } elseif ($c === $quote) {
                $closed = true;
                break;
            } else {
                $result .= $c;
            }
        }

        if (! $closed) {
            throw A2uiException::expression("Unclosed string literal: missing closing {$quote}");
        }

        return $result;
    }

    private function isNumberStart(): bool
    {
        $offset = ($this->peek() === '-' || $this->peek() === '+') ? 1 : 0;

        if (ctype_digit($this->peek($offset))) {
            return true;
        }

        return $this->peek($offset) === '.' && ctype_digit($this->peek($offset + 1));
    }

    private function parseNumberLiteral(): int|float
    {
        $start = $this->pos;

        if ($this->peek() === '-' || $this->peek() === '+') {
            $this->advance();
        }

        while (! $this->atEnd() && (ctype_digit($this->peek()) || $this->peek() === '.')) {
            $this->advance();
        }

        if (! $this->atEnd() && ($this->peek() === 'e' || $this->peek() === 'E')) {
            $this->advance();
            if (! $this->atEnd() && ($this->peek() === '+' || $this->peek() === '-')) {
                $this->advance();
            }
            while (! $this->atEnd() && ctype_digit($this->peek())) {
                $this->advance();
            }
        }

        $text = $this->substring($start, $this->pos);

        if (! preg_match(self::NUMBER_LITERAL, $text)) {
            throw A2uiException::expression("Invalid number literal: '{$text}'");
        }

        $value = $text + 0;

        if (is_float($value) && ! is_finite($value)) {
            throw A2uiException::expression("Number literal is out of range: '{$text}'");
        }

        if (is_float($value) && $value == floor($value) && abs($value) < PHP_INT_MAX && ! preg_match('/[.eE]/', $text)) {
            return (int) $value;
        }

        return $value == 0 ? 0 : $value;
    }

    private function extractInterpolationContent(): string
    {
        $start = $this->pos;
        $balance = 1;

        while (! $this->atEnd() && $balance > 0) {
            $c = $this->advance();
            if ($c === '{') {
                $balance++;
            } elseif ($c === '}') {
                $balance--;
            } elseif ($c === "'" || $c === '"') {
                while (! $this->atEnd()) {
                    $inner = $this->advance();
                    if ($inner === '\\') {
                        $this->advance();
                    } elseif ($inner === $c) {
                        break;
                    }
                }
            }
        }

        if ($balance > 0) {
            throw A2uiException::expression("Unclosed interpolation: missing '}'");
        }

        return $this->substring($start, $this->pos - 1);
    }

    // Scanner primitives over Unicode code points --------------------------

    private function load(string $input): void
    {
        $this->chars = mb_str_split($input);
        $this->length = count($this->chars);
        $this->pos = 0;
    }

    private function atEnd(): bool
    {
        return $this->pos >= $this->length;
    }

    private function peek(int $offset = 0): string
    {
        return $this->chars[$this->pos + $offset] ?? '';
    }

    private function advance(int $count = 1): string
    {
        $chunk = implode('', array_slice($this->chars, $this->pos, $count));
        $this->pos += $count;

        return $chunk;
    }

    private function matches(string $expected): bool
    {
        $expectedChars = mb_str_split($expected);

        foreach ($expectedChars as $i => $char) {
            if ($this->peek($i) !== $char) {
                return false;
            }
        }

        return true;
    }

    private function matchesKeyword(string $keyword): bool
    {
        if (! $this->matches($keyword)) {
            return false;
        }

        $next = $this->peek(mb_strlen($keyword));

        if ($next !== '' && Uax31::isContinue($next)) {
            return false;
        }

        $this->advance(mb_strlen($keyword));

        return true;
    }

    private function skipWhitespace(): void
    {
        while (! $this->atEnd() && preg_match('/^\s$/u', $this->peek())) {
            $this->advance();
        }
    }

    private function substring(int $start, int $end): string
    {
        return implode('', array_slice($this->chars, $start, max(0, $end - $start)));
    }
}
