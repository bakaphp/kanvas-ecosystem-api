<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Baka\Support\TempFile;
use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\OpenCode\SshClient as CodingSshClient;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Brings the agent's screenshots out of a finished workspace, into Kanvas Filesystem.
 *
 * `.kanvas/shots/` is excluded from the diff on purpose and the worktree is reaped after a day, so
 * without this the only person who ever saw a screenshot was whoever pulled it over SSH by hand.
 * Read from `workspace_path`, a host path on both runtimes, never from `session_data_path`.
 *
 * Never fails a run: an SSH or upload error is reported and the announcement goes out without pictures.
 */
class CollectSessionScreenshotsAction
{
    public const string DIRECTORY = '.kanvas/shots';

    public const int MAX_FILES = 10;

    public const int MAX_BYTES = 10_485_760;

    public function __construct(
        private readonly AgentTaskSession $session,
    ) {
    }

    /**
     * @return list<Filesystem>
     */
    public function execute(): array
    {
        $workspace = Str::trimToNull((string) $this->session->workspace_path);
        $user = $this->session->user ?? $this->session->agent?->user;

        if ($workspace === null || $user === null) {
            return [];
        }

        $client = null;

        try {
            // Inside the try on purpose: `fromMachine()` logs in eagerly, and a session is often being
            // finalized precisely because its machine stopped answering.
            $client = $this->openSshClient();

            return $client === null ? [] : $this->collect($client, $workspace, $user);
        } catch (Throwable $e) {
            report($e);

            return [];
        } finally {
            $client?->disconnect();
        }
    }

    /**
     * @return list<Filesystem>
     */
    private function collect(SshClient $client, string $workspace, Users $user): array
    {
        $stored = [];

        foreach ($this->listScreenshots($client, $workspace) as $remote) {
            $local = TempFile::path('png');

            // Per file: one upload that fails must not discard the ones already stored.
            try {
                if (! $client->downloadToFile($remote, $local)) {
                    continue;
                }

                $stored[] = $this->store($local, basename($remote), $user);
            } catch (Throwable $e) {
                report($e);
            } finally {
                TempFile::delete($local);
            }
        }

        return $stored;
    }

    /**
     * The caps live in the listing: `find -size` rounds up to whole kilobytes, so `-size -<cap+1>k`
     * keeps files up to the cap, and `head` keeps the first files in name order.
     *
     * @return list<string>
     */
    private function listScreenshots(SshClient $client, string $workspace): array
    {
        $output = $client->exec(
            'find ' . escapeshellarg($workspace . '/' . self::DIRECTORY) . " -maxdepth 1 -type f -name '*.png'"
            . ' -size -' . (int) (self::MAX_BYTES / 1024 + 1) . 'k 2>/dev/null | sort | head -n ' . self::MAX_FILES,
            60
        );

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])));
    }

    protected function openSshClient(): ?SshClient
    {
        $machine = $this->session->machine;

        return $machine === null ? null : CodingSshClient::fromMachine($machine);
    }

    protected function store(string $localPath, string $name, Users $user): Filesystem
    {
        return new FilesystemServices($this->session->app, $this->session->company)->upload(
            new UploadedFile(
                path: $localPath,
                originalName: $name,
                mimeType: 'image/png',
                test: true
            ),
            $user
        );
    }
}
