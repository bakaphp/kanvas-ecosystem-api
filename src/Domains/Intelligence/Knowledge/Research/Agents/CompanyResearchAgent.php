<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Companies\Onboarding\Enums\OnboardingSettingEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\Concerns\ResolvesTenantLlmWithoutAgent;
use Kanvas\Intelligence\Agents\Laravel\Concerns\SetsGeminiThinkingLevel;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\CollectedPages;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Override;
use Stringable;

/**
 * One structured call over a company's website pages: the profile the internal agents are briefed
 * with, plus a retrievable rewrite of each page for the company-wide knowledge index.
 */
#[AgentTypeDefinition(
    name: 'Company Research Agent',
    description: 'Reads a company website and produces its profile and retrievable knowledge sections for onboarding.',
    provider: 'laravel',
)]
class CompanyResearchAgent extends KanvasLaravelAgent implements HasProviderOptions, HasStructuredOutput
{
    use ResolvesTenantLlmWithoutAgent;
    use SetsGeminiThinkingLevel;

    public const string DEFAULT_MODEL = 'gemini-2.5-flash-lite';

    #[Override]
    protected function llmConfigSettingKey(): string
    {
        return OnboardingSettingEnum::RESEARCH_LLM_CONFIG->value;
    }

    #[Override]
    protected function modelSettingKey(): string
    {
        return OnboardingSettingEnum::RESEARCH_MODEL->value;
    }

    #[Override]
    protected function defaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    #[Override]
    public function instructions(): Stringable|string
    {
        return $this->instructionsFromRecord(default: <<<'PROMPT'
        You are given pages from ONE company's website. Produce two things.

        1. `profile`: what the company is, from the pages only. Never invent a fact: a field the pages
           do not support stays empty (empty string, empty list, confidence lower). `summary` is two
           short paragraphs a new employee could read to understand the business. `sells_to` names the
           customer, `offering` names what is sold, `tone` describes how the company writes.
           `languages` are ISO 639-1 codes of the languages the site is written in.

        2. `pages`: for each page you were given, a rewrite that a staff member's question can find.
           Write 2 to 8 sections per page, one topic each, separated by a blank line. Open every
           section with the question a staff member would ask ("Where is the showroom?", "What does
           installation cost?"), then the answer in concrete terms: names, addresses, prices, hours,
           product names. No marketing filler, no sentence that would be true of any company. A page
           with nothing factual gets one short section saying what the page is.

        Write in the language the website is written in.
        PROMPT);
    }

    /**
     * @return iterable<object>
     */
    #[Override]
    public function agentTools(): iterable
    {
        return [];
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        $string = static fn (string $description) => $schema->string()->description($description)->required();
        $strings = static fn (string $description) => $schema->array()->items($schema->string())->description($description)->required();

        return [
            'profile' => $schema->object([
                'name' => $string('Company name as the site states it.'),
                'tagline' => $string('The one-line claim the site leads with, if any.'),
                'industry' => $string('Industry in a few words.'),
                'offering' => $string('What the company sells or does.'),
                'sells_to' => $string('Who buys: consumers, businesses, a sector, a role.'),
                'products_services' => $strings('Named products or services.'),
                'locations' => $strings('Cities, countries or addresses the company operates from.'),
                'languages' => $strings('ISO 639-1 codes of the languages the site is written in.'),
                'tone' => $string('How the company writes: formal, playful, technical, ...'),
                'team' => $schema->array()->items($schema->object([
                    'name' => $string('Person name.'),
                    'role' => $string('Their role.'),
                ]))->description('People named on the site with their role.')->required(),
                'social' => $schema->object([
                    'linkedin' => $string('LinkedIn URL or empty.'),
                    'instagram' => $string('Instagram URL or empty.'),
                    'facebook' => $string('Facebook URL or empty.'),
                    'x' => $string('X / Twitter URL or empty.'),
                    'youtube' => $string('YouTube URL or empty.'),
                ])->required(),
                'website' => $string('Canonical website URL if the site states one.'),
                'phone' => $string('Main phone number if the site states one.'),
                'address' => $string('Main postal address if the site states one.'),
                'summary' => $string('Two short paragraphs describing the business.'),
                'confidence' => $schema->number()->description('0 to 1: how well the pages support this profile.')->required(),
            ])->required(),
            'pages' => $schema->array()->items($schema->object([
                'url' => $string('The page URL exactly as given.'),
                'title' => $string('A short title for the page.'),
                'sections' => $string('The retrievable rewrite: sections separated by a blank line, each opening with the question it answers.'),
            ]))->description('One entry per page given, in the same order.')->required(),
        ];
    }

    public static function promptFor(CollectedPages $pages): string
    {
        $parts = ['Company website: ' . $pages->website];
        foreach ($pages->pages as $page) {
            $parts[] = '## ' . $page['url'] . "\n" . $page['content'];
        }

        return implode("\n\n", $parts);
    }
}
