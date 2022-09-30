<?php

declare(strict_types=1);

namespace Kanvas\Companies\DataTransferObject;

use Illuminate\Pagination\LengthAwarePaginator;
use Kanvas\Contracts\DataTransferObject\CollectionResponseData as BaseCollectionResponseData;

/**
 * ResponseData class.
 */
class CollectionResponseData extends BaseCollectionResponseData
{
    /**
     * Create new instance of DTO from request.
     *
     * @param LengthAwarePaginator $paginatedCollection
     *
     * @return self
     *
     * @todo This implementation could be improved
     */
    public static function fromModelCollection(LengthAwarePaginator $paginatedCollection) : self
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
