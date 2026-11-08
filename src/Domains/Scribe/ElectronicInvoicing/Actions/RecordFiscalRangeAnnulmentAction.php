<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalRangeAnnulment;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalRangeAnnulmentLine;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;

/**
 * Records a DR ANECF request: one batch, one line per interval. DGII answers per line, so the batch only
 * settles once every line has a result and may end `partial`.
 */
class RecordFiscalRangeAnnulmentAction
{
    /**
     * @param list<array{document_type: string, series?: string|null, from: int, to: int}> $lines
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $environment,
        public readonly array $lines,
        public readonly ?string $provider = null,
    ) {
    }

    public function execute(): FiscalRangeAnnulment
    {
        return DB::connection('accounting')->transaction(function (): FiscalRangeAnnulment {
            $annulment = FiscalRangeAnnulment::create([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'environment' => $this->environment,
                'country' => new EcfIssuanceService()->regimeFor($this->company)->country(),
                'provider' => $this->provider,
                'status' => FiscalRangeAnnulmentLine::PENDING,
                'submitted_at' => Carbon::now(),
                'payload' => ['lines' => $this->lines],
            ]);

            foreach ($this->lines as $line) {
                $annulment->lines()->create([
                    'document_type' => $line['document_type'],
                    'series' => $line['series'] ?? null,
                    'range_from' => $line['from'],
                    'range_to' => $line['to'],
                    'result' => FiscalRangeAnnulmentLine::PENDING,
                ]);
            }

            return $annulment;
        });
    }

    /**
     * @param array<int, array{annulled: bool, message?: string|null}> $results keyed by line id
     */
    public static function settle(FiscalRangeAnnulment $annulment, array $results): FiscalRangeAnnulment
    {
        return DB::connection('accounting')->transaction(function () use ($annulment, $results): FiscalRangeAnnulment {
            foreach ($annulment->lines as $line) {
                if (! isset($results[$line->getKey()])) {
                    continue;
                }

                $line->result = $results[$line->getKey()]['annulled'] ? FiscalRangeAnnulmentLine::ANNULLED : FiscalRangeAnnulmentLine::REFUSED;
                $line->message = $results[$line->getKey()]['message'] ?? null;
                $line->save();
            }

            $outcomes = $annulment->lines()->pluck('result')->unique()->values()->all();

            $annulment->status = match (true) {
                in_array(FiscalRangeAnnulmentLine::PENDING, $outcomes, true) => FiscalRangeAnnulmentLine::PENDING,
                $outcomes === [FiscalRangeAnnulmentLine::ANNULLED] => FiscalRangeAnnulmentLine::ANNULLED,
                $outcomes === [FiscalRangeAnnulmentLine::REFUSED] => FiscalRangeAnnulmentLine::REFUSED,
                default => 'partial',
            };
            $annulment->resolved_at = $annulment->status === FiscalRangeAnnulmentLine::PENDING ? null : Carbon::now();
            $annulment->save();

            return $annulment->refresh();
        });
    }
}
