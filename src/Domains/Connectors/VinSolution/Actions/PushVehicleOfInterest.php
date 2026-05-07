<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Vehicles\Interest;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

class PushVehicleOfInterest
{
    public function __construct(
        protected Lead $lead,
        protected Message $message,
    ) {
    }

    public function execute(): ?Interest
    {
        $vinCompany = Dealer::getById($this->lead->company->get(ConfigurationEnum::COMPANY->value), $this->lead->app);

        $vinUserId = $this->lead->user->get(ConfigurationEnum::getUserKey($this->lead->company, $this->lead->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->lead->app,
        );

        $message = $this->message->getMessage();
        $products = $message['data']['products'];
        $product = $products[0];

        //$vehicleInterest = Interest::getByLeadId($vinCompany, $user, $this->lead->get(CustomFieldEnum::LEADS->value));

        if (! isset($product['make']) || ! isset($product['model']) || ! isset($product['interested'])) {
            return null;
        }

        if ((bool) $product['interested'] == false) {
            return null;
        }

        $messageVehicleInterest = [
            'year' => $product['year'],
            'make' => $product['make'],
            'model' => $product['model'],
            'vin' => $product['vin'],
            'trim' => $product['trim'] ?? ' ',
            'stockNumber' => $product['stock_number'],
            'doors' => 4,
            'mileage' => $product['millage'] ?? 0,
            'sellingPrice' => $product['price'],
            'msrp' => $product['price'],
        ];

        //if (empty($vehicleInterest->items)) {
        return Interest::create(
            $vinCompany,
            $user,
            $this->lead->get(CustomFieldEnum::LEADS->value),
            $messageVehicleInterest
        );
    }
}
