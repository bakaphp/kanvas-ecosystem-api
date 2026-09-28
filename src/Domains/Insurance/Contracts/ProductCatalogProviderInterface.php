<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceProduct;

/**
 * Opt-in: an insurer that can say which policies it sells. Not all expose it over
 * the wire — Universal's auto line is a fixed table in their doc, so their adapter
 * answers from an enum. The caller can't tell the difference.
 */
interface ProductCatalogProviderInterface
{
    /**
     * @return list<InsuranceProduct>
     */
    public function products(): array;
}
