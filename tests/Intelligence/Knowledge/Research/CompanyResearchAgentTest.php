<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\OnboardingSettingEnum;
use Kanvas\Intelligence\Knowledge\Research\Agents\CompanyResearchAgent;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\CollectedPages;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
final class CompanyResearchAgentTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    public function testSchemaHasProfileAndPages(): void
    {
        $schema = $this->agent()->schema(new JsonSchemaTypeFactory());

        $this->assertArrayHasKey('profile', $schema);
        $this->assertArrayHasKey('pages', $schema);

        $profile = $schema['profile']->toArray();
        foreach (['name', 'industry', 'offering', 'sells_to', 'products_services', 'locations', 'languages', 'tone', 'team', 'social', 'summary', 'confidence'] as $key) {
            $this->assertArrayHasKey($key, $profile['properties'], $key);
        }
        $this->assertSame('array', $schema['pages']->toArray()['type']);
    }

    public function testModelLadderPrefersAppSettingThenDefault(): void
    {
        $app = app(Apps::class);
        $this->rememberAppSettings(OnboardingSettingEnum::RESEARCH_MODEL->value);

        $app->del(OnboardingSettingEnum::RESEARCH_MODEL->value);
        $this->assertPromptedModel(CompanyResearchAgent::DEFAULT_MODEL);
        $this->assertSame([], $this->agent()->providerOptions(Lab::Gemini));

        $app->set(OnboardingSettingEnum::RESEARCH_MODEL->value, 'gemini-2.5-flash');
        $this->assertPromptedModel('gemini-2.5-flash');
        $this->assertSame(['generation_config' => ['thinking_level' => 'low']], $this->agent()->providerOptions(Lab::Gemini));
    }

    public function testPromptListsEveryPageUnderItsUrl(): void
    {
        $prompt = CompanyResearchAgent::promptFor(new CollectedPages('https://93.184.216.34', 'tavily', [
            ['url' => 'https://93.184.216.34', 'title' => 'Acme', 'content' => 'We make chairs.'],
            ['url' => 'https://93.184.216.34/about', 'title' => null, 'content' => 'Founded in 1999.'],
        ]));

        $this->assertStringContainsString('https://93.184.216.34', $prompt);
        $this->assertStringContainsString("## https://93.184.216.34/about\nFounded in 1999.", $prompt);
        $this->assertStringContainsString('We make chairs.', $prompt);
    }

    private function assertPromptedModel(string $expected): void
    {
        CompanyResearchAgent::fake([['profile' => [], 'pages' => []]]);
        $this->agent()->promptWithConfig('pages ' . $expected);

        CompanyResearchAgent::assertPrompted(
            fn ($prompt): bool => $prompt->prompt === 'pages ' . $expected && $prompt->model === $expected,
        );
    }

    private function agent(): CompanyResearchAgent
    {
        $agent = new CompanyResearchAgent();
        $agent->setConfigurationWithoutAgent(app(Apps::class), auth()->user()->getCurrentCompany());

        return $agent;
    }
}
