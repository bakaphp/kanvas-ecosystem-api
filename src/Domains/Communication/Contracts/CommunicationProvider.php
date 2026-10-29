<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * Implemented by the integration handler of a vendor that can carry messages. Implementing it is the
 * whole opt-in: completing such an integration is what provisions communication, and an integration
 * whose handler does not implement it is never touched by this domain.
 *
 * Static, because a handler is constructed with the credentials a tenant just submitted and none of
 * this depends on them: it is asked of the vendor, long after the form is gone.
 */
interface CommunicationProvider
{
    /**
     * The vendor, as the provider registry and every communication row name it.
     */
    public static function slug(): string;

    public static function integration(): IntegrationsEnum;

    /**
     * @return array<value-of<ChannelTypeEnum>, class-string<Transport>> the transport serving each channel
     */
    public static function transports(): array;

    /**
     * The accounts selected on this integration, read from what the integration flow persisted on
     * the row — never from the vendor, which would turn every number on the account into a
     * connection.
     *
     * @return list<AccountSelection>
     */
    public static function accounts(IntegrationsCompany $integrationCompany): array;

    /**
     * False for a vendor whose inbound registration cannot be done through an API: it keeps working
     * for tenants already on it, but is not offered where integrating is supposed to be enough.
     */
    public static function onboardable(): bool;

    public static function eventRetention(): Retention;

    /**
     * True for a vendor that posts every tenant's webhooks to one URL and signs them with an
     * app-level secret (Meta): the app gets one receiver, and the workspace a message belongs to is
     * read from the claim on the identity it was delivered to. False for a vendor that signs with
     * each tenant's own credentials, which the receiver has to name before anything is checked.
     */
    public static function sharesAppReceiver(): bool;

    /**
     * Resolved through the container, so a test can stand a fake in for the vendor's API.
     *
     * @return class-string<Provisioner>
     */
    public static function provisioner(): string;

    /**
     * Its side of the communication webhook. Resolved through the container like the provisioner.
     *
     * @return class-string<InboundSource>
     */
    public static function inbound(): string;
}
