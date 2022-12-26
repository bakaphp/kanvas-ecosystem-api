<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantDto;
use Kanvas\Inventory\Variants\Models\Variants as VariantModel;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Actions\addAttributeAction;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannel;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;

class Variants
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function create(mixed $root, array $req): VariantModel
    {
        $variantDto = VariantDto::from([
            'products_id' => $req['input']['products_id'],
            'name' => $req['input']['name'],
            'description' => $req['input']['description'] ?? '',
            'short_description' => $req['input']['short_description'] ?? null,
            'html_description' => $req['input']['html_description'] ?? null,
            'warranty_terms' => $req['input']['warranty_terms'] ?? null,
            'upc' => $req['input']['upc'] ?? null,
            'categories' => $req['input']['categories'] ?? [],
            'warehouses' => $req['input']['warehouses'] ?? [],
        ]);
        $action = new CreateVariantsAction($variantDto);
        return $action->execute();
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function update(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $variant->update($req['input']);
        return $variant;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $req
     * @return bool
     */
    public function delete(mixed $root, array $req): bool
    {
        $variant = VariantsRepository::getById($req['id']);
        return $variant->delete();
    }

    /**
     * addToWarehouse
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function addToWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
        $variantWarehouses = VariantsWarehouses::from($req['input']);
        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }

    /**
     * removeToWarehouse
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function removeToWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
        $variant->warehouses()->detach($warehouse->id);
        return $variant;
    }

    /**
     * addAttribute
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function addAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $attribute = AttributesRepository::getById($req['attributes_id']);
        (new addAttributeAction($variant, $attribute, $req['input']['value']))->execute();
        return $variant;
    }

    /**
     * removeAttribute
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function removeAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $attribute = AttributesRepository::getById($req['attributes_id']);
        $variant->attributes()->detach($attribute->id);
        return $variant;
    }

    /**
     * addToChannel
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function addToChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $warehouse = WarehouseRepository::getById($req['warehouses_id']);
        $channel = ChannelRepository::getById($req['channels_id']);
        $variantChannel = VariantChannel::from($req['input']);
        (new AddVariantToChannel($variant, $channel, $warehouse, $variantChannel))->execute();
        return $variant;
    }
    
    /**
     * removeChannel
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function removeChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $channel = ChannelRepository::getById($req['channels_id']);
        $variant->channels()->detach($channel->id);
        return $variant;
    }
}
