<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants as VariantModel;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class Variants
{
    /**
     * create.
     */
    public function create(mixed $root, array $req): VariantModel
    {
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById(
                (int) $req['input']['status']['id'],
                auth()->user()->getCurrentCompany()
            )->getId();
        }

        $variantDto = VariantDto::viaRequest($req['input'], auth()->user());
        $action = new CreateVariantsAction($variantDto, auth()->user());
        $variantModel = $action->execute();
        $company = $variantDto->product->company()->get()->first();

        if (isset($req['input']['attributes'])) {
            $variantModel->addAttributes(auth()->user(), $req['input']['attributes']);
        }
        if (! $variantDto->warehouse_id) {
            $variantDto->warehouse_id = Warehouses::getDefault($company)->getId();
        }
        $warehouse = WarehouseRepository::getById($variantDto->warehouse_id, $company);

        if (isset($req['input']['warehouse']['status'])) {
            $status = StatusRepository::getById(
                (int) $req['input']['warehouse']['status']['id'],
                $company
            )->getId();
        } else {
            $status = Status::getDefault($company);
        }
        $req['input']['warehouse']['status_id'] = $status ? $status->getId() : null;

        if (! empty($variantDto->files)) {
            foreach ($variantDto->files as $file) {
                $variantModel->addFileFromUrl($file['url'], $file['name']);
            }
        }
        $variantWarehouses = VariantsWarehouses::viaRequest($req['input']['warehouse'] ?? []);

        (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();

        if (isset($req['input']['channels'])) {
            foreach ($req['input']['channels'] as $variantChannel) {
                $warehouse = WarehouseRepository::getById((int) $variantChannel['warehouses_id']);
                $channel = ChannelRepository::getById((int) $variantChannel['channels_id']);
                $variantChannelDto = VariantChannel::from($variantChannel);

                VariantService::addVariantChannel(
                    $variantModel,
                    $warehouse,
                    $channel,
                    $variantChannelDto
                );
            }
        }
        return $variantModel;
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): VariantModel
    {
        $company = auth()->user()->getCurrentCompany();
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], $company)->getId();
        }

        $variant = VariantsRepository::getById((int) $req['id'], $company);
        $variant->update($req['input']);

        if (isset($req['input']['attributes'])) {
            $variant->addAttributes(auth()->user(), $req['input']['attributes']);
        }

        if (isset($req['input']['warehouse'])) {
            $warehouse = WarehouseRepository::getById((int) $req['input']['warehouse']['warehouse_id'], $company);
            VariantService::updateWarehouseVariant($variant, $warehouse, $req['input']['warehouse']);
        }

        return $variant;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        return $variant->delete();
    }

    /**
     * addToWarehouse.
     */
    public function addToWarehouse(mixed $root, array $req): VariantModel
    {
        $company = auth()->user()->getCurrentCompany();
        $variant = VariantsRepository::getById((int) $req['id'], $company);

        $warehouse = WarehouseRepository::getById((int) $req['input']['warehouse_id']);
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], $company)->getId();
        }
        $variantWarehouses = VariantsWarehouses::viaRequest($req['input']);

        (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();

        return $variant;
    }

    /**
     * updateVariantInWarehouse.
     */
    public function updateVariantInWarehouse(mixed $root, array $req): VariantModel
    {
        $company = auth()->user()->getCurrentCompany();

        $variant = VariantsRepository::getById((int) $req['id'], $company);
        $warehouse = WarehouseRepository::getById((int) $req['input']['warehouse_id'], $company);

        return VariantService::updateWarehouseVariant($variant, $warehouse, $req['input']);
    }

    /**
     * @todo Remove and use softdelete.
     * removeToWarehouse.
     */
    public function removeToWarehouse(mixed $root, array $req): VariantModel
    {
        $company = auth()->user()->getCurrentCompany();

        $variant = VariantsRepository::getById((int) $req['id'], $company);

        $warehouse = WarehouseRepository::getById($req['warehouse_id'], $company);
        $variant->warehouses()->detach($warehouse);

        return $variant;
    }

    /**
     * addAttribute.
     */
    public function addAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById((int) $req['attributes_id']);
        (new AddAttributeAction($variant, $attribute, $req['input']['value']))->execute();

        return $variant;
    }

    /**
     * @todo Remove and use softdelete.
     * removeAttribute.
     */
    public function removeAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById((int) $req['attributes_id']);
        $variant->attributes()->detach($attribute);

        return $variant;
    }

    /**
     * addToChannel.
     */
    public function addToChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['variants_id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['warehouses_id']);
        $channel = ChannelRepository::getById((int) $req['channels_id']);
        $variantChannelDto = VariantChannel::from($req['input']);

        VariantService::addVariantChannel(
            $variant,
            $warehouse,
            $channel,
            $variantChannelDto
        );

        return $variant;
    }

    /**
     * update variant In channels.
     */
    public function updateVariantInChannel(mixed $root, array $req)
    {
        $variant = VariantsRepository::getById((int) $req['variants_id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['warehouses_id'], auth()->user()->getCurrentCompany());
        $channel = ChannelRepository::getById((int) $req['channels_id'], auth()->user()->getCurrentCompany());

        $variantChannel = VariantsChannels::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->where('channels_id', $channel->getId())
            ->firstOrFail();

        VariantService::updateVariantChannel(
            $variantChannel,
            $req['input']
        );

        return $variant;
    }

    /**
     * @todo Remove and use softdelete.
     * removeChannel.
     * @todo Use softdelete and cascade softdelete and remove detach
     */
    public function removeChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['variants_id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['warehouses_id']);
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();
        $channel = ChannelRepository::getById((int) $req['channels_id']);
        $variantWarehouses->channels()->where('id', $channel->getId())->detach($channel->id);

        return $variant;
    }
}
