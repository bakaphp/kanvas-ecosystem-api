<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannel;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants as VariantModel;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class Variants
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function create(mixed $root, array $req) : VariantModel
    {
        $variantDto = VariantDto::viaRequest($req['input']);
        $action = new CreateVariantsAction($variantDto, auth()->user());
        return $action->execute();
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function update(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $variant->update($req['input']);
        return $variant;
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function delete(mixed $root, array $req) : bool
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        return $variant->delete();
    }

    /**
     * addToWarehouse.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function addToWarehouse(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
        $variantWarehouses = VariantsWarehouses::from($req['input']);
        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }

    /**
     * removeToWarehouse.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function removeToWarehouse(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
        $variant->warehouses()->detach($warehouse->id);
        return $variant;
    }

    /**
     * addAttribute.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function addAttribute(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById($req['attributes_id']);
        (new AddAttributeAction($variant, $attribute, $req['input']['value']))->execute();
        return $variant;
    }

    /**
     * removeAttribute.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function removeAttribute(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById($req['attributes_id']);
        $variant->attributes()->detach($attribute->id);
        return $variant;
    }

    /**
     * addToChannel.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function addToChannel(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById($req['warehouses_id']);
        $channel = ChannelRepository::getById($req['channels_id']);
        $variantChannel = VariantChannel::from($req['input']);
        (new AddVariantToChannel($variant, $channel, $warehouse, $variantChannel))->execute();
        return $variant;
    }

    /**
     * removeChannel.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return VariantModel
     */
    public function removeChannel(mixed $root, array $req) : VariantModel
    {
        $variant = VariantsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        $channel = ChannelRepository::getById($req['channels_id']);
        $variant->channels()->detach($channel->id);
        return $variant;
    }
}
