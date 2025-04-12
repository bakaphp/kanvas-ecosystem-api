<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Support\Str;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

class GeminiTagService
{
    /**
     * Get the top 3 most relevant tags for a given message.
     */
    public function generateTags(string $message, array $availableTags, int $limit = 3): array
    {
        $prompt = "Given the following message:\n\n\"$message\"\n\nSelect the **{$limit} most relevant** tags from this list: " . implode(', ', $availableTags);

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt($prompt)
            ->generate();

        $generatedText = $response->text ?? '';

        return $this->extractTags($generatedText, $availableTags, $limit);
    }

    private function extractTags(string $responseText, array $availableTags, int $limit = 3): array
    {
        $matchedTags = [];

        foreach ($availableTags as $tag) {
            if (Str::contains($responseText, $tag)) {
                $matchedTags[] = $tag;
            }
        }

        return array_slice($matchedTags, 0, $limit);
    }
}
