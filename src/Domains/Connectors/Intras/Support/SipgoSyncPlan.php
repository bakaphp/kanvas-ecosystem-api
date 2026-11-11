<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

/**
 * What one on-demand refresh will pull: the legacy ids of every row in the root's graph, in the
 * order the importer needs them (companies → participants → versions → registrations). Built by
 * reading SIPGO, before anything is written, so the caller can decide to run it inline or queue it.
 */
final readonly class SipgoSyncPlan
{
    /**
     * @param list<int> $companyIds
     * @param list<int> $participantIds
     * @param list<int> $versionIds
     * @param list<int> $skippedParticipantIds participants left alone because a Kanvas edit of theirs awaits approval
     */
    public function __construct(
        public SipgoReference $root,
        public int $agencyId,
        public array $companyIds,
        public array $participantIds,
        public array $versionIds,
        public int $registrationCount,
        public array $skippedParticipantIds = [],
    ) {
    }

    public function rows(): int
    {
        return count($this->companyIds)
            + count($this->participantIds)
            + count($this->versionIds)
            + $this->registrationCount;
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'empresas' => count($this->companyIds),
            'ejecutivos' => count($this->participantIds),
            'versiones' => count($this->versionIds),
            'inscripciones' => $this->registrationCount,
        ];
    }
}
