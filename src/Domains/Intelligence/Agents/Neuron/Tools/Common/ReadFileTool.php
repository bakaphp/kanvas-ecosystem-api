<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use stdClass;
use Throwable;

/**
 * Read the text of a file the company owns.
 *
 * `file_url` resolves back to a row we own rather than being fetched: fetching an LLM-supplied URL
 * is a read-anything primitive one prompt injection away from internal hosts and other tenants'
 * CDN objects. A URL matching no file in this app and company is refused.
 *
 * Every refusal is phrased as an instruction because a model with no way to read its input does not
 * stop, it guesses — plan 31241 reported a breakdown of 158 records from a file of 52.
 */
#[AgentTool(name: 'Read File', category: 'ecosystem')]
class ReadFileTool extends Tool implements HasRunKey
{
    use HasKanvasContext;

    private const int CHUNK = 40000;

    /** Bounds re-reads of ONE page; paging forward gets a fresh key. See {@see getRunKey()}. */
    private const int MAX_RUNS = 3;

    /** Past this the history trimmer drops earlier pages and the read never converges. */
    private const int MAX_TURN_CHARS = 120000;

    private const int MAX_TURN_CALLS = 12;

    /** Shared across a turn because NeuronAI shallow-clones the tool per call. */
    private stdClass $turn;

    public function __construct()
    {
        parent::__construct(
            name: 'read_file',
            description: 'Read the text of a file stored in Kanvas, by filesystem_id (preferred) or by its '
                . 'file_url. Use it whenever work references a document, spreadsheet or export — a CSV of '
                . 'employees, a PDF contract, a JSON payload. Supported: '
                . implode(', ', FileTextExtractor::supportedExtensions()) . '. Long files come back in chunks: '
                . 'start at offset 0 and pass the returned next_offset until has_more is false. If the file '
                . 'cannot be read you will be told why — report that, never estimate or invent its contents.',
        );

        $this->turn = new stdClass();
        $this->turn->calls = 0;
        $this->turn->chars = 0;
        $this->turn->pages = [];

        $this->setMaxRuns(self::MAX_RUNS);
    }

    #[Override]
    public function getRunKey(): string
    {
        return $this->getName()
            . ':' . (int) $this->getInput('filesystem_id')
            . ':' . (string) $this->getInput('file_url')
            . ':' . max(0, (int) $this->getInput('offset'));
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The id of the file to read. Prefer this — it is what list/attach tools return.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'The file\'s URL, when that is all you were given. It must be a file this company '
                    . 'already owns; an outside URL is refused.',
                required: false,
            ),
            new ToolProperty(
                name: 'offset',
                type: PropertyType::INTEGER,
                description: 'Character offset to start from (default 0). Pass the previous call\'s next_offset '
                    . 'to page through a long file.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $filesystem_id = null,
        ?string $file_url = null,
        ?int $offset = null,
    ): array {
        $offset = max(0, (int) $offset);
        $fileUrl = trim((string) $file_url);

        if (++$this->turn->calls > self::MAX_TURN_CALLS) {
            return $this->stop(
                null,
                0,
                $offset,
                'read_file has been called ' . $this->turn->calls . ' times this turn. Stop reading and answer '
                    . 'with what you have.',
            );
        }

        if (($filesystem_id === null || $filesystem_id <= 0) && $fileUrl === '') {
            return $this->stop(
                null,
                0,
                $offset,
                'Pass either filesystem_id or file_url.',
            );
        }

        $file = $this->resolveFile($filesystem_id, $fileUrl);

        if ($file === null) {
            return $this->stop(
                $filesystem_id,
                0,
                $offset,
                $fileUrl !== '' && ($filesystem_id === null || $filesystem_id <= 0)
                    ? 'No file with that URL belongs to this company. Ask whoever referenced it to attach it, '
                        . 'and read it by filesystem_id.'
                    : "File {$filesystem_id} was not found in this company. Do not guess its contents — say you "
                        . 'could not read it.',
            );
        }

        $extractor = new FileTextExtractor();

        if (! $extractor->supports($file)) {
            return $this->stop(
                $file->getId(),
                0,
                $offset,
                sprintf(
                    '"%s" is not a readable document type. Supported: %s. Say so rather than describing what '
                        . 'you assume is in it.',
                    $file->name,
                    implode(', ', FileTextExtractor::supportedExtensions()),
                ),
            );
        }

        try {
            $content = $extractor->extract($file);
        } catch (Throwable $e) {
            report($e);

            return $this->stop(
                $file->getId(),
                0,
                $offset,
                sprintf('"%s" could not be downloaded or parsed. Report that it is unreadable.', $file->name),
            );
        }

        return $this->page($file, $content, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(Filesystem $file, string $content, int $offset): array
    {
        $total = mb_strlen($content);

        if ($total === 0) {
            return $this->stop(
                $file->getId(),
                0,
                $offset,
                sprintf('"%s" holds no readable text — it may be a scan or an empty export.', $file->name),
            );
        }

        if ($offset >= $total) {
            return $this->stop(
                $file->getId(),
                $total,
                $offset,
                "You have reached the end of this file ({$total} chars). Act on what you read.",
            );
        }

        $page = $file->getId() . ':' . $offset;

        if (isset($this->turn->pages[$page])) {
            return $this->stop(
                $file->getId(),
                $total,
                $offset,
                "You already received offset {$offset} of this file earlier in this turn — it is above in the "
                    . 'conversation. Continue from the last next_offset, or answer with what you have.',
            );
        }

        if ($this->turn->chars >= self::MAX_TURN_CHARS) {
            return $this->stop(
                $file->getId(),
                $total,
                $offset,
                'You have already read ' . $this->turn->chars . ' chars of file content this turn, which is all '
                    . 'one turn returns. Work from that and read the rest in a follow-up if you truly need it.',
            );
        }

        $slice = mb_substr($content, $offset, self::CHUNK);
        $length = mb_strlen($slice);
        $next = $offset + $length;

        $this->turn->pages[$page] = true;
        $this->turn->chars += $length;

        $hasMore = $next < $total && $this->turn->chars < self::MAX_TURN_CHARS;

        return [
            'filesystem_id' => $file->getId(),
            'file_name' => $file->name,
            'file_type' => $file->file_type,
            'total_length' => $total,
            'offset' => $offset,
            'content' => $slice,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $next : null,
        ];
    }

    private function resolveFile(?int $filesystemId, string $fileUrl): ?Filesystem
    {
        $query = Filesystem::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted();

        return $filesystemId !== null && $filesystemId > 0
            ? $query->where('id', $filesystemId)->first()
            : $query->where('url', $fileUrl)->first();
    }

    /**
     * Terminal: no content and has_more false, so the model has an instruction to follow rather than
     * a page to reach for.
     *
     * @return array<string, mixed>
     */
    private function stop(
        ?int $filesystemId,
        int $total,
        int $offset,
        string $reason
    ): array {
        return [
            'filesystem_id' => $filesystemId,
            'total_length' => $total,
            'offset' => $offset,
            'content' => '',
            'has_more' => false,
            'next_offset' => null,
            'note' => $reason,
        ];
    }
}
