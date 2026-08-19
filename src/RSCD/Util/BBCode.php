<?php

namespace RSCD\Util;

/**
 * Minimal BBCode renderer for the forums.
 *
 * Everything is HTML-escaped first, then a small whitelist of tags is
 * converted, so no user-supplied markup ever reaches the page:
 *
 *   [b] [i] [u]              inline styling
 *   [quote] [quote=Name]     blockquote, optionally attributed
 *   [code]                   preformatted block, contents left un-parsed
 *   [url=https://...]text[/url] and [url]https://...[/url]
 *                            links, http/https only, rel=nofollow
 *
 * Unknown or unmatched tags are left as visible literal text — a forum post
 * with a typo'd tag should look wrong, not disappear.
 */
class BBCode {

    /**
     * Render BBCode text to safe HTML.
     *
     * @param  string $text Raw post body as typed.
     * @return string       Safe HTML.
     */
    public static function toHtml($text) {
        $text = str_replace("\r\n", "\n", (string)$text);

        // Pull [code] blocks out first so nothing inside them is parsed.
        $codeBlocks = [];
        $text = preg_replace_callback('/\[code\](.*?)\[\/code\]/is', function($m) use(&$codeBlocks) {
            $codeBlocks[] = '<pre class="bb-code">' . Strings::displayText(trim($m[1], "\n")) . '</pre>';
            return "\x1A" . (count($codeBlocks) - 1) . "\x1A";
        }, $text);

        $html = Strings::displayText($text);

        // Simple paired inline tags.
        foreach(['b', 'i', 'u'] as $tag) {
            $html = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/is', '<' . $tag . '>$1</' . $tag . '>', $html);
        }

        // Quotes, innermost first so nesting works.
        for($i = 0; $i < 5; $i++) {
            $replaced = preg_replace(
                ['/\[quote=((?:[^\[\]])+?)\]((?:[^\[]|\[(?!\/?quote))*?)\[\/quote\]/is',
                 '/\[quote\]((?:[^\[]|\[(?!\/?quote))*?)\[\/quote\]/is'],
                ['<blockquote class="bb-quote"><p class="bb-quote-by">$1 wrote:</p>$2</blockquote>',
                 '<blockquote class="bb-quote">$1</blockquote>'],
                $html);
            if($replaced === $html || $replaced === null) {
                break;
            }
            $html = $replaced;
        }

        // Links: scheme whitelist, attribute-safe because the text was escaped
        // above and quotes in the URL arrive as &quot; (rejected by the
        // character class).
        $html = preg_replace(
            '/\[url=(https?:\/\/[^\s\[\]&]*(?:&amp;[^\s\[\]&]*)*)\](.*?)\[\/url\]/is',
            '<a href="$1" rel="nofollow">$2</a>', $html);
        $html = preg_replace(
            '/\[url\](https?:\/\/[^\s\[\]&]*(?:&amp;[^\s\[\]&]*)*)\[\/url\]/is',
            '<a href="$1" rel="nofollow">$1</a>', $html);

        $html = nl2br($html);

        // Restore the untouched code blocks.
        return preg_replace_callback('/\x1A(\d+)\x1A/', function($m) use($codeBlocks) {
            return $codeBlocks[(int)$m[1]] ?? '';
        }, $html);
    }

}
