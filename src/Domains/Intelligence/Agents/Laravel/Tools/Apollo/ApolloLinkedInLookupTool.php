<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Apollo;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Kanvas\Connectors\Apollo\Client as ApolloClient;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Apollo LinkedIn Lookup')]
class ApolloLinkedInLookupTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to find LinkedIn profile URLs for a list of people at a specific company. Pass the company name and an array of people (each with a name and optionally a title). Only matches meeting the confidence threshold are returned (default 0.5 — lower to capture more matches, raise for stricter name matching via `min_confidence`). Returns an array of people with their `linkedin_url` and `confidence` score.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find LinkedIn profile URLs for company executives and board members using Apollo. Returns only matches above the confidence threshold (default 0.5, configurable via min_confidence).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $companyName = (string) $request->string('company_name');
        $people = (array) ($request['people'] ?? []);
        $minConfidence = (float) ($request['min_confidence'] ?? 0.5);

        if (empty($people)) {
            return json_encode(['people' => []]);
        }

        try {
            $client = new ApolloClient($this->app);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        $results = [];

        foreach ($people as $person) {
            $inputName = trim((string) ($person['name'] ?? ''));
            $title = trim((string) ($person['title'] ?? ''));

            if (empty($inputName)) {
                continue;
            }

            $nameParts = explode(' ', $inputName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            try {
                $payload = [
                    'name' => $inputName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'organization_name' => $companyName,
                    'reveal_personal_emails' => false,
                    'reveal_phone_number' => false,
                ];

                if (! empty($title)) {
                    $payload['title'] = $title;
                }

                $response = $client->post('/people/match', $payload);
                $matched = $response['person'] ?? [];

                $linkedinUrl = $matched['linkedin_url'] ?? null;

                if (empty($linkedinUrl)) {
                    usleep(200_000);

                    continue;
                }

                $returnedName = trim((string) ($matched['name'] ?? $inputName));
                $confidence = $this->nameConfidence($inputName, $returnedName);

                if ($confidence > $minConfidence) {
                    $results[] = [
                        'name' => $inputName,
                        'title' => $title,
                        'linkedin_url' => $linkedinUrl,
                        'confidence' => $confidence,
                    ];
                }
            } catch (Throwable) {
                // skip this person on any error
            }

            usleep(200_000);
        }

        return json_encode(['people' => $results]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_name' => $schema
                ->string()
                ->description('Full company name (e.g. "Saks Global"). Used to narrow the Apollo match to employees of this company.')
                ->required(),
            'people' => $schema
                ->array()
                ->items($schema->object())
                ->description('List of people to look up. Each object must have a "name" field (full name) and optionally a "title" field.')
                ->required(),
            'min_confidence' => $schema
                ->number()
                ->description('Minimum confidence score (0–1) to include a match. Default 0.5. Lower (e.g. 0.3) to capture more results; raise (e.g. 0.7) for stricter name matching.'),
        ];
    }

    private function nameConfidence(string $input, string $returned): float
    {
        $a = strtolower(trim($input));
        $b = strtolower(trim($returned));

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));

        if ($maxLen === 0) {
            return 0.0;
        }

        return round(1 - levenshtein($a, $b) / $maxLen, 3);
    }
}
