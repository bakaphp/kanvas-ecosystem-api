<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportSubaccount;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Users\Models\Users;

/**
 * Pull the Acumatica subaccount list (dbo.Sub) into Scribe's `subaccounts` reference table. Pure
 * reference data (no invariants), so it upserts the model directly: description + active flag stay
 * fresh on re-runs, keyed on external_id (the Acumatica SubID).
 */
class PullSubaccountsAction
{
    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('Sub')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->select(['SubID', 'SubCD', 'Description', 'Active'])
            ->orderBy('SubCD');

        if ($this->modifiedSince !== null) {
            $query->where('LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $count = 0;
        $this->skipped = ['no_code' => 0];

        foreach ($rows as $row) {
            $sub = AcumaticaImportSubaccount::fromRow($row);

            if ($sub->subCode === '') {
                $this->skipped['no_code']++;

                continue;
            }

            $this->upsert($sub);
            $count++;
        }

        return $count;
    }

    private function upsert(AcumaticaImportSubaccount $sub): void
    {
        /** @var Subaccount|null $existing */
        $existing = Subaccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('sub_code', $sub->subCode)
            ->where('is_deleted', false)
            ->first();

        $model = $existing ?? new Subaccount();

        if ($existing === null) {
            $model->apps_id = $this->app->getId();
            $model->companies_id = $this->company->getId();
            $model->sub_code = $sub->subCode;
            $model->source = AcumaticaImportSubaccount::SOURCE;
            $model->external_id = $sub->externalId !== '' ? $sub->externalId : null;
            $model->users_id = $this->user->getId();
        }

        $model->description = $sub->description;
        $model->is_active = $sub->isActive;
        $model->last_synced_at = Carbon::now();
        $model->save();
    }
}
