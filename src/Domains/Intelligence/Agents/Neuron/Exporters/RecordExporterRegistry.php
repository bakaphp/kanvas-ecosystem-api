<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

/**
 * The catalog of record types the export_records tool can produce. Add a domain by dropping its
 * exporter into this list — the tool discovers the new record_type automatically.
 */
class RecordExporterRegistry
{
    public const int MAX_ROWS = 50000;

    /**
     * @var list<RecordExporterInterface>
     */
    private array $exporters;

    /**
     * @param list<RecordExporterInterface>|null $exporters
     */
    public function __construct(?array $exporters = null)
    {
        $this->exporters = $exporters ?? [
            new PeopleRecordExporter(),
            new PeopleMatchRecordExporter(),
            new OrganizationsRecordExporter(),
            new EventParticipantsRecordExporter(),
            new ProductsRecordExporter(),
            new EmployeesRecordExporter(),
            new OrdersRecordExporter(),
            new AffiliateCommissionsRecordExporter(),
        ];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(fn (RecordExporterInterface $e): string => $e->type(), $this->exporters);
    }

    public function for(string $type): ?RecordExporterInterface
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->type() === $type) {
                return $exporter;
            }
        }

        return null;
    }

    /**
     * A "type — filters" line per record type, for the tool description.
     */
    public function describe(): string
    {
        return implode('; ', array_map(
            fn (RecordExporterInterface $e): string => $e->type() . ' (' . $e->filtersHint() . ')',
            $this->exporters,
        ));
    }
}
