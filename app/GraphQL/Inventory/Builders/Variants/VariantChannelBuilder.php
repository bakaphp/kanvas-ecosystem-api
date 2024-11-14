<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Traits\SearchWorkflowTrait;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Repositories\VariantsChannelRepository;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use stdClass;

class VariantChannelBuilder
{
    use SearchWorkflowTrait;

    public function allVariantsPublishedInChannel(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantsChannel = new VariantsChannels();
        $app = app(Apps::class);
        $companyBranch = app(CompaniesBranches::class);
        $region = Regions::getDefault($companyBranch->company, $app);
        if (! $userId = $app->get(AppEnums::fromName('DEFAULT_PUBLIC_SEARCH_USER_ID'))) {
            throw new ModelNotFoundException('User not found');
        }
        $user = Users::getById($userId);

        //set index
        //ModelsVariants::setSearchIndex((int) $channel->companies_id);
        $this->fireSearch(
            $app,
            $user,
            $companyBranch,
            $region,
            $args['search'] ?? ''
        );

        /**
         * @var Builder
         */
        return ModelsVariants::select(
            $variants->getTable() . '.*',
            DB::raw("'{$channel->name}' as channel_name"), //add channel name
            $variantsChannel->getTable() . '.price',
            $variantsChannel->getTable() . '.discounted_price',
            $variantsChannel->getTable() . '.is_published',
        )
        ->join($variantsChannel->getTable(), $variantsChannel->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
        ->where($variantsChannel->getTable() . '.channels_id', $channel->getId())
        ->where($variantsChannel->getTable() . '.is_deleted', 0)
        ->where($variantsChannel->getTable() . '.is_published', 1);
    }

    public function allVariantsPublishedInChannelFilterByAttributes(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $channelUuid = $args['id'];
        $attributes = $args['attributes'] ?? [];

        if (isset($attributes['price']) && ! is_array($attributes['price'])) {
            throw new ValidationException('Price must be an array');
        }

        if (isset($attributes['millage']) && ! is_array($attributes['millage'])) {
            throw new ValidationException('millage must be an array');
        }

        $channel = Channels::getByUuid($channelUuid);

        //set index
        //ModelsVariants::setSearchIndex((int) $channel->companies_id);

        /**
        * @var Builder
        */
        return VariantsChannelRepository::filterByAttributes(
            $channel->uuid,
            $attributes,
            $attributes['price'] ?? []
        );
    }

    /**
     * Format channel data from builder
     */
    public function getChannel(mixed $root, array $req): array
    {
        //@todo send the channel via header
        if (! isset($root->channel_name)) {
            try {
                $defaultChannelVariant = $root->getPriceInfoFromDefaultChannel();
                $root = new stdClass();
                $root->channel_name = $defaultChannelVariant->name;
                $root->price = $defaultChannelVariant->pivot->price;
                $root->discounted_price = $defaultChannelVariant->pivot->discounted_price;
                $root->is_published = $defaultChannelVariant->pivot->is_published;
            } catch (ModelNotFoundException $e) {
            }
        }

        //@todo doesnt work with search
        return [
            'name' => $root->channel_name,
            'price' => $root->price,
            'discounted_price' => $root->discounted_price,
            'is_published' => $root->is_published,
        ];
    }

    /**
     * Get filter variant by channel
     */
    public function getHasChannel(mixed $root, array $req): Collection
    {
        if (empty($req['HAS']['condition']['value'])) {
            return collect();
        }
        $channelUuid = $req['HAS']['condition']['value'];

        return $root->with(['channels' => function ($query) use ($channelUuid) {
            $query->where('uuid', $channelUuid);
        }])->get();
    }

    /**
     * Get channel price history
     */
    public function getChannelHistory(mixed $root): array
    {
        return $root->pricesHistory(
            'product_variants_warehouse_id',
            $root->pivot->product_variants_warehouse_id
        )->get()->toArray();
    }
}
