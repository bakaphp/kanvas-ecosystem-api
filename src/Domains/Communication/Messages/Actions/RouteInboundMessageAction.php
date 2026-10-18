<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundRoute;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;

/**
 * Routes by the identity the vendor delivered to, through its claim — never by which receiver the
 * payload arrived at. Filing stays with the provider's job, which knows its payload; this decides
 * which connection the conversation is on and whether an automatic answer may go out.
 */
final class RouteInboundMessageAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly InboundMessage $inbound
    ) {
    }

    public function execute(): InboundRoute
    {
        $identity = $this->inbound->channel->normalizeAddress($this->inbound->inboundIdentity) ?? $this->inbound->inboundIdentity;

        $claim = InboundClaim::query()
            ->where('provider', $this->inbound->provider)
            ->where('inbound_identity', $identity)
            ->first();

        if ($claim !== null && (int) $claim->apps_id === $this->app->getId() && (int) $claim->companies_id === $this->company->getId()) {
            return new InboundRoute(InboundRoute::ROUTED, $this->channel(), $claim->connection);
        }

        if ($claim !== null) {
            return new InboundRoute(InboundRoute::UNROUTED, $this->channel(), reason: 'claimed_by_another_workspace');
        }

        return new InboundRoute(InboundRoute::NOT_PROVISIONED, $this->channel(), reason: 'identity_not_connected');
    }

    private function channel(): ChannelTypeEnum
    {
        return $this->inbound->channel;
    }
}
