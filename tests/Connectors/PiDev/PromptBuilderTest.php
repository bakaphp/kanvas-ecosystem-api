<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Connectors\PiDev\Services\PromptBuilder;
use Tests\TestCase;

final class PromptBuilderTest extends TestCase
{
    public function testLockedPolicyIsAlwaysFirst(): void
    {
        $prompt = PromptBuilder::build(['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git']);

        $this->assertStringStartsWith('You are an autonomous coding agent', $prompt);
        $this->assertStringContainsString('Never merge it yourself', $prompt);
        $this->assertStringContainsString('Never print, log, or exfiltrate secrets', $prompt);
    }

    public function testIncludesRepoRulesOfEngagement(): void
    {
        $prompt = PromptBuilder::build([
            'slug' => 'widgets',
            'url' => 'https://github.com/acme/widgets.git',
            'base_branch' => 'develop',
            'branch_prefix' => 'agent/',
            'rules' => 'No dependency bumps.',
            'protected_paths' => ['.github/workflows/', '.env'],
        ]);

        $this->assertStringContainsString('Base branch: develop', $prompt);
        $this->assertStringContainsString('agent/', $prompt);
        $this->assertStringContainsString('No dependency bumps.', $prompt);
        $this->assertStringContainsString('.github/workflows/', $prompt);
    }

    public function testAppendsAgentPersonaLast(): void
    {
        $prompt = PromptBuilder::build(
            ['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git'],
            'You are the frontend specialist.',
        );

        $this->assertStringContainsString('You are the frontend specialist.', $prompt);
        $this->assertStringEndsWith('You are the frontend specialist.', $prompt);
    }
}
