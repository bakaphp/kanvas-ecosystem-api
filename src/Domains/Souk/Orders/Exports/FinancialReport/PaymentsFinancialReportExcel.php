<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Exports\FinancialReport;

use Kanvas\Souk\Orders\Exports\Concerns\DrawsHeaderLogos;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PaymentsFinancialReportExcel implements WithMultipleSheets
{
    use DrawsHeaderLogos;

    public function __construct(
        private readonly array $report,
        private readonly array $metadata = [],
    ) {
    }

    public function sheets(): array
    {
        return [
            new PaymentsSummarySheet($this->report, $this->metadata),
            new PaymentsDailySheet($this->report, $this->metadata),
            new PaymentsDetailSheet($this->report, $this->metadata),
        ];
    }
}
