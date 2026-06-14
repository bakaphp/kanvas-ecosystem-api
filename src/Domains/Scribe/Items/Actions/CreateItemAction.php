<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Items\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Items\DataTransferObject\ItemData;
use Kanvas\Scribe\Items\Models\Item;
use RuntimeException;

class CreateItemAction
{
    public function __construct(
        public readonly ItemData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Item
    {
        return DB::connection('accounting')->transaction(function (): Item {
            $this->assertItemNumberUnique();

            $item = new Item();
            $item->apps_id = $this->data->app->getId();
            $item->companies_id = $this->data->company->getId();
            $item->item_number = $this->data->item_number;
            $item->name = $this->data->name;
            $item->description = $this->data->description;
            $item->type = $this->data->type->value;
            $item->inventory_variant_id = $this->data->inventory_variant_id;
            $item->default_income_account_id = $this->data->default_income_account_id;
            $item->default_expense_account_id = $this->data->default_expense_account_id;
            $item->default_tax_code_id = $this->data->default_tax_code_id;
            $item->default_price_native = $this->data->default_price_native;
            $item->currency = $this->data->currency;
            $item->is_active = $this->data->is_active;
            $item->source = $this->data->source;
            $item->external_id = $this->data->external_id;
            $item->metadata = $this->data->metadata;
            $item->users_id = $this->user?->getId();
            $item->save();

            return $item->refresh();
        });
    }

    private function assertItemNumberUnique(): void
    {
        $exists = Item::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('item_number', $this->data->item_number)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Item number '{$this->data->item_number}' is already used in this company's catalog."
            );
        }
    }
}
