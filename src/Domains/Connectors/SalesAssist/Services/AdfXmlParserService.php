<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Services;

use DOMDocument;
use Kanvas\Connectors\SalesAssist\Exceptions\InvalidAdfPayloadException;
use Kiwilan\XmlReader\XmlReader;

class AdfXmlParserService
{
    /**
     * ADF arrives as an email body, so providers wrap the XML in their own text (CARFAX appends an
     * unsubscribe footer after </adf>) and anything mailed to the receiver inbox lands here too.
     * Returns null for a body with no <adf> element so callers can skip non-lead mail without reporting.
     *
     * @throws InvalidAdfPayloadException when the body holds an <adf> element that still isn't valid XML
     */
    public static function toArray(?string $body): ?array
    {
        if ($body === null || ! preg_match('/<adf[\s>]/i', $body, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $adfStart = $match[0][1];
        $closingTag = strripos($body, '</adf>');
        if ($closingTag === false || $closingTag < $adfStart) {
            throw new InvalidAdfPayloadException('Invalid ADF XML: missing closing </adf> tag');
        }

        // Only the xml declaration survives from the prolog: XmlReader keys nodes by name, and CARFAX's
        // lowercase "adf" processing instruction collides with the <adf> element, nesting the prospect.
        preg_match('/<\?xml\b[^?]*\?>/i', substr($body, 0, $adfStart), $declaration);

        $xml = ($declaration[0] ?? '') . substr($body, $adfStart, $closingTag + strlen('</adf>') - $adfStart);

        self::assertWellFormed($xml);

        // The slice always ends in "</adf>", so XmlReader can't mistake it for a file path.
        return XmlReader::make($xml, true, true)->toArray();
    }

    /**
     * XmlReader returns a bare string for a plain node and an `@content`/`@attributes` array for one with attributes.
     */
    public static function content(mixed $node): ?string
    {
        return is_array($node) ? ($node['@content'] ?? null) : $node;
    }

    private static function assertWellFormed(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);

        new DOMDocument()->loadXML($xml);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($errors !== []) {
            throw new InvalidAdfPayloadException(
                'Invalid ADF XML: ' . trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')'
            );
        }
    }
}
