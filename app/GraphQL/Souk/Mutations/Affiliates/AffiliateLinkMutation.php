<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Affiliates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateLinkAction;
use Kanvas\Souk\Affiliates\Actions\UpdateAffiliateLinkAction;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateLink as AffiliateLinkData;
use Kanvas\Souk\Affiliates\Enums\AttributionModelEnum;
use Kanvas\Souk\Affiliates\Enums\CommissionTypeEnum;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Users\Models\Users;

class AffiliateLinkMutation
{
    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function create(mixed $rootValue, array $request): AffiliateLink
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::getByIdFromCompanyApp((int) $input['affiliate_id'], $company, $app);

        return new CreateAffiliateLinkAction(
            new AffiliateLinkData(
                app: $app,
                company: $company,
                user: $user,
                affiliate: $affiliate,
                name: $input['name'] ?? null,
                description: $input['description'] ?? null,
                custom_slug: $input['custom_slug'] ?? null,
                short_code: $input['short_code'] ?? null,
                destination_url: $input['destination_url'] ?? null,
                link_type: $input['link_type'] ?? 'general',
                config: $input['config'] ?? null,
                tracking_method: $input['tracking_method'] ?? 'utm_params',
                cookie_duration_days: (int) ($input['cookie_duration_days'] ?? 30),
                attribution_window_days: (int) ($input['attribution_window_days'] ?? 30),
                attribution_model: AttributionModelEnum::from($input['attribution_model'] ?? 'last_click'),
                override_commission: (bool) ($input['override_commission'] ?? false),
                commission_type: isset($input['commission_type']) ? CommissionTypeEnum::from($input['commission_type']) : null,
                commission_rate: isset($input['commission_rate']) ? (float) $input['commission_rate'] : null,
                commission_note: $input['commission_note'] ?? null,
                is_active: (bool) ($input['is_active'] ?? true),
                is_approved: (bool) ($input['is_approved'] ?? false),
                approval_notes: $input['approval_notes'] ?? null,
                configuration: $input['configuration'] ?? null,
            ),
        )->execute();
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function update(mixed $rootValue, array $request): AffiliateLink
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AffiliateLink $link */
        $link = AffiliateLink::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateAffiliateLinkAction(
            $link,
            new AffiliateLinkData(
                app: $app,
                company: $company,
                user: $user,
                affiliate: $link->affiliate,
                name: $input['name'] ?? $link->name,
                description: $input['description'] ?? $link->description,
                custom_slug: $input['custom_slug'] ?? $link->custom_slug,
                short_code: $input['short_code'] ?? $link->short_code,
                destination_url: $input['destination_url'] ?? $link->destination_url,
                link_type: $input['link_type'] ?? $link->link_type,
                config: $input['config'] ?? $link->config,
                tracking_method: $input['tracking_method'] ?? $link->tracking_method,
                cookie_duration_days: (int) ($input['cookie_duration_days'] ?? $link->cookie_duration_days),
                attribution_window_days: (int) ($input['attribution_window_days'] ?? $link->attribution_window_days),
                attribution_model: AttributionModelEnum::from($input['attribution_model'] ?? $link->attribution_model),
                override_commission: (bool) ($input['override_commission'] ?? $link->override_commission),
                commission_type: isset($input['commission_type']) ? CommissionTypeEnum::from($input['commission_type']) : ($link->commission_type !== null ? CommissionTypeEnum::from($link->commission_type) : null),
                commission_rate: isset($input['commission_rate']) ? (float) $input['commission_rate'] : $link->commission_rate,
                commission_note: $input['commission_note'] ?? $link->commission_note,
                is_active: (bool) ($input['is_active'] ?? $link->is_active),
                is_approved: (bool) ($input['is_approved'] ?? $link->is_approved),
                approval_notes: $input['approval_notes'] ?? $link->approval_notes,
                configuration: $input['configuration'] ?? $link->configuration,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var AffiliateLink $link */
        $link = AffiliateLink::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $link->delete();

        return true;
    }
}
