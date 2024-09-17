<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\Providers\AuthorizeNetPaymentProcessor;

class DraftOrderManagementMutation
{
    public function create(mixed $root, array $request): array
    {
        $user = auth()->user();

        print_R($request);
        $customer = $request['input']['customer'];
        $customer['contacts'] = [
            [
                'contact' => $request['input']['email'],
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0
            ]
        ];
    
        print_r($customer); die();
      

        return [];
    }


}
