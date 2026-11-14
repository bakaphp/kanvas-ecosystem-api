<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Services\RegionResolutionService;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

/**
 * A connection hangs off a company's integration with the provider, and some accounts come to
 * exist without the integration form: a WhatsApp number linked by QR, a mailbox on a Mailgun the
 * whole app shares. This gives the company the row, with the accounts it already has selected.
 */
final class EnsureProviderIntegrationAction
{
    /**
     * @param class-string<CommunicationProvider> $provider
     * @param array<string, mixed> $selection what the provider's `accounts()` reads off the row
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly UserInterface $user,
        private readonly string $provider,
        private readonly array $selection = []
    ) {
    }

    /**
     * @return IntegrationsCompany|null null when the provider is not in the integration catalog,
     *                                  or the company has no region in this app to file a new row under
     */
    public function execute(): ?IntegrationsCompany
    {
        $integration = Integrations::query()
            ->where('handler', $this->provider)
            ->whereIn('apps_id', [0, $this->app->getId()])
            ->where('is_deleted', 0)
            ->orderByDesc('apps_id')
            ->first();

        if ($integration === null) {
            return null;
        }

        // Whatever region it was integrated on: keying on the default one would give a company
        // that integrated on another a second row.
        $row = IntegrationsCompany::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->where('integrations_id', $integration->getId())
            ->notDeleted()
            ->first();

        if ($row === null) {
            return $this->create($integration);
        }

        // A new row schedules its own setup when it is saved; one already there only learns the
        // selection here.
        if (array_diff_assoc($this->selection, $row->config ?? []) !== []) {
            $row->config = array_merge($row->config ?? [], $this->selection);
            $row->saveOrFail();

            new ScheduleCommunicationSetupAction($this->app, $row)->execute();
        }

        return $row;
    }

    private function create(Integrations $integration): ?IntegrationsCompany
    {
        $status = Status::query()->where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->first();
        $region = new RegionResolutionService($this->app)->forCompany($this->company);

        if ($status === null || $region === null) {
            return null;
        }

        return new CreateIntegrationCompanyAction(
            new IntegrationsCompanyData(
                app: $this->app,
                integration: $integration,
                company: $this->company,
                region: $region,
                config: [],
                persistedConfig: $this->selection
            ),
            $this->user,
            $status
        )->execute();
    }
}
