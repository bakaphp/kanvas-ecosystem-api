<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Prices;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Prices\Actions\CreatePrice;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price as PriceModel;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Stripe\Price as StripePrice;
use Stripe\Stripe;

class PriceMutation
{   
    private ?Apps $app = null;
    private ?UserInterface $user = null;

    /**
     * @todo move to middleware
     */
    public function validateStripe()
    {
        $this->app = app(Apps::class);
        $this->user = auth()->user();

        if (empty($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }
        Stripe::setApiKey($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
    }
    /**
     * create.
     */
    public function create(mixed $root, array $req): PriceModel
    {
        $this->validateStripe();
        $data = $req['input'];
        $stripePlan = PlanRepository::getByIdWithApp(($data['apps_plans_id']));

        $newPrice= StripePrice::create([
            'unit_amount' => $data['amount'] * 100,
            'currency' => $data['currency'],
            'recurring' => ['interval' => $data['interval']],
            'product' => $stripePlan->stripe_id,
        ]);

        $data['stripe_id'] = $newPrice->id;
        $dto = PriceDto::viaRequest($data, $this->user, $this->app);
        $action = new CreatePrice($dto, $this->user);
        return $action->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): PriceModel
    {
        $this->validateStripe();
        $data = $req['input'];
        $price = PriceRepository::getByIdWithApp((int)$req['id'], $this->app);

        StripePrice::update(
            $price->stripe_id,
            [
                'active' => $data['is_active']
            ]
        );

        $price->update($data);

        return $price;
    }
}
