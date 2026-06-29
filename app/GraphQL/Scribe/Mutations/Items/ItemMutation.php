<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Items;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Items\Actions\CreateItemAction;
use Kanvas\Scribe\Items\Actions\UpdateItemAction;
use Kanvas\Scribe\Items\DataTransferObject\Item as ItemData;
use Kanvas\Scribe\Items\Enums\ItemTypeEnum;
use Kanvas\Scribe\Items\Models\Item;

class ItemMutation
{
    public function create(mixed $rootValue, array $request): Item
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateItemAction(
            data: $this->buildData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Item
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Item $item */
        $item = Item::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateItemAction(
            item: $item,
            data: $this->buildData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    private function buildData(
        array $input,
        AppInterface $app,
        CompanyInterface $company,
    ): ItemData {
        return new ItemData(
            app: $app,
            company: $company,
            item_number: (string) $input['item_number'],
            name: (string) $input['name'],
            type: ItemTypeEnum::from((string) $input['type']),
            description: $input['description'] ?? null,
            inventory_variant_id: isset($input['inventory_variant_id']) ? (int) $input['inventory_variant_id'] : null,
            default_income_account_id: isset($input['default_income_account_id']) ? (int) $input['default_income_account_id'] : null,
            default_expense_account_id: isset($input['default_expense_account_id']) ? (int) $input['default_expense_account_id'] : null,
            default_tax_code_id: isset($input['default_tax_code_id']) ? (int) $input['default_tax_code_id'] : null,
            default_price_native: isset($input['default_price_native']) ? (float) $input['default_price_native'] : null,
            currency: $input['currency'] ?? null,
            is_active: $input['is_active'] ?? true,
            source: $input['source'] ?? 'kanvas',
            external_id: $input['external_id'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
