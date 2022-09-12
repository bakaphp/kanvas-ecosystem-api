<?php

declare(strict_types=1);

namespace Kanvas\Locations\States\DataTransferObject;

use Kanvas\Contracts\DataTransferObject\CollectionResponseData as BaseCollectionResponseData;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Locations\States\DataTransferObject\SingleResponseData;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ResponseData class
 */
class CollectionResponseData extends BaseCollectionResponseData
{
    /**
     * Create new instance of DTO from request
     *
     * @param LengthAwarePaginator $paginatedCollection
     *
     * @return self
     *
     * @todo This implementation could be improved
     */
    public static function fromModelCollection(LengthAwarePaginator $paginatedCollection): self
    {
        $collectionArray = [];

        foreach ($paginatedCollection->getCollection() as $record) {
            $dto = SingleResponseData::fromModel($record);
            $collectionArray[] = $dto;
        }

        return new self(
            data: $collectionArray,
            currentPage: $paginatedCollection->currentPage(),
            total: $paginatedCollection->total()
        );
    }
}
