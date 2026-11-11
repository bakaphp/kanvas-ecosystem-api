<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

use Override;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The HTML sanitizer allows a `style` attribute but never reads the CSS inside it. A `url(...)` there
 * would be fetched by the preview renderer on our network and by every recipient's mail client, so a
 * declaration that can load or run anything drops the whole attribute.
 */
final class SignatureStyleSanitizer implements AttributeSanitizerInterface
{
    private const string FORBIDDEN = '/url\s*\(|expression\s*\(|@import|javascript:|behavior\s*:|-moz-binding|position\s*:\s*(fixed|absolute)|[<>]/i';

    #[Override]
    public function getSupportedElements(): ?array
    {
        return null;
    }

    #[Override]
    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    #[Override]
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return preg_match(self::FORBIDDEN, $value) === 1 ? null : $value;
    }
}
