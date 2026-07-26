<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Illuminate\Support\Str;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\ProjectClassification;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Project\Models\Project;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Cascade step (b): content classification. When deterministic matching (a) is ambiguous or empty, ask
 * the LLM which candidate project this signal belongs to — scoring the signal's title + content against
 * each project's derived descriptor (title + objective + description). The model may answer "none" (no
 * project fits / no action). A hallucinated id outside the candidate set is treated as none. Confidence
 * banding is the caller's job — this returns the raw classification.
 */
class ClassifySignalToProjectAction
{
    private const int CONTENT_CHAR_CAP = 8000;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly string $model = 'gemini-2.5-pro',
    ) {
    }

    /**
     * @param list<Project> $candidates
     */
    public function execute(array $candidates): ProjectClassification
    {
        if ($candidates === []) {
            return ProjectClassification::none('No candidate projects.');
        }

        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema): array => [
                'project_id' => $schema->integer()
                    ->description('The id of the project this signal belongs to, chosen ONLY from the '
                        . 'candidate ids provided. Use 0 if NONE of them fit or no project action is needed.')
                    ->required(),
                'confidence' => $schema->number()
                    ->description('0.0 to 1.0 confidence in the project_id choice.')
                    ->required(),
                'reason' => $schema->string()
                    ->description('One short sentence explaining the choice.')
                    ->required(),
            ],
        )->prompt(
            $this->buildPrompt($candidates),
            provider: Lab::Gemini,
            model: $this->model,
            timeout: 220,
        );

        return $this->normalize($response->structured, $candidates);
    }

    /**
     * @param array<string, mixed> $structured
     * @param list<Project> $candidates
     */
    private function normalize(array $structured, array $candidates): ProjectClassification
    {
        $projectId = (int) ($structured['project_id'] ?? 0);
        $confidence = (float) ($structured['confidence'] ?? 0.0);
        $reason = (string) ($structured['reason'] ?? '');

        $validIds = array_map(static fn (Project $project): int => $project->getId(), $candidates);

        // 0 = "none"; anything the model invented that isn't a real candidate is treated as none too.
        if ($projectId <= 0 || ! in_array($projectId, $validIds, true)) {
            return ProjectClassification::none($reason !== '' ? $reason : 'No project fit.');
        }

        return new ProjectClassification($projectId, $confidence, $reason);
    }

    /**
     * @param list<Project> $candidates
     */
    private function buildPrompt(array $candidates): string
    {
        $descriptors = [];
        foreach ($candidates as $project) {
            $descriptors[] = sprintf(
                "[project_id=%d] \"%s\"\n  objective: %s\n  about: %s",
                $project->getId(),
                $project->title,
                Str::limit((string) $project->objective, 400) ?: '(none)',
                Str::limit((string) $project->description, 400) ?: '(none)',
            );
        }

        return "You route an inbound {$this->signal->kind->value} to the ONE project it belongs to.\n\n"
            . "SIGNAL\n"
            . "Title: {$this->signal->title}\n"
            . "Content:\n" . Str::limit($this->signal->content, self::CONTENT_CHAR_CAP) . "\n\n"
            . "CANDIDATE PROJECTS\n" . implode("\n\n", $descriptors) . "\n\n"
            . 'Pick the single project_id from the candidates that this signal is about. If none of them '
            . 'fit, or the signal needs no project action (an internal FYI, spam, a duplicate), return '
            . 'project_id 0. Give a 0.0-1.0 confidence and a one-sentence reason.';
    }
}
