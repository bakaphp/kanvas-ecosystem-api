<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Actions;

use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum as SourceEnum;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationTagsService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class ApplyPeopleClassificationAction
{
    public function __construct(
        private readonly People $people,
        private readonly ?string $level,
        private readonly ?string $area,
        private readonly SourceEnum $source,
        private readonly ?Users $user = null,
    ) {
    }

    public function execute(): void
    {
        $previousLevel = $this->people->get(FieldEnum::LEVEL->value);
        $previousArea = $this->people->get(FieldEnum::AREA->value);
        $changed = $previousLevel !== $this->level || $previousArea !== $this->area;

        // Bookkeeping, not an edit: these writes must not reach rules on CREATE_CUSTOM_FIELD (the SIPGO
        // sync request rule); PEOPLE_CLASSIFIED below is the event this action owns.
        $this->people->withoutWorkflows(function (): void {
            $this->write(FieldEnum::LEVEL, $this->level);
            $this->write(FieldEnum::AREA, $this->area);
            $this->people->set(FieldEnum::SOURCE->value, $this->source->value);
        });

        $settings = PeopleClassificationSettings::for($this->people->app, $this->people->company);

        if ($settings->applyTags && $settings->taxonomy !== null) {
            PeopleClassificationTagsService::reconcile($this->people, $settings->taxonomy);
        }

        if (! $changed) {
            return;
        }

        $this->recordNote($settings->taxonomy, $previousLevel, $previousArea);
        $this->recordLedgerEvent($previousLevel, $previousArea);

        if ($this->source === SourceEnum::PENDING_REVIEW) {
            return;
        }

        // The classification is already saved, so a workflow outage must not fail it.
        try {
            $this->people->fireWorkflow(
                WorkflowEnum::PEOPLE_CLASSIFIED->value,
                true,
                [
                    'app' => $this->people->app,
                    'company' => $this->people->company,
                ]
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function write(FieldEnum $field, ?string $value): void
    {
        $value === null
            ? $this->people->del($field->value)
            : $this->people->set($field->value, $value);
    }

    private function recordNote(?PeopleTaxonomy $taxonomy, ?string $previousLevel, ?string $previousArea): void
    {
        $label = static fn (?string $code, ?string $name): string => $name ?? $code ?? 'none';

        $body = sprintf(
            'Classification changed (%s): level %s, was %s; area %s, was %s.',
            $this->source->value,
            $label($this->level, $taxonomy?->levelLabel($this->level)),
            $label($previousLevel, $taxonomy?->levelLabel($previousLevel)),
            $label($this->area, $taxonomy?->areaLabel($this->area)),
            $label($previousArea, $taxonomy?->areaLabel($previousArea)),
        );

        new RecordPeopleNoteAction($this->people)->execute(
            $body,
            'classification',
            $this->user,
            $this->user === null,
        );
    }

    /**
     * Best-effort: the classification is already saved, so a ledger outage must not fail it.
     */
    private function recordLedgerEvent(?string $previousLevel, ?string $previousArea): void
    {
        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->people->app,
                    company: $this->people->company,
                    sourceDomain: 'Guild',
                    eventType: 'guild.people.classified',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: People::class,
                    sourceEntityId: (int) $this->people->getId(),
                    actorType: $this->user !== null ? 'User' : 'System',
                    actorId: $this->user?->getId(),
                    payload: [
                        'source' => $this->source->value,
                        'level' => $this->level,
                        'previous_level' => $previousLevel,
                        'area' => $this->area,
                        'previous_area' => $previousArea,
                        'confidence' => $this->source === SourceEnum::MANUAL
                            ? null
                            : $this->people->get(FieldEnum::CONFIDENCE->value),
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
