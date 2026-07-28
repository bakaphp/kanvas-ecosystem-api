<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Override;
use Throwable;

class EventParticipantsRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'event_participants';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'version_id (required) — the event edition whose attendees to export';
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Participant Type', 'Ticket Price', 'Discount', 'Payment Status'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $versionId = $this->filterInt($filters, 'version_id');
        if ($versionId === null) {
            throw new ValidationException('version_id is required to export event_participants.');
        }

        try {
            /** @var EventVersion $version */
            $version = EventVersion::getByIdFromCompanyApp($versionId, $company, $app);
        } catch (Throwable) {
            throw new ValidationException(sprintf('No event version #%d found in this company.', $versionId));
        }

        $participants = EventVersionParticipant::query()
            ->where('event_version_id', $version->getId())
            ->notDeleted()
            ->with(['participant.people', 'participantType'])
            ->orderBy('id')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get();

        return $participants->map(fn (EventVersionParticipant $p): array => [
            $p->participant?->people?->getName() ?? '',
            $p->participantType?->name ?? '',
            (float) $p->ticket_price,
            (float) $p->discount,
            $p->payment_status ?? '',
        ])->all();
    }
}
