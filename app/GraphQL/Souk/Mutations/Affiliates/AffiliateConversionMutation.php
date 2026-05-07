<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateManualAffiliateConversionAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateConversionAction;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateConversion as AffiliateConversionData;
use Kanvas\Souk\Affiliates\Enums\AffiliateConversionStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AttributionModelEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateConversion;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AffiliateConversionMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): AffiliateConversion
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::getById((int) $input['affiliate_id'], $app);

        /** @var Order $order */
        $order = Order::getById((int) $input['order_id'], $app);

        /** @var AffiliateLink|null $link */
        $link = isset($input['affiliate_link_id'])
            ? AffiliateLink::getById((int) $input['affiliate_link_id'], $app)
            : null;

        return new CreateManualAffiliateConversionAction(
            new AffiliateConversionData(
                app: $app,
                user: $user,
                affiliate: $affiliate,
                order: $order,
                order_total: (float) $input['order_total'],
                eligible_amount: (float) $input['eligible_amount'],
                commission_type: CommissionTypeEnum::from($input['commission_type']),
                commission_rate: (float) $input['commission_rate'],
                commission_amount: (float) $input['commission_amount'],
                link: $link,
                attribution_model: AttributionModelEnum::from($input['attribution_model'] ?? 'last_click'),
                status: AffiliateConversionStatusEnum::from($input['status'] ?? 'pending'),
                notes: $input['notes'] ?? null,
                converted_at: $input['converted_at'] ?? null,
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): AffiliateConversion
    {
        $app = app(Apps::class);
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateConversion $conversion */
        $conversion = AffiliateConversion::getById((int) $request['id'], $app);

        return new UpdateAffiliateConversionAction(
            conversion: $conversion,
            status: isset($input['status']) ? AffiliateConversionStatusEnum::from($input['status']) : null,
            confirmed: isset($input['confirmed']) ? (bool) $input['confirmed'] : null,
            notes: $input['notes'] ?? null,
            dispute_reason: $input['dispute_reason'] ?? null,
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);

        /** @var AffiliateConversion $conversion */
        $conversion = AffiliateConversion::getById((int) $request['id'], $app);

        $conversion->delete();

        return true;
    }
}
