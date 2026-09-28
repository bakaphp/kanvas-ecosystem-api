<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\DocumentUploadResult;
use Kanvas\Insurance\DataTransferObject\InsuranceDocument;
use Kanvas\Souk\Orders\Models\Order;

interface InspectionProviderInterface
{
    /**
     * @param list<InsuranceDocument> $documents
     */
    public function uploadDocuments(Order $order, array $documents): DocumentUploadResult;

    /**
     * Whether the product quoted on this order still needs an inspection before it
     * can be emitted. Lets the graph block a premature emit without knowing the
     * insurer's product catalog.
     */
    public function requiresInspection(Order $order): bool;
}
