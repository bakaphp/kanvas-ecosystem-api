<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\CollectSessionScreenshotsAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * `.kanvas/shots/` is excluded from the diff and reaped with the worktree, so this is the only way a
 * screenshot reaches a person. Collection must never cost a run its outcome, so every failure path
 * returns an empty list.
 */
class CollectSessionScreenshotsTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string WORKSPACE = '/srv/kanvas/agents/7/worktrees/01a1a8db';

    /** @var list<string> */
    private array $storedBytes = [];

    private ?string $failUploadOf = null;

    public function testScreenshotsAreStoredUnderTheSessionsUser(): void
    {
        $ssh = new FakeCodingSshClient([
            'find ' => self::WORKSPACE . "/.kanvas/shots/home.png\n" . self::WORKSPACE . '/.kanvas/shots/login.png',
        ]);
        $ssh->files[self::WORKSPACE . '/.kanvas/shots/home.png'] = 'PNG-home';
        $ssh->files[self::WORKSPACE . '/.kanvas/shots/login.png'] = 'PNG-login';

        $stored = $this->collect($ssh)->execute();

        $this->assertCount(2, $stored);
        $this->assertSame(['home.png', 'login.png'], array_map(static fn (Filesystem $f): string => $f->name, $stored));
        $this->assertSame(['PNG-home', 'PNG-login'], $this->storedBytes);
        $this->assertSame(auth()->user()->getId(), $stored[0]->users_id);

        $find = $ssh->commandStartingWith('find ');
        $this->assertStringContainsString(escapeshellarg(self::WORKSPACE . '/.kanvas/shots'), $find);
        $this->assertStringContainsString("-name '*.png'", $find);
    }

    public function testNoScreenshotsAttachesNothing(): void
    {
        $this->assertSame([], $this->collect(new FakeCodingSshClient(['find ' => '']))->execute());
        $this->assertSame([], $this->storedBytes);
    }

    /**
     * The size cap and the file cap are enforced by the listing itself, and a listed file that cannot
     * be read is skipped rather than stored empty.
     */
    public function testAnUnreadableListedFileIsSkippedAndTheListingCarriesTheCaps(): void
    {
        $ssh = new FakeCodingSshClient([
            'find ' => self::WORKSPACE . "/.kanvas/shots/gone.png\n" . self::WORKSPACE . '/.kanvas/shots/home.png',
        ]);
        $ssh->files[self::WORKSPACE . '/.kanvas/shots/home.png'] = 'PNG-home';

        $stored = $this->collect($ssh)->execute();

        $this->assertSame(['home.png'], array_map(static fn (Filesystem $f): string => $f->name, $stored));
        $find = $ssh->commandStartingWith('find ');
        $this->assertStringContainsString('-size -' . (CollectSessionScreenshotsAction::MAX_BYTES / 1024 + 1) . 'k', $find);
        $this->assertStringContainsString('head -n ' . CollectSessionScreenshotsAction::MAX_FILES, $find);
    }

    /**
     * The upload before the failed one is already in the Filesystem; dropping the whole list would
     * leave it orphaned and announce nothing.
     */
    public function testOneFailedUploadDoesNotDiscardTheOthers(): void
    {
        $ssh = new FakeCodingSshClient([
            'find ' => self::WORKSPACE . "/.kanvas/shots/home.png\n" . self::WORKSPACE . '/.kanvas/shots/login.png',
        ]);
        $ssh->files[self::WORKSPACE . '/.kanvas/shots/home.png'] = 'PNG-home';
        $ssh->files[self::WORKSPACE . '/.kanvas/shots/login.png'] = 'PNG-login';
        $this->failUploadOf = 'home.png';

        $stored = $this->collect($ssh)->execute();

        $this->assertSame(['login.png'], array_map(static fn (Filesystem $f): string => $f->name, $stored));
    }

    public function testAnSshFailureIsReportedNotThrown(): void
    {
        $ssh = new class () extends FakeCodingSshClient {
            public function exec(string $command, int $timeout = 30): string
            {
                throw new RuntimeException('connection reset');
            }
        };

        $this->assertSame([], $this->collect($ssh)->execute());
    }

    /**
     * The machine being offline is the common case for a finalize: the silence sweep ends a session
     * precisely because its Mac stopped answering. `fromMachine()` logs in eagerly and throws.
     */
    public function testAnUnreachableMachineIsReportedNotThrown(): void
    {
        $session = $this->createTaskSession(attributes: [
            'workspace_path' => self::WORKSPACE,
            'users_id' => auth()->user()->getId(),
        ]);

        $action = new class ($session) extends CollectSessionScreenshotsAction {
            protected function openSshClient(): ?SshClient
            {
                throw new ValidationException('Could not reach the machine.');
            }
        };

        $this->assertSame([], $action->execute());
    }

    public function testASessionWithoutAWorkspaceCollectsNothing(): void
    {
        $session = $this->createTaskSession(attributes: ['workspace_path' => null]);
        $ssh = new FakeCodingSshClient(['find ' => self::WORKSPACE . '/.kanvas/shots/home.png']);

        $this->assertSame([], $this->collect($ssh, $session)->execute());
        $this->assertSame([], $ssh->commands);
    }

    /**
     * A file is stored under a user, and a session dispatched by an agent with no user of its own has
     * none; the upload would be a TypeError the outer catch turns into a report and no pictures.
     */
    public function testASessionWithoutAUserCollectsNothing(): void
    {
        $session = $this->createTaskSession(attributes: ['workspace_path' => self::WORKSPACE, 'users_id' => null]);
        $ssh = new FakeCodingSshClient(['find ' => self::WORKSPACE . '/.kanvas/shots/home.png']);

        $this->assertSame([], $this->collect($ssh, $session)->execute());
        $this->assertSame([], $ssh->commands);
    }

    private function collect(FakeCodingSshClient $ssh, ?AgentTaskSession $session = null): CollectSessionScreenshotsAction
    {
        $session ??= $this->createTaskSession(attributes: [
            'workspace_path' => self::WORKSPACE,
            'users_id' => auth()->user()->getId(),
        ]);
        $store = fn (string $localPath, string $name, Users $user): Filesystem => $this->fakeStore($localPath, $name, $user);

        return new class ($session, $ssh, $store) extends CollectSessionScreenshotsAction {
            public function __construct(
                AgentTaskSession $session,
                private readonly FakeCodingSshClient $ssh,
                private readonly Closure $store
            ) {
                parent::__construct($session);
            }

            protected function openSshClient(): ?SshClient
            {
                return $this->ssh;
            }

            protected function store(string $localPath, string $name, Users $user): Filesystem
            {
                return ($this->store)($localPath, $name, $user);
            }
        };
    }

    private function fakeStore(string $localPath, string $name, Users $user): Filesystem
    {
        if ($name === $this->failUploadOf) {
            throw new RuntimeException('upload failed');
        }

        $this->storedBytes[] = (string) file_get_contents($localPath);

        $filesystem = new Filesystem();
        $filesystem->apps_id = app(Apps::class)->getId();
        $filesystem->companies_id = $user->getCurrentCompany()->getId();
        $filesystem->users_id = $user->getId();
        $filesystem->name = $name;
        $filesystem->path = 'files/shots/' . uniqid() . '-' . $name;
        $filesystem->url = 'https://cdn.example.test/' . $filesystem->path;
        $filesystem->file_type = 'png';
        $filesystem->size = (string) strlen(end($this->storedBytes));
        $filesystem->is_deleted = 0;
        $filesystem->saveOrFail();

        return $filesystem;
    }
}
