<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject;

use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingTargetTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;

final readonly class RoutingCandidate
{
    private function __construct(
        public RoutingTargetTypeEnum $type,
        public Lead|Organization|Project $record
    ) {
    }

    public static function forLead(Lead $lead): self
    {
        return new self(RoutingTargetTypeEnum::LEAD, $lead);
    }

    public static function forOrganization(Organization $organization): self
    {
        return new self(RoutingTargetTypeEnum::ORGANIZATION, $organization);
    }

    public static function forProject(Project $project): self
    {
        return new self(RoutingTargetTypeEnum::PROJECT, $project);
    }

    public function id(): int
    {
        return (int) $this->record->getId();
    }

    /**
     * "lead:12" — the id the classifier answers with, and the dedup / ledger key.
     */
    public function key(): string
    {
        return $this->type->value . ':' . $this->id();
    }

    public function label(): string
    {
        return (string) ($this->record instanceof Organization ? $this->record->name : $this->record->title);
    }

    public function project(): ?Project
    {
        return $this->record instanceof Project ? $this->record : null;
    }

    public function describe(): string
    {
        $record = $this->record;

        $details = match (true) {
            $record instanceof Lead => sprintf(
                "  status: %s\n  contact: %s\n  account: %s\n  about: %s",
                $record->statusName() ?? '(none)',
                $record->people?->getName() ?: '(none)',
                $record->organization?->name ?: '(none)',
                Str::limit((string) $record->description, 400) ?: '(none)',
            ),
            $record instanceof Organization => sprintf(
                '  email domains: %s',
                implode(', ', $record->emailDomains()) ?: '(none)',
            ),
            default => sprintf(
                "  objective: %s\n  about: %s",
                Str::limit((string) $record->objective, 400) ?: '(none)',
                Str::limit((string) $record->description, 400) ?: '(none)',
            ),
        };

        return sprintf("[%s] %s \"%s\"\n%s", $this->key(), $this->type->value, $this->label(), $details);
    }
}
