<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Baka\Support\TempFile;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Brings a file a coding job produced out of its container and into Kanvas.
 *
 * The job's *code* leaves on a branch, but a job that pulls an API and draws a chart produces
 * something that is not code and has no business in a repository. Without this it only exists inside a
 * workspace the reaper deletes 24h after the run, so the person who asked for it never sees it.
 *
 * Deliberately narrow on three axes, because the path and the name both arrive from a model:
 *
 *  - **Inside the workspace, or nowhere.** The resolved path must sit under this session's own
 *    checkout. The container mount is the whole agent's worktree root, so `../` reaches sibling tasks
 *    and `.home` — which holds opencode's session store and its environment.
 *  - **Documents and images only.** Reports, sheets, diagrams. Not scripts, archives or media: code
 *    travels by pull request, and nothing here should become a delivery channel for an executable.
 *  - **Capped.** A dependency tree or a build output is not an artifact, and a 600MB object is a
 *    transfer nobody asked for.
 */
#[AgentTool(name: 'Fetch Coding Job Artifact', category: 'coding')]
class FetchHarnessCodingArtifactTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    private const int MAX_BYTES = 100 * 1024 * 1024;

    /**
     * Narrower than `WORK_FILES` on purpose. That list carries every video and audio format, and
     * heic/heif/avif, which `docker/imagemagick-policy.xml` disables — an artifact in one of those
     * fails inside the optimizer with "not authorized by the security policy", which reads like a
     * broken upload rather than an unsupported format.
     *
     * @var list<string>
     */
    private const array ARTIFACT_EXTENSIONS = [
        'csv', 'doc', 'docx', 'gif', 'html', 'jpeg', 'jpg', 'json',
        'md', 'ods', 'odt', 'pdf', 'png', 'ppt', 'pptx', 'txt',
        'webp', 'xls', 'xlsx', 'xml',
    ];

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'fetch_coding_job_artifact',
            description: 'Bring a file a coding job produced — a report, chart, spreadsheet, PDF or '
                . 'image — out of its workspace and attach it to the job\'s task, so the person can '
                . 'open it. Use it when the job made something that is NOT code; code arrives by pull '
                . 'request instead. The file must still exist, so fetch it soon after the job ends: '
                . 'workspaces are deleted a day later. Scripts, archives, audio and video are refused.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'job_id',
                type: PropertyType::INTEGER,
                description: 'The coding job that produced the file.',
                required: true,
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Where the file sits inside the job\'s workspace, e.g. '
                    . '"reports/revenue.csv". Relative to the checkout root, never an absolute path.',
                required: true,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'What to store it as, including the extension. Defaults to the name in '
                    . '`path`.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $path, ?string $file_name = null): array
    {
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'No coding job ' . $job_id . ' for this agent. Use list_self_hosted_coding_jobs to find '
                    . 'the right id.'
            );
        }

        $workspace = Str::trimToNull($session->workspace_path);
        $machine = $session->machine;

        if ($workspace === null || $machine === null) {
            return $this->noop(
                ['job_id' => $job_id],
                'Job ' . $job_id . ' has no workspace to read from. Attach mode keeps no workspace of its '
                    . 'own, and a reaped one is gone for good — say the file cannot be retrieved rather '
                    . 'than describing its contents.'
            );
        }

        $remote = $this->resolveInsideWorkspace($workspace, $path);

        if ($remote === null) {
            return $this->invalidArgs(
                '"' . $path . '" is not inside the job\'s workspace.',
                guidance: 'Pass a path relative to the checkout root, with no leading slash and no "..".'
            );
        }

        $name = $this->safeFileName($file_name ?? basename($path));

        if ($name === null || ! in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ARTIFACT_EXTENSIONS, true)) {
            return $this->invalidArgs(
                'Only documents and images can be fetched: ' . implode(', ', self::ARTIFACT_EXTENSIONS) . '.',
                guidance: 'A script or an archive is not an artifact — code reaches the team through the '
                    . 'pull request. Do not rename a file to get around this.'
            );
        }

        $user = $this->contextUser();
        $task = $session->task;

        if ($user === null || $task === null) {
            return $this->failed('This job has no task or user to attach the file to.');
        }

        try {
            $file = $this->pull(
                $machine->connectSsh(),
                $remote,
                $name,
                $user,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                $e->getMessage(),
                guidance: 'The file was not retrieved. Do not describe its contents.'
            );
        }

        if (is_array($file)) {
            return $file;
        }

        $attached = $this->attachStoredFile($task, 'task', $file, $name);

        // Branch on it rather than wrapping it: `ok()` stamps `success: true`, so wrapping a failed
        // attach would hand the model `success: true` beside `status: error` and tell it to give out
        // a file_url that does not exist — the exact confusion ReportsToolOutcome exists to stop.
        if (($attached['status'] ?? null) !== 'success') {
            return $this->failed(
                (string) ($attached['message'] ?? 'The file could not be attached to the task.'),
                guidance: 'The file did not reach the task. Do not offer the person a link.'
            );
        }

        return $this->ok(
            [
                'job_id' => $job_id,
                'filesystem_id' => $attached['filesystem_id'],
                'file_name' => $attached['file_name'],
                'file_url' => $attached['file_url'],
            ],
            guidance: 'Give the person the file_url. Describe only what the job reported about it — you '
                . 'have not read its contents.'
        );
    }

    /**
     * Streams to a temp file rather than reading into memory: `createFileSystemFromBase64()` writes a
     * temp file and calls `upload()` anyway, so going through a string and base64 costs about 2.3x the
     * file's size in memory to arrive at the same place.
     *
     * @return Filesystem|array<string, mixed> the stored file, or a refusal
     */
    private function pull(
        object $client,
        string $remote,
        string $name,
        Users $user,
    ): mixed {
        try {
            $size = $this->remoteSize($client, $remote);

            if ($size === null) {
                return $this->notFound(
                    ['path' => $remote],
                    'There is no file at that path in the workspace. List what the job actually produced '
                        . 'before trying again.'
                );
            }

            if ($size > self::MAX_BYTES) {
                return $this->invalidArgs(
                    'That file is ' . round($size / 1024 / 1024) . 'MB, over the '
                        . (self::MAX_BYTES / 1024 / 1024) . 'MB limit.',
                    guidance: 'Ask the job to write a smaller summary file instead of fetching this one.'
                );
            }

            return TempFile::using(
                function (string $tmp) use ($client, $remote, $name, $user): mixed {
                    if (! $client->downloadToFile($remote, $tmp) || ! is_file($tmp)) {
                        return $this->failed('The file could not be read off the machine.');
                    }

                    return new FilesystemServices($this->app, $this->company)->upload(
                        new UploadedFile(
                            path: $tmp,
                            originalName: $name,
                            mimeType: FilesystemServices::detectMimeType($tmp),
                            test: true,
                        ),
                        $user,
                    );
                },
                extension: pathinfo($name, PATHINFO_EXTENSION),
            );
        } finally {
            $client->disconnect();
        }
    }

    private function remoteSize(object $client, string $remote): ?int
    {
        $output = trim((string) $client->exec(
            'stat -c %s ' . escapeshellarg($remote) . ' 2>/dev/null || echo MISSING',
            30
        ));

        return is_numeric($output) ? (int) $output : null;
    }

    /**
     * The path arrives from a model, so it is resolved and then proven to be under the workspace.
     *
     * Textual checks are not enough on their own — the container mounts the agent's whole worktree
     * root, so a sibling task's checkout and `.home` (opencode's session store, and its environment)
     * are both a few `../` away. This normalises the path ourselves rather than asking the machine,
     * because asking would mean running a command built from the very string being validated.
     */
    private function resolveInsideWorkspace(string $workspace, string $path): ?string
    {
        $relative = trim($path);

        // Refused, not re-rooted. Silently reading `<workspace>/etc/passwd` when the model asked for
        // `/etc/passwd` is safe but dishonest, and a guard that quietly means something else than it
        // was asked is the kind that stops being reviewed.
        if ($relative === '' || str_starts_with($relative, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $relative)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Refuse rather than pop: a path that climbs at all is a path that meant to leave.
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : rtrim($workspace, '/') . '/' . implode('/', $segments);
    }
}
