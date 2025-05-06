<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

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
        $syncLead = new SyncLeadAction($this->lead);
        $eLead = $syncLead->execute();

        $formData = $message['data']['form'];
        $files = $this->lead->getFiles();
        $filesLinks = '';

        if (count($files) > 0) {
            foreach ($files as $file) {
                $filesLinks .= $file->url;
            }
        }

        //clean up milage
        $number = str_replace(',', '', $message['data']['form']['mileage']);

        $tradeIn = new TradeIn(
            (int) $formData['year'],
            $formData['make'] ?? '',
            $formData['model'] ?? '',
            isset($formData['trim']) ? substr($formData['trim'], 0, 50) : '',
            $formData['vin'],
            (int) $number ?? 0,
            $formData['int_color'] ?? '',
            $formData['ext_color'] ?? ''
        );

        $eLead->addTradeIn($tradeIn);

        return $tradeIn;
    }
}
