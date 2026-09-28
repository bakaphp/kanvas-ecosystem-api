<?php

declare(strict_types=1);

namespace Tests\Connectors\Github;

use Kanvas\Connectors\Github\Client;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GithubRepositoryNameTest extends TestCase
{
    public static function repositoryForms(): array
    {
        return [
            'already owner/repo' => ['bakaphp/kanvas-ecosystem-api'],
            'https url' => ['https://github.com/bakaphp/kanvas-ecosystem-api'],
            'http url with www' => ['http://www.github.com/bakaphp/kanvas-ecosystem-api'],
            'host without scheme' => ['github.com/bakaphp/kanvas-ecosystem-api'],
            'trailing slash' => ['https://github.com/bakaphp/kanvas-ecosystem-api/'],
            'clone url' => ['https://github.com/bakaphp/kanvas-ecosystem-api.git'],
            'ssh clone line' => ['git@github.com:bakaphp/kanvas-ecosystem-api.git'],
            'link to a release' => ['https://github.com/bakaphp/kanvas-ecosystem-api/releases/tag/v1.0.0'],
            'link to a branch' => ['https://github.com/bakaphp/kanvas-ecosystem-api/tree/development'],
            'query string' => ['https://github.com/bakaphp/kanvas-ecosystem-api?tab=readme-ov-file'],
            'surrounding whitespace' => ['  https://github.com/bakaphp/kanvas-ecosystem-api  '],
            'uppercase host' => ['HTTPS://GitHub.com/bakaphp/kanvas-ecosystem-api'],
        ];
    }

    #[DataProvider('repositoryForms')]
    public function testReducesEveryFormPeopleActuallyPasteToOwnerRepo(string $input): void
    {
        $this->assertSame('bakaphp/kanvas-ecosystem-api', Client::normalizeRepository($input));
    }

    public static function unusableValues(): array
    {
        return [
            'owner only' => ['bakaphp'],
            'url to an owner' => ['https://github.com/bakaphp'],
            'empty' => ['   '],
            'a sentence' => ['the kanvas repo'],
            'path traversal' => ['../../user/repos'],
        ];
    }

    #[DataProvider('unusableValues')]
    public function testRejectsWhatCannotBeARepository(string $input): void
    {
        $this->expectException(ValidationException::class);

        Client::normalizeRepository($input);
    }

    /**
     * Case is preserved: GitHub redirects a wrong-cased repository on the web, but the API is happy to
     * answer on either, and echoing back what the user typed keeps error messages recognizable.
     */
    public function testKeepsTheCaseOfTheNameItself(): void
    {
        $this->assertSame('BakaPHP/Kanvas-Ecosystem-API', Client::normalizeRepository('https://github.com/BakaPHP/Kanvas-Ecosystem-API'));
    }

    #[DataProvider('repositoryForms')]
    public function testTheNullableFormAnswersTheSameForARealRepository(string $input): void
    {
        $this->assertSame('bakaphp/kanvas-ecosystem-api', Client::tryNormalizeRepository($input));
    }

    /**
     * A coding session's repository is whatever someone typed, and a bare project name with no owner is
     * a legitimate thing to type. The nullable form exists so a caller can canonicalise what it can and
     * keep the rest untouched, instead of having to catch an exception per row.
     */
    #[DataProvider('unusableValues')]
    public function testTheNullableFormAnswersNullInsteadOfThrowing(string $input): void
    {
        $this->assertNull(Client::tryNormalizeRepository($input));
    }
}
