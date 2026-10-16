<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `resolve()` finds the CONFIGURED entry for a repository, whatever spelling it was given. It is not
 * the permission check — that is the git token, and `resolveOrFail()` falls through to it. These tests
 * cover the spelling, which is the part that silently goes wrong.
 */
class RepoAllowListResolveTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    public static function acceptedProvider(): array
    {
        return [
            'the slug' => ['sandbox'],
            'the slug in another case' => ['SandBox'],
            'the exact clone url' => ['https://github.com/mctekk/agent-sandbox.git'],
            'without the .git suffix' => ['https://github.com/mctekk/agent-sandbox'],
            'the browser url with a trailing slash' => ['https://github.com/mctekk/agent-sandbox/'],
            'the ssh form' => ['git@github.com:mctekk/agent-sandbox.git'],
            'owner and name only' => ['mctekk/agent-sandbox'],
            'a token embedded in the url' => ['https://x-access-token:secret@github.com/mctekk/agent-sandbox.git'],
            // Address-bar forms. A regex anchored at the end of the URL rejected all three, which is
            // every link a person actually has in hand while looking at the repository.
            'a link to a branch' => ['https://github.com/mctekk/agent-sandbox/tree/main'],
            'a link to a pull request' => ['https://github.com/mctekk/agent-sandbox/pull/3'],
            'a link to a file' => ['https://github.com/mctekk/agent-sandbox/blob/main/README.md'],
            'a url with a query string' => ['https://github.com/mctekk/agent-sandbox?tab=readme-ov-file'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAnAllowListedRepositoryResolvesHoweverItIsWritten(string $identifier): void
    {
        $this->assertSame('sandbox', $this->service()->resolve($identifier)?->slug);
    }

    /**
     * Not configured, so `resolve()` finds nothing and the caller falls through to asking the token.
     * A null here is "no settings for this one", not "refused".
     *
     * @return array<string, list<string>>
     */
    public static function unconfiguredProvider(): array
    {
        return [
            'another repository entirely' => ['https://github.com/mctekk/kanvas-mission-control.git'],
            'the right name under the wrong owner' => ['https://github.com/attacker/agent-sandbox.git'],
            'the right path on the wrong host' => ['https://gitlab.com/mctekk/agent-sandbox.git'],
            'an unknown slug' => ['production'],
            'a local path' => ['/srv/kanvas/origins/demo.git'],
            'nothing' => ['   '],
        ];
    }

    #[DataProvider('unconfiguredProvider')]
    public function testARepositoryWithNoEntryHasNoConfiguredSettings(string $identifier): void
    {
        $this->assertNull($this->service()->resolve($identifier));
    }

    /**
     * A near-miss must not be swallowed by the `owner/name` suffix match — otherwise one repository's
     * protected paths and rules would quietly be applied to a different repository.
     */
    public function testASuffixOfTheOwnerDoesNotCount(): void
    {
        $this->assertNull($this->service()->resolve('ektekk/agent-sandbox'));
    }

    private function service(): RepoAllowListService
    {
        // The list normally comes from a custom field; this replaces the lookup, not the matching.
        return new class (new Agent()) extends RepoAllowListService {
            #[Override]
            public function all(): array
            {
                return [new CodingRepository(
                    slug: 'sandbox',
                    cloneUrl: 'https://github.com/mctekk/agent-sandbox.git',
                )];
            }
        };
    }
}
