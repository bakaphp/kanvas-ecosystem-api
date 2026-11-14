<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Concerns\HasProvisionerDefaults;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Connectors\RespondIO\Actions\RetireLegacyRespondIOInboundAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

/**
 * Respond.io has no API for its webhooks: a person creates them in the workspace and pastes the URL
 * in. So nothing is registered or removed at the vendor here. What this does is check the token,
 * and move a workspace that was already posting to Kanvas onto the communication webhook without
 * anyone having to touch Respond.io.
 */
class RespondIOProvisioner implements Provisioner
{
    use HasProvisionerDefaults;

    #[Override]
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        $token = Str::trimToNull((string) (
            $integrationCompany->company->get(ConfigurationEnum::BEARER_TOKEN->value)
            ?? $app->get(ConfigurationEnum::BEARER_TOKEN->value)
        ));

        if ($token === null) {
            return 'The Respond.io bearer token is not set.';
        }

        return $this->tokenWorks($token) ? null : 'Respond.io rejected the stored bearer token.';
    }

    #[Override]
    public function legacyReceiverJobs(): array
    {
        return [RetireLegacyRespondIOInboundAction::RECEIVER_JOB];
    }

    /**
     * The receiver URL is the tenant's to paste into Respond.io; a workspace that already posts to
     * its old receiver keeps that URL (RetireLegacyRespondIOInboundAction).
     */
    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        return new InboundRegistration();
    }

    #[Override]
    public function retireLegacy(Connection $connection): array
    {
        return new RetireLegacyRespondIOInboundAction($connection)->execute();
    }

    protected function tokenWorks(string $token): bool
    {
        return Client::validateCredentials($token);
    }
}
