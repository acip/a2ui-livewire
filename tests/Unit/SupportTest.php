<?php

namespace A2uiLivewire\Tests\Unit;

use A2uiLivewire\Evaluation\RegexDialect;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Support\JsonPointer;
use A2uiLivewire\Support\UrlSanitizer;
use A2uiLivewire\Support\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_json_pointer_resolution(): void
    {
        $this->assertSame('/users/0/name', JsonPointer::resolve('name', '/users/0'));
        $this->assertSame('/company', JsonPointer::resolve('/company', '/users/0'));
        $this->assertSame('/a~1b/c', JsonPointer::resolve('/a~1b/c'));
        $this->assertSame(['a/b', 'c~d'], JsonPointer::segments('/a~1b/c~0d'));
    }

    public function test_json_pointer_set_auto_vivifies(): void
    {
        $data = JsonPointer::set([], '/a/0/b', 1);
        $this->assertSame(['a' => [['b' => 1]]], $data);

        $data = JsonPointer::set(['list' => ['x']], '/list/2', 'z');
        $this->assertSame(['list' => ['x', null, 'z']], $data);

        $data = JsonPointer::set(['list' => ['x']], '/list/-', 'y');
        $this->assertSame(['list' => ['x', 'y']], $data);
    }

    public function test_json_pointer_set_through_primitive_fails(): void
    {
        $this->expectException(A2uiException::class);
        JsonPointer::set(['a' => 'str'], '/a/b', 1);
    }

    public function test_json_pointer_remove(): void
    {
        $this->assertSame(['a' => []], JsonPointer::remove(['a' => ['b' => 1]], '/a/b'));
        $this->assertSame(['l' => [1, 3]], JsonPointer::remove(['l' => [1, 2, 3]], '/l/1'));
        $this->assertSame(['a' => 1], JsonPointer::remove(['a' => 1], '/missing/x'));
    }

    public function test_dynamic_boolean_coercion_table(): void
    {
        $this->assertTrue(Values::toBoolean(true));
        $this->assertTrue(Values::toBoolean(['valid' => true]));
        $this->assertFalse(Values::toBoolean(['valid' => false, 'message' => 'x']));
        $this->assertTrue(Values::toBoolean('TRUE'));
        $this->assertFalse(Values::toBoolean('hello'));
        $this->assertFalse(Values::toBoolean(''));
        $this->assertFalse(Values::toBoolean(0));
        $this->assertTrue(Values::toBoolean(-3.5));
        $this->assertFalse(Values::toBoolean(null));
        $this->assertFalse(Values::toBoolean(['a' => 1]));
    }

    public function test_string_coercion(): void
    {
        $this->assertSame('', Values::toString(null));
        $this->assertSame('true', Values::toString(true));
        $this->assertSame('1.5', Values::toString(1.5));
        $this->assertSame('2', Values::toString(2.0));
        $this->assertSame('{"a":1}', Values::toString(['a' => 1]));
        $this->assertSame('[1,2]', Values::toString([1, 2]));
    }

    public function test_check_rule_message_precedence(): void
    {
        $fnResult = ['valid' => false, 'message' => 'This field is required.'];
        $this->assertSame('Email is required.', Values::toValidationResult($fnResult, 'Email is required.')['message']);
        $this->assertSame('This field is required.', Values::toValidationResult($fnResult, 'Fallback', false)['message']);
        $this->assertSame(['valid' => false, 'message' => 'Nope', 'severity' => 'error'], Values::toValidationResult(false, 'Nope'));
    }

    public static function mediaUrls(): array
    {
        return [
            ['https://example.com/a.png', 'https://example.com/a.png'],
            ['  http://example.com/a.png ', 'http://example.com/a.png'],
            ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'],
            ['/images/a.png', '/images/a.png'],
            ['images/a.png', 'images/a.png'],
            ['javascript:alert(1)', null],
            ['java	script:alert(1)', null],
            ['file:///etc/passwd', null],
            ['blob:https://x/1', null],
            ['//evil.example/a.png', null],
            ['\\\\evil.example/a.png', null],
            ['https:evil.example', null],
            ['', null],
        ];
    }

    #[DataProvider('mediaUrls')]
    public function test_media_url_rules(string $input, ?string $expected): void
    {
        $this->assertSame($expected, UrlSanitizer::media($input));
    }

    public function test_navigation_urls(): void
    {
        $this->assertSame('https://example.com', UrlSanitizer::navigation('https://example.com'));
        $this->assertNull(UrlSanitizer::navigation('javascript:alert(1)'));
        $this->assertNull(UrlSanitizer::navigation('mailto:a@b.c'));
        $this->assertSame('mailto:a@b.c', UrlSanitizer::navigation('mailto:a@b.c', null, ['http', 'https', 'mailto']));
        $this->assertSame('https://app.test/docs', UrlSanitizer::navigation('/docs', 'https://app.test/x/y'));
        $this->assertSame('https://app.test/x/docs', UrlSanitizer::navigation('docs', 'https://app.test/x/y'));
    }

    public static function regexCases(): array
    {
        return [
            ['^[a-z]+@[a-z]+\.com$', 'abc@def.com', true],
            ['^(?=.*\d).{8,}$', 'password1', true],
            ['^(?=.*\d).{8,}$', 'password', false],
            ['\w+\s+\w+', 'hello   world', true],
            ['^\d{10}$', '1234567890', true],
            ['^\d{10}$', '١٢٣٤٥٦٧٨٩٠', false],   // \d is ASCII only
            ['^a$', "a\n", false],               // $ does not match before trailing newline
            ['^.$', "\n", false],                // . excludes line terminators
            ['^.$', '😀', true],                 // astral code point is one character
            ['\bcafé\b', 'cafés', true],         // \b is ASCII only: é is not a word char
            ['b', 'abc', true],                  // search semantics
        ];
    }

    #[DataProvider('regexCases')]
    public function test_regex_dialect_semantics(string $pattern, string $value, bool $expected): void
    {
        $this->assertSame($expected, RegexDialect::test($pattern, $value));
    }

    public static function unsafePatterns(): array
    {
        return [['(a+)+$'], ['(a|ab)*c'], ['\d+\s*\d+'], ['(\w)\1'], ['(?<=a)b'], ['(?<n>a)'], ['\p{L}'], ['(?i)a'], ['[invalid(']];
    }

    #[DataProvider('unsafePatterns')]
    public function test_regex_rejects_unsafe_or_unsupported_patterns(string $pattern): void
    {
        $this->expectException(A2uiException::class);
        RegexDialect::compile($pattern);
    }
}
