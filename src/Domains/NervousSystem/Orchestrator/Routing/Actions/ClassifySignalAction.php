<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Illuminate\Support\Str;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\SignalClassification;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Content classification: when the deterministic match is ambiguous or empty, ask the LLM which
 * candidate (a lead, an organization or a project) this signal is about, scoring its title + content
 * against each candidate's descriptor. The model may answer "none" (no record fits / no action). A
 * hallucinated key outside the candidate set is treated as none. Confidence banding is the caller's
 * job — this returns the raw classification.
 */
class ClassifySignalAction
{
    public const string NONE = 'none';

    private const int CONTENT_CHAR_CAP = 8000;

    public function __construct(
        private readonly InboundSignal $signal,
        private readonly string $model = 'gemini-3.1-flash-lite'
    ) {
    }

    /**
     * @param list<RoutingCandidate> $candidates
     */
    public function execute(array $candidates): SignalClassification
    {
        if ($candidates === []) {
            return SignalClassification::none('No candidates.');
        }

        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema): array => [
                'candidate' => $schema->string()
                    ->description('The key of the record this signal belongs to (e.g. "lead:12"), chosen ONLY '
                        . 'from the candidate keys provided. Use "' . self::NONE . '" if NONE of them fit or no '
                        . 'action is needed.')
                    ->required(),
                'confidence' => $schema->number()
                    ->description('0.0 to 1.0 confidence in the choice.')
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
     * @param list<RoutingCandidate> $candidates
     */
    private function normalize(array $structured, array $candidates): SignalClassification
    {
        $key = strtolower(trim((string) ($structured['candidate'] ?? '')));
        $confidence = (float) ($structured['confidence'] ?? 0.0);
        $reason = (string) ($structured['reason'] ?? '');

        foreach ($candidates as $candidate) {
            if ($candidate->key() === $key) {
                return new SignalClassification($candidate, $confidence, $reason);
            }
        }

        return SignalClassification::none($reason !== '' ? $reason : 'No candidate fit.');
    }

    /**
     * @param list<RoutingCandidate> $candidates
     */
    private function buildPrompt(array $candidates): string
    {
        $descriptors = array_map(
            static fn (RoutingCandidate $candidate): string => $candidate->describe(),
            $candidates
        );

        return "You route an inbound {$this->signal->kind->value} to the ONE record it belongs to.\n\n"
            . "SIGNAL\n"
            . "Title: {$this->signal->title}\n"
            . "Content:\n" . Str::limit($this->signal->content, self::CONTENT_CHAR_CAP) . "\n\n"
            . "CANDIDATES\n" . implode("\n\n", $descriptors) . "\n\n"
            . 'Pick the single candidate key this signal is about. A lead is one open sales opportunity, '
            . 'an organization is a customer account, a project is internal work. If none of them fit, '
            . 'or the signal needs no action (an internal FYI, spam, a duplicate), answer "' . self::NONE
            . '". Give a 0.0-1.0 confidence and a one-sentence reason.';
    }
}
