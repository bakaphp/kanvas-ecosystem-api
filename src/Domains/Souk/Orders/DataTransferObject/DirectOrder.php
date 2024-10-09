<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Darryldecode\Cart\Cart;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\DataTransferObject\Profile;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class DirectOrder extends Data
{
    public function __construct(
        public Apps $app,
        public Users $user,
        public CreditCard $creditCard,
        public Cart $cart,
        public ?Profile $profile = null
    ) {
    }
}
