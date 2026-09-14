<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Baka\Support\Str;
use Illuminate\Support\Arr;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Hands back the stored URL of a file the company owns, so a report can link what it lists.
 *
 * The list_*_files tools withhold URLs on purpose — a listing carrying them teaches the model to
 * describe files instead of opening them (see the PresentsEntityFiles trait). This is the deliberate
 * exception to that: a separate call, made only when a file is being handed to a person.
 *
 * The URL is looked up rather than composed because our file URLs carry a storage-generated path —
 * a model assembling one from the id produces a plausible dead link.
 */
#[AgentTool(name: 'Get File Link', category: 'ecosystem')]
class GetFileLinkTool extends Tool implements HasRunKey
{
    use HasKanvasContext;

    // A summary that links six documents may ask for them one at a time, and the default per-name
    // budget would abort the turn partway through the list.
    use TrackByInputs;

    private const int MAX_IDS = 25;

    public function __construct()
    {
        parent::__construct(
            name: 'get_file_link',
            description: 'Get an openable link for a file stored in Kanvas, by filesystem_id. Use it whenever '
                . 'you tell a person about a document — a brief, a deck, a report you attached — so they get a '
                . 'link instead of an id. Pass every id you are about to mention in one call. Ids this company '
                . 'does not own come back under `unavailable`: say that the file could not be linked, and never '
                . 'write a URL this tool did not return.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'filesystem_ids',
                description: 'The ids of the files to link — the filesystem_id the list/attach tools returned. '
                    . 'Up to ' . self::MAX_IDS . ' per call.',
                required: true,
                items: new ToolProperty(
                    name: 'filesystem_id',
                    type: PropertyType::INTEGER,
                    description: 'A file id owned by this company.',
                ),
                maxItems: self::MAX_IDS,
            ),
        ];
    }

    /**
     * The union type is not defensiveness: asked for one document, models pass the bare id rather
     * than a one-element list, and a typed `array` parameter turns that into a TypeError the model
     * never sees an explanation for.
     *
     * @return array<string, mixed>
     */
    public function __invoke(array|int|string|null $filesystem_ids = null): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'status' => 'error',
                'message' => 'No app in scope, a file link cannot be built for this turn.',
            ];
        }

        $ids = $this->normalizeIds($filesystem_ids);

        if ($ids === []) {
            return [
                'status' => 'error',
                'message' => 'Pass filesystem_ids — the ids the list_plan_files, list_task_files or upload tools '
                    . 'returned. There is no other way to name a file.',
            ];
        }

        // Reported rather than sliced away: a model that asked for thirty links and silently got
        // twenty-five hands over a list with five documents quietly missing from it.
        $unavailable = array_map(
            static fn (int $id): array => [
                'filesystem_id' => $id,
                'reason' => 'Not requested — only ' . self::MAX_IDS . ' ids are linked per call. Ask for the rest '
                    . 'in another call.',
            ],
            array_slice($ids, self::MAX_IDS),
        );

        $ids = array_slice($ids, 0, self::MAX_IDS);

        $files = Filesystem::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Filesystem $file): int => (int) $file->getId());

        $links = [];

        foreach ($ids as $id) {
            /** @var Filesystem|null $file */
            $file = $files->get($id);

            if ($file === null) {
                $unavailable[] = [
                    'filesystem_id' => $id,
                    'reason' => 'No file with this id belongs to this company.',
                ];

                continue;
            }

            $url = Str::trimToNull($file->url);

            if ($url === null) {
                $unavailable[] = [
                    'filesystem_id' => $id,
                    'file_name' => $file->name,
                    'reason' => 'This file has no stored URL, so it cannot be linked.',
                ];

                continue;
            }

            $links[] = [
                'filesystem_id' => $id,
                'file_name' => $file->name,
                'file_type' => $file->file_type,
                'url' => $url,
            ];
        }

        return [
            'links' => $links,
            'count' => count($links),
            'unavailable' => $unavailable,
            'note' => $this->note($links, $unavailable),
        ];
    }

    /**
     * @param list<array<string, mixed>> $links
     * @param list<array<string, mixed>> $unavailable
     */
    private function note(array $links, array $unavailable): string
    {
        if ($links === []) {
            return 'None of these files could be linked. Tell the person the document is not available to link '
                . 'rather than writing a URL of your own.';
        }

        $note = sprintf('%d link(s). Give them as clickable links, not as ids.', count($links));

        return $unavailable === []
            ? $note
            : $note . sprintf(' %d file(s) could not be linked — say so instead of guessing a URL.', count($unavailable));
    }

    /**
     * @return list<int>
     */
    private function normalizeIds(array|int|string|null $ids): array
    {
        $normalized = [];

        foreach (Arr::wrap($ids) as $id) {
            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }
}
