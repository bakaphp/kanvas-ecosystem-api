<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Connectors\PiDev\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class RepoAllowListServiceTest extends TestCase
{
    public function testValidateNormalizesEntryAndKeepsRulesOfEngagement(): void
    {
        $result = RepoAllowListService::validate([
            [
                'slug' => 'widgets',
                'url' => 'https://github.com/acme/widgets.git',
                'base_branch' => 'main',
                'branch_prefix' => 'agent/',
                'rules' => 'No dependency bumps.',
                'protected_paths' => ['.github/workflows/', '.env'],
                'ignored_extra' => 'dropped',
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('widgets', $result[0]['slug']);
        $this->assertSame('main', $result[0]['base_branch']);
        $this->assertSame('No dependency bumps.', $result[0]['rules']);
        $this->assertSame(['.github/workflows/', '.env'], $result[0]['protected_paths']);
        $this->assertArrayNotHasKey('ignored_extra', $result[0]);
    }

    public function testValidateRejectsEmptyList(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([]);
    }

    public function testValidateRequiresSlugAndUrl(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([['slug' => 'widgets']]);
    }

    public function testValidateRejectsNonHttpsUrl(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([
            ['slug' => 'widgets', 'url' => 'git@github.com:acme/widgets.git'],
        ]);
    }

    public function testValidateRejectsUrlWithoutOwnerAndRepo(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([
            ['slug' => 'widgets', 'url' => 'https://github.com/widgets'],
        ]);
    }

    public function testValidateRejectsDuplicateSlugs(): void
    {
        $this->expectException(ValidationException::class);

        RepoAllowListService::validate([
            ['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git'],
            ['slug' => 'widgets', 'url' => 'https://github.com/acme/other.git'],
        ]);
    }
}
