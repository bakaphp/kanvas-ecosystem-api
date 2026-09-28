<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Kernel;

use Kanvas\Connectors\Kernel\Services\BrowserFiles;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\AttachAgentFilesToPlanAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Shared by both save tools: pull paths out of the VM, store them in Kanvas, put them on a plan.
 */
trait SavesKernelBrowserFiles
{
    public function requiredMcpServer(): string
    {
        return BrowserFiles::SERVER;
    }

    /**
     * @return array<string, mixed>|null the refusal to return, or null when there is an agent to work with
     */
    protected function withoutAgent(?Agent $agent): ?array
    {
        return $agent instanceof Agent
            ? null
            : ['status' => 'error', 'message' => 'No agent is in scope, so nothing can be saved.'];
    }

    protected function browserFiles(Agent $agent): BrowserFiles
    {
        // property_exists, not `??`: a host that does not declare the seam reads an undefined property.
        return property_exists($this, 'files') && $this->files instanceof BrowserFiles
            ? $this->files
            : new BrowserFiles($agent);
    }

    /**
     * @param list<array{path: string, size?: int|null}> $candidates a sweep knows each size; a named path is measured here
     * @return array<string, mixed>
     */
    protected function saveToPlan(
        Agent $agent,
        string $sessionId,
        array $candidates,
        ?int $planId,
        string $title
    ): array {
        $files = $this->browserFiles($agent);
        $filesystem = new FilesystemServices($this->app, $this->company);
        $plan = $planId === null ? null : Plan::getByIdFromCompanyApp($planId, $this->company, $this->app);
        $alreadyThere = $this->fingerprintsOn($plan);
        $stored = [];
        $skipped = [];

        foreach ($this->uniquePaths($candidates) as $path => $knownSize) {
            $size = $knownSize ?? $files->sizeOf($sessionId, $path);
            $name = basename(str_replace('\\', '/', $path));

            if ($size === null) {
                $skipped[$path] = 'not found in the session';

                continue;
            }

            if ($size > BrowserFiles::MAX_BYTES) {
                $skipped[$path] = sprintf('%d bytes is over the %d limit — export a narrower range', $size, BrowserFiles::MAX_BYTES);

                continue;
            }

            // The tool may be called twice for one session; without this the plan collects it twice.
            if (in_array($name . ':' . $size, $alreadyThere, true)) {
                $skipped[$path] = 'already saved';

                continue;
            }

            $encoded = $files->read($sessionId, $path);

            if ($encoded === null) {
                $skipped[$path] = 'could not be read';

                continue;
            }

            try {
                $stored[$name] = $filesystem->createFileSystemFromBase64($encoded, $name, $this->user);
                $alreadyThere[] = $name . ':' . $size;
            } catch (Throwable $e) {
                report($e);
                $skipped[$path] = 'could not be stored in Kanvas';
            }
        }

        if ($stored === []) {
            return [
                'status' => 'error',
                'saved' => [],
                'skipped' => $skipped,
                'message' => $skipped === []
                    ? 'Nothing was saved.'
                    : 'Nothing new was saved — see skipped for why. Do not tell the person a file was saved.',
            ];
        }

        $plan = new AttachAgentFilesToPlanAction(
            agent: $agent,
            user: $this->user,
            files: $stored,
            title: $title,
            plan: $plan,
        )->execute();

        // Read back from the plan, not from what we meant to attach: the agent narrates from this payload.
        $attached = $plan?->getFiles()
            ->whereIn('name', array_keys($stored))
            ->map(static fn ($file): array => ['name' => $file->name, 'id' => $file->getId(), 'size' => (int) $file->size])
            ->values()
            ->all() ?? [];

        return [
            'status' => $attached === [] ? 'error' : 'success',
            'plan_id' => $plan?->getId(),
            'saved' => $attached,
            'skipped' => $skipped,
            'note' => sprintf(
                'Saved %d file(s) to plan #%d. Name only these files to the person, and do not paste their '
                . 'contents into the conversation.',
                count($attached),
                (int) $plan?->getId()
            ),
        ];
    }

    /**
     * @param list<array{path: string, size?: int|null}> $candidates
     * @return array<string, int|null> path => its known size, each path once
     */
    private function uniquePaths(array $candidates): array
    {
        $paths = [];

        foreach ($candidates as $candidate) {
            $paths[$candidate['path']] ??= $candidate['size'] ?? null;
        }

        return $paths;
    }

    /**
     * @return list<string> name:size of what the plan already holds
     */
    private function fingerprintsOn(?Plan $plan): array
    {
        return $plan?->getFiles()
            ->map(static fn ($file): string => $file->name . ':' . (int) $file->size)
            ->all() ?? [];
    }
}
