<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Souk\Orders\Actions\Concerns\StoresExcelExport;
use Kanvas\Souk\Orders\DataTransferObject\PaymentsFinancialReport;
use Kanvas\Souk\Orders\Exports\FinancialReport\PaymentsFinancialReportExcel;
use Kanvas\Souk\Orders\Exports\FinancialReport\PaymentsSummarySheet;
use Kanvas\Souk\Orders\Services\PaymentsFinancialReportService;

class ExportPaymentsFinancialReportAction
{
    use StoresExcelExport;

    public function __construct(
        private readonly PaymentsFinancialReport $data,
    ) {
    }

    public function execute(): array
    {
        $report = new PaymentsFinancialReportService($this->data)->build();

        $result = $this->storeExcelExport(
            new PaymentsFinancialReportExcel($report, $this->data->metadata),
            "payments_financial_report_{$report['start_date']}_{$report['end_date']}",
            $this->data->app,
            $this->data->user,
        );

        PaymentsSummarySheet::cleanupHeaderLogoFiles();

        return $result;
    }
}
