<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Support\Arr;
use Kanvas\Connectors\Elead\DataTransferObject\TradeIn;
use Kanvas\Guild\Leads\Models\Lead;

class AddTradeInAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message): TradeIn
    {
        $eLead = new SyncLeadAction($this->lead)->execute();

        $formData = $this->resolveFormData($message['data'] ?? []);
        $mileage = str_replace(',', '', (string) ($formData['mileage'] ?? $formData['odometer'] ?? '0'));

        $tradeIn = new TradeIn(
            (int) ($formData['year'] ?? 0),
            $formData['make'] ?? '',
            $formData['model'] ?? '',
            isset($formData['trim']) ? substr($formData['trim'], 0, 50) : '',
            $formData['vin'] ?? '',
            (int) $mileage,
            $formData['int_color'] ?? $formData['interior_color'] ?? '',
            $formData['ext_color'] ?? $formData['exterior_color'] ?? ''
        );

        $eLead->addTradeIn($tradeIn);

        return $tradeIn;
    }

    /**
     * Web forms send `data.form` keyed by field; the browser extension sends `data` as a
     * `[{label, value}]` list (VIN, Odometer, Exterior color, ...).
     */
    protected function resolveFormData(array $data): array
    {
        return $data['form'] ?? Arr::fromLabelValuePairs($data);
    }
}
