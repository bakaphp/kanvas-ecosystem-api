<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

use Kanvas\Exceptions\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Email-signature HTML is written by an agent (or a person) and then mailed on someone's behalf, so it is
 * reduced to what mail clients render — tables, text, links, images, inline styles — and nothing that runs
 * or loads on its own.
 *
 * Images are the one thing a signature fetches, so they never come from a URL the author typed: the author
 * writes `kanvas-file:<filesystem_id>` and the caller swaps in the URL of a file it has already checked
 * belongs to the tenant. Any other image source fails the whole signature rather than being dropped, so the
 * author learns why the logo is missing.
 */
final class SignatureHtml
{
    public const string IMAGE_PREFIX = 'kanvas-file:';

    private const int MAX_LENGTH = 20000;

    private const array LAYOUT_ATTRIBUTES = ['style', 'width', 'height', 'align', 'valign', 'bgcolor'];

    /**
     * @return list<int>
     */
    public static function imageFileIds(string $html): array
    {
        preg_match_all('/' . preg_quote(self::IMAGE_PREFIX, '/') . '(\d+)/', $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * @param array<int, string> $imageUrls filesystem id => URL, each already checked to be the tenant's image
     *
     * @throws ValidationException
     */
    public static function sanitize(string $html, array $imageUrls = []): string
    {
        if (mb_strlen($html) > self::MAX_LENGTH) {
            throw new ValidationException('The signature HTML is longer than ' . self::MAX_LENGTH . ' characters.');
        }

        $html = (string) preg_replace_callback(
            '/' . preg_quote(self::IMAGE_PREFIX, '/') . '(\d+)/',
            static fn (array $match): string => $imageUrls[(int) $match[1]]
                ?? throw new ValidationException('Image ' . $match[0] . ' was not resolved.'),
            $html
        );

        $clean = self::clean($html);

        preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/i', $clean, $sources);
        $foreign = array_diff(array_map('html_entity_decode', $sources[1]), $imageUrls);

        if ($foreign !== []) {
            throw new ValidationException(
                'Images must be files sent in the conversation, written as ' . self::IMAGE_PREFIX . '<filesystem_id>.'
            );
        }

        if (trim(strip_tags($clean, '<img>')) === '') {
            throw new ValidationException('The signature has no content left after removing what emails cannot show.');
        }

        return $clean;
    }

    /**
     * The sanitizer pass alone, for HTML that was already validated when it was saved — run again at render
     * time so a row edited outside the tools still cannot carry anything active.
     */
    public static function clean(string $html): string
    {
        return trim(new HtmlSanitizer(self::config())->sanitize($html));
    }

    /**
     * The text/plain part of the email: block boundaries become line breaks.
     */
    public static function toText(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $html);
        $text = (string) preg_replace('#</t[dh]>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)),
            explode("\n", $text)
        );

        return implode("\n", array_filter($lines));
    }

    private static function config(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->withMaxInputLength(self::MAX_LENGTH)
            ->withAttributeSanitizer(new SignatureStyleSanitizer());

        foreach (['table', 'tbody', 'thead'] as $element) {
            $config = $config->allowElement($element, [...self::LAYOUT_ATTRIBUTES, 'cellpadding', 'cellspacing', 'border', 'role']);
        }

        foreach (['tr', 'td', 'th'] as $element) {
            $config = $config->allowElement($element, [...self::LAYOUT_ATTRIBUTES, 'colspan', 'rowspan']);
        }

        foreach (['div', 'span', 'p', 'strong', 'b', 'em', 'i', 'u', 'small', 'h1', 'h2', 'h3', 'h4', 'hr'] as $element) {
            $config = $config->allowElement($element, ['style', 'align']);
        }

        return $config
            ->allowElement('br')
            ->allowElement('a', ['href', 'style', 'title'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'style', 'border']);
    }
}
