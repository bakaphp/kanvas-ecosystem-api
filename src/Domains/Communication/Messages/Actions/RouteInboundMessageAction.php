<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundRoute;
use Kanvas\Companies\Models\Companies;

/**
 * Routes by the identity the vendor delivered to, through its claim — never by which receiver the
 * payload arrived at. Filing stays with the provider's job, which knows its payload; this decides
 * which workspace and connection the conversation is on and whether an automatic answer may go out.
 *
 * $app and $company are what the receiver serves. Both are null for the global receiver, which
 * serves every app and workspace and so takes both from the claim.
 */
final class RouteInboundMessageAction
{
    public function __construct(
        private readonly ?Apps $app,
        private readonly ?Companies $company,
        private readonly InboundMessage $inbound
    ) {
    }

    public function execute(): InboundRoute
    {
        $claim = InboundClaim::forIdentity(
            $this->inbound->provider,
            $this->inbound->channel,
            $this->inbound->inboundIdentity
        );

        if ($claim !== null && $this->serves($claim)) {
            return new InboundRoute(
                InboundRoute::ROUTED,
                $this->inbound->channel,
                $claim->company,
                $claim->connection
            );
        }

        if ($claim !== null) {
            return new InboundRoute(
                InboundRoute::UNROUTED,
                $this->inbound->channel,
                company: null,
                reason: 'claimed_by_another_workspace'
            );
        }

        return new InboundRoute(
            InboundRoute::NOT_PROVISIONED,
            $this->inbound->channel,
            $this->company,
            reason: 'identity_not_connected'
        );
    }

    private function serves(InboundClaim $claim): bool
    {
        return ($this->app === null || (int) $claim->apps_id === $this->app->getId())
            && ($this->company === null || (int) $claim->companies_id === $this->company->getId());
    }
}
