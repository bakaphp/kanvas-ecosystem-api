<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Actions;

use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\InsuranceOrderStatusEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

// Video requirements: full walkaround + VIN + odometer, max 1:30 (see CLAUDE.md).
class UploadInspectionDocumentsAction
{
    public function __construct(
        protected Order $order,
        protected ?string $matriculaPath = null,
        protected ?string $videoInspeccionPath = null,
    ) {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function execute(): array
    {
        $quoteNumber = (string) $this->order->get(CustomFieldEnum::QUOTE_NUMBER->value);

        if ($quoteNumber === '') {
            throw new ValidationException('Order has no Universal Seguros quote number to attach documents to');
        }

        $service = new UniversalSegurosService($this->order->app, $this->order->company);
        $results = [];

        if ($this->matriculaPath !== null) {
            $results[] = $service->uploadDocument(
                $quoteNumber,
                DocumentTransactionEnum::MATRICULA,
                DocumentOperationEnum::COTIZACION,
                $this->matriculaPath
            );
        }

        if ($this->videoInspeccionPath !== null) {
            $results[] = $service->uploadDocument(
                $quoteNumber,
                DocumentTransactionEnum::VIDEO_INSPECCION,
                DocumentOperationEnum::COTIZACION,
                $this->videoInspeccionPath
            );
        }

        if ($results !== []) {
            $this->order->set(CustomFieldEnum::STATUS->value, InsuranceOrderStatusEnum::DOCUMENTS_UPLOADED->value);
        }

        return $results;
    }
}
