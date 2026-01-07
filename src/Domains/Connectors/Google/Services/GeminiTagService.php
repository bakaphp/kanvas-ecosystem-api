<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use Baka\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class GeminiTagService
{
    /**
     * Get the top 3 most relevant tags for a given message.
     */
    public function generateTags(
        string $message,
        array $availableTags,
        int $limit = 3
    ): array {
        if (! in_array('NSFW', $availableTags)) {
            $availableTags[] = 'NSFW';
        }

        $tagsList = implode(', ', $availableTags);

        // Enhanced prompt with NSFW detection rules
        $prompt = <<<PROMPT
You will be given a short text.
Your job is to analyze what the message is fundamentally about and select the TOP {$limit} tags that best describe it.

Allowed tags: {$tagsList}

Rules:
- Select ONLY from these tags.
- If the message contains sexual content, erotic context, nudity, or adult themes, include the tag "NSFW".
- DO NOT generate any NSFW content. Only classify.
- Do NOT infer anything unrealistic or unrelated.
- Choose the tags that best reflect the main activity or intention.
- Output ONLY the {$limit} tags, comma-separated, ranked by relevance.

TEXT:
"{$message}"
PROMPT;

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt($prompt)
            ->asText();

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
