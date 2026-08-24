<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\Repositories\InscriptionsVsHistoricalRepository;
use Kanvas\Event\Reports\Repositories\InscriptionsVsObjectiveRepository;
use Kanvas\Event\Reports\Repositories\InscriptionTrackRepository;
use Kanvas\Event\Reports\Repositories\ParticipantConcentrationRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Analytics for one event version: enrollment vs goal, vs past editions, registrations by participant
 * type, or which organizations dominate attendance. Delegates to the same report repositories the
 * dashboard uses. Company-scoped (the version is resolved tenant-scoped first).
 */
#[AgentTool(name: 'Get Event Report', category: 'events')]
class GetEventReportTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    private const array REPORTS = [
        'inscriptions_vs_objective',
        'inscriptions_vs_historical',
        'inscription_track',
        'participant_concentration',
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'get_event_report',
            description: 'Analytics for one event version. report is one of: "inscriptions_vs_objective" (enrollment '
                . 'curve vs this event\'s goal), "inscriptions_vs_historical" (vs past editions), "inscription_track" '
                . '(registrations broken down by participant type), "participant_concentration" (which organizations '
                . 'dominate the attendee list). Use for "how is event X selling", "is it on track", "who\'s coming".',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'version_id', type: PropertyType::INTEGER, description: 'The event version id to report on.', required: true),
            new ToolProperty(
                name: 'report',
                type: PropertyType::STRING,
                description: 'Which report to run.',
                required: true,
                enum: self::REPORTS,
            ),
            new ToolProperty(name: 'cumulative', type: PropertyType::BOOLEAN, description: 'For the two enrollment-curve reports: cumulative totals. Defaults to true.', required: false),
            new ArrayProperty(
                name: 'include_types',
                description: 'Optional participant-type names to include (from list_participant_types). Omit for all.',
                required: false,
                items: new ToolProperty(name: 'participant_type', type: PropertyType::STRING, description: 'A participant-type name.'),
            ),
            new ArrayProperty(
                name: 'exclude_types',
                description: 'Optional participant-type names to exclude.',
                required: false,
                items: new ToolProperty(name: 'participant_type', type: PropertyType::STRING, description: 'A participant-type name.'),
            ),
            new ToolProperty(name: 'top_n', type: PropertyType::INTEGER, description: 'participant_concentration only: keep the top-N orgs, collapse the rest into "Other".', required: false),
        ];
    }

    /**
     * @param list<string>|null $include_types
     * @param list<string>|null $exclude_types
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $version_id,
        string $report,
        ?bool $cumulative = null,
        ?array $include_types = null,
        ?array $exclude_types = null,
        ?int $top_n = null,
    ): array {
        if (! in_array($report, self::REPORTS, true)) {
            return ['error' => 'report must be one of: ' . implode(', ', self::REPORTS) . '.'];
        }

        try {
            /** @var EventVersion $version */
            $version = EventVersion::getByIdFromCompanyApp($version_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No event version #%d found in this company.', $version_id)];
        }

        $cumulative ??= true;
        $include = ! empty($include_types) ? array_values($include_types) : null;
        $exclude = ! empty($exclude_types) ? array_values($exclude_types) : [];

        return match ($report) {
            'inscriptions_vs_objective' => InscriptionsVsObjectiveRepository::forEventVersion(
                $version,
                cumulative: $cumulative,
                includeTypes: $include,
                excludeTypes: $exclude,
            )->toArray(),
            'inscriptions_vs_historical' => InscriptionsVsHistoricalRepository::forEventVersion(
                $version,
                cumulative: $cumulative,
                includeTypes: $include,
                excludeTypes: $exclude,
            )->toArray(),
            'inscription_track' => [
                'version_id' => $version->getId(),
                'by_type' => InscriptionTrackRepository::forEventVersion($version, $exclude)
                    ->map(fn ($row) => $row->toArray())->all(),
            ],
            'participant_concentration' => [
                'version_id' => $version->getId(),
                'by_organization' => ParticipantConcentrationRepository::forEventVersion(
                    $version,
                    topN: $top_n,
                    includeTypes: $include,
                    excludeTypes: $exclude,
                )->map(fn ($row) => $row->toArray())->all(),
            ],
        };
    }
}
