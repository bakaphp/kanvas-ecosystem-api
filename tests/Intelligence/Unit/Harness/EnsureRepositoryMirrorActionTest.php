<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Actions\EnsureRepositoryMirrorAction;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;
use Throwable;

class EnsureRepositoryMirrorActionTest extends TestCase
{
    public function testTheMirrorLivesUnderReposPerCompanyOutsideTheWorktreeRoot(): void
    {
        $this->assertSame(
            '/srv/kanvas/repos/11479/agent-sandbox.git',
            EnsureRepositoryMirrorAction::pathFor('/srv/kanvas/', 11479, 'agent-sandbox')
        );
    }

    public function testAMissingMirrorIsClonedBare(): void
    {
        $client = new FakeCodingSshClient([
            'test -d' => 'MISSING',
            'git' => 'EXIT_CODE:0',
            'mkdir' => 'EXIT_CODE:0',
        ]);

        $this->action($client)->execute();

        $this->assertCount(1, $this->commandsMatching($client, 'clone --mirror'));
        $this->assertCount(0, $this->commandsMatching($client, 'fetch --prune'));
        $this->assertStringContainsString("'https://github.com/mctekk/agent-sandbox.git' '/srv/kanvas/repos/7/agent-sandbox.git'", $this->commandsMatching($client, 'clone --mirror')[0]);
    }

    public function testAnExistingMirrorIsFetchedNotRecloned(): void
    {
        $client = new FakeCodingSshClient([
            'test -d' => 'EXISTS',
            'git' => 'EXIT_CODE:0',
        ]);

        $this->action($client)->execute();

        $this->assertCount(0, $this->commandsMatching($client, 'clone --mirror'));
        $this->assertCount(1, $this->commandsMatching($client, 'fetch --prune origin'));
    }

    public function testTheLentCredentialIsRemovedEvenWhenGitFails(): void
    {
        $client = new FakeCodingSshClient([
            'test -d' => 'MISSING',
            'git' => 'fatal: nope EXIT_CODE:128',
        ]);

        try {
            $this->action($client)->execute();
            $this->fail('a failed clone must surface');
        } catch (Throwable) {
        }

        $this->assertCount(1, $this->commandsMatching($client, 'rm -f'), 'the token file must not outlive the clone');
    }

    private function action(FakeCodingSshClient $client): EnsureRepositoryMirrorAction
    {
        return new EnsureRepositoryMirrorAction(
            $client,
            null,
            '/srv/kanvas/repos/7/agent-sandbox.git',
            'https://github.com/mctekk/agent-sandbox.git',
            'test'
        );
    }

    /**
     * @return list<string>
     */
    private function commandsMatching(FakeCodingSshClient $client, string $needle): array
    {
        return array_values(array_filter($client->commands, static fn (string $c): bool => str_contains($c, $needle)));
    }
}
