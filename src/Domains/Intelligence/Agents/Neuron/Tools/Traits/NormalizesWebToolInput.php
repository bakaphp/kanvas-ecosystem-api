<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;

/**
 * Cleaning up what the model passed before it reaches an external web API. Shared by the research
 * connectors (Tavily, Jina) because each of them otherwise grows its own copy of the same two checks.
 */
trait NormalizesWebToolInput
{
    /**
     * The provider fetches the URL, not us, so this is a usability guard rather than an SSRF one: a
     * malformed or non-http scheme is a wasted round trip and a credit, and the model can fix it if told.
     *
     * @return array{error: string}|null
     */
    protected function rejectInvalidUrl(string $url): ?array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return ['error' => '"' . $url . '" is not a valid http(s) URL. Pass a full address including '
                . 'the scheme, e.g. https://example.com/pricing.'];
        }

        return null;
    }

    /**
     * An absent optional param and one the model filled with whitespace mean the same thing — leave the
     * key off the payload so the provider applies its own default rather than receiving an empty string.
     */
    protected function optionalText(?string $value): ?string
    {
        return Str::trimToNull($value);
    }

    /**
     * Page content is unbounded and several pages can land in one turn, so every tool that returns
     * fetched text caps it. The marker is left in the string on purpose — a model that can see the text
     * was cut can ask for the rest instead of answering from half a page as though it were whole.
     */
    protected function truncateContent(string $content, int $maxLength): string
    {
        if (mb_strlen($content) <= $maxLength) {
            return $content;
        }

        return mb_substr($content, 0, $maxLength) . "\n\n[... truncated, page continues ...]";
    }
}
