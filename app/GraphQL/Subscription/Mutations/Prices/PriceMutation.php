<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Prices;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Prices\Actions\CreatePrice;
use Kanvas\Subscription\Prices\Actions\UpdatePrice;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price as PriceModel;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
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
        $stripePlan = PlanRepository::getByIdWithApp((int)$data['apps_plans_id'], $this->app);
        $data['stripe_id'] = $stripePlan->stripe_id;

        $dto = PriceDto::viaRequest($data, $this->user, $this->app);
        $action = new CreatePrice($dto);
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
        $data['stripe_id'] = $price->stripe_id;

        $dto = PriceDto::viaRequest($data, $this->user, $this->app);
        $action = new UpdatePrice($price, $dto);
        return $action->execute();
    }
}
