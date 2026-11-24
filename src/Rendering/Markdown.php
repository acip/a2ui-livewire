<?php

namespace A2uiLivewire\Rendering;

use Illuminate\Support\HtmlString;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Throwable;

/**
 * Safe Markdown rendering for `Text` (raw HTML stripped, unsafe links removed).
 */
final class Markdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    /** @var array<string, string> */
    private static array $cache = [];

    public static function render(string $text, bool $inline = false): HtmlString
    {
        if ($text === '') {
            return new HtmlString('');
        }

        $key = ($inline ? 'i:' : 'b:').$text;

        if (isset(self::$cache[$key])) {
            return new HtmlString(self::$cache[$key]);
        }

        try {
            $html = (string) self::converter()->convert($text);

            if ($inline) {
                $html = self::unwrapParagraph($html);
            }
        } catch (Throwable) {
            $html = e(self::strip($text));
        }

        if (count(self::$cache) > 500) {
            self::$cache = [];
        }

        return new HtmlString(self::$cache[$key] = trim($html));
    }

    /**
     * Plain-text fallback that drops common Markdown markers.
     */
    public static function strip(string $text): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|~~|`)(.+?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;

        return $text;
    }

    private static function unwrapParagraph(string $html): string
    {
        $trimmed = trim($html);

        if (preg_match('#^<p>(.*)</p>$#s', $trimmed, $m) && substr_count($trimmed, '<p>') === 1) {
            return $m[1];
        }

        if (preg_match('#^<h([1-6])>(.*)</h\1>$#s', $trimmed, $m)) {
            return '<strong>'.$m[2].'</strong>';
        }

        return $trimmed;
    }

    private static function converter(): GithubFlavoredMarkdownConverter
    {
        return self::$converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }
}
