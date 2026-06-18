<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxCode as TaxCodeData;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use RuntimeException;

/**
 * Updates the TaxCode header. Rate management goes through dedicated rate-CRUD Actions (later) — this
 * action does NOT replace the rate list, because rates have historical JE references that would orphan
 * if we wholesale-deleted them.
 */
class UpdateTaxCodeAction
{
    public function __construct(
        public readonly TaxCode $taxCode,
        public readonly TaxCodeData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): TaxCode
    {
        return DB::connection('accounting')->transaction(function (): TaxCode {
            $code = $this->taxCode;

            if ($code->code !== $this->data->code) {
                $this->assertCodeUniqueOnRename($code);
            }

            $code->code = $this->data->code;
            $code->name = $this->data->name;
            $code->jurisdiction = $this->data->jurisdiction;
            $code->is_active = $this->data->is_active;
            $code->external_id = $this->data->external_id ?? $code->external_id;
            $code->metadata = $this->data->metadata ?? $code->metadata;
            $code->save();

            return $code->refresh();
        });
    }

    private function assertCodeUniqueOnRename(TaxCode $taxCode): void
    {
        $exists = TaxCode::query()
            ->where('apps_id', $taxCode->apps_id)
            ->where('companies_id', $taxCode->companies_id)
            ->where('code', $this->data->code)
            ->where('id', '!=', $taxCode->id)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Tax code '{$this->data->code}' is already used in this company."
            );
        }
    }
}
