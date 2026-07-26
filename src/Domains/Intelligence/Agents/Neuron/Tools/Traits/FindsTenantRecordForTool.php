<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Look up a single tenant-scoped record by one column and return either the model OR a structured
 * found=false array the LLM can act on. Requires HasKanvasContext ($this->app / $this->company) on
 * the same tool. Mirrors ResolvesLeadForTool's shape for Find{Invoice,Bill,PurchaseOrder,SalesOrder}Tool.
 */
trait FindsTenantRecordForTool
{
    /**
     * @param class-string<Model> $modelClass
     * @return Model|array{found: bool, message: string}
     */
    protected function findTenantRecordOrNotFound(
        string $modelClass,
        string $column,
        string $value,
        string $label
    ): Model|array {
        /** @var Model|null $record */
        $record = $modelClass::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where($column, trim($value))
            ->notDeleted()
            ->first();

        if ($record === null) {
            return [
                'found' => false,
                $column => $value,
                'message' => "No {$label} with that number in the synced data — it may not have been synced yet.",
            ];
        }

        return $record;
    }
}
