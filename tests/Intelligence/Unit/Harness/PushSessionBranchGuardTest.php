<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Actions\PushSessionBranchAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The branch guard runs before anything touches a machine, so it is exercised here without SSH.
 *
 * These are the rules that decide where an agent's work can land. Getting them wrong writes to somebody
 * else's trunk, which is why they are asserted rather than trusted.
 */
class PushSessionBranchGuardTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    public static function forbiddenBranchProvider(): array
    {
        return [
            'the base branch itself' => ['main'],
            'a trunk by another name' => ['develop'],
            'case does not help' => ['MAIN'],
            'production' => ['production'],
            'HEAD' => ['HEAD'],
        ];
    }

    #[DataProvider('forbiddenBranchProvider')]
    public function testAnAgentCannotPushToATrunkByDefault(string $branch): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/base or protected branch/');

        $this->push($branch)->execute();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function malformedBranchProvider(): array
    {
        return [
            'parent traversal' => ['../evil'],
            'option injection' => ['--force'],
            'a refspec' => ['refs/heads/main:refs/heads/main'],
            'leading dash' => ['-x'],
            'a space' => ['my branch'],
        ];
    }

    #[DataProvider('malformedBranchProvider')]
    public function testAMalformedBranchNameIsRefusedOutright(string $branch): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not a valid branch name/');

        $this->push($branch)->execute();
    }

    /**
     * Proves the guard let the name through: the next thing `execute()` does is resolve the machine, so
     * that is the failure a legitimate branch reaches.
     */
    public function testAnOrdinaryAgentBranchPassesTheGuard(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/no machine/');

        $this->push('agent/24081')->execute();
    }

    public function testASessionWithNoBranchHasNothingToPush(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/nothing to push/');

        $session = new AgentTaskSession();
        $session->workspace_path = '/srv/kanvas/worktrees/x';

        new PushSessionBranchAction(session: $session, commitMessage: 'x')->execute();
    }

    private function push(string $branch): PushSessionBranchAction
    {
        $session = new AgentTaskSession();
        $session->workspace_path = '/srv/kanvas/worktrees/x';
        $session->branch = $branch;

        return new PushSessionBranchAction(
            session: $session,
            commitMessage: 'a change',
            baseBranch: 'main',
        );
    }
}
