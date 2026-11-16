<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Microsoft\Actions\EnsureMicrosoftOAuthReceiverAction;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Saving a Microsoft integration does not connect the account: it stores the connection's choices and
 * prepares the receiver whose URL (`oauth_url` on the integration) opens Microsoft's consent; the tokens
 * land on the connection's store when the consent completes. The row is ACTIVE from the save on, because
 * the Outlook activities look the integration up by ACTIVE status.
 */
abstract class BaseMicrosoftHandler extends BaseIntegration
{
    private ?ReceiverWebhook $receiver = null;

    private ?string $rootFolder = null;

    abstract protected function store(): MicrosoftTokenStore;

    /**
     * Throws when this user may not save the connection.
     */
    abstract protected function authorize(Users $user): void;

    #[Override]
    public function setup(): bool
    {
        $user = auth()->user();

        if (! $user instanceof Users) {
            throw new ValidationException('A user is required to prepare the Microsoft connection.');
        }

        $this->authorize($user);
        $store = $this->store();

        foreach ([ConfigurationEnum::CLIENT_ID, ConfigurationEnum::CLIENT_SECRET, ConfigurationEnum::TENANT_ID] as $setting) {
            if ($store->credential($setting) === '') {
                throw new ValidationException('Microsoft is not configured for this app: ' . $setting->value . ' is missing.');
            }
        }

        $this->storeRootFolder($store);

        $this->receiver = new EnsureMicrosoftOAuthReceiverAction(
            $this->app,
            $this->company,
            $user,
            $store,
            $this->redirectUrl()
        )->execute();

        return true;
    }

    #[Override]
    public function persistedConfig(): array
    {
        return [
            IntegrationsCompany::OAUTH_RECEIVER => $this->receiver?->uuid,
            'root_folder' => $this->rootFolder,
        ];
    }

    /**
     * Its URL would otherwise still connect an account after the integration is gone.
     */
    #[Override]
    public function removing(IntegrationsCompany $integrationCompany): void
    {
        $integrationCompany->getOAuthReceiver()?->softDelete();
    }

    /**
     * Everything the connection owns lives in its store, the app's included: the base teardown leaves
     * app settings alone, but the app's shared OneDrive is exactly what removing its integration ends.
     */
    #[Override]
    public function teardown(): void
    {
        parent::teardown();

        $this->store()->forget();
    }

    private function storeRootFolder(MicrosoftTokenStore $store): void
    {
        $value = $this->data['root_folder'] ?? null;

        try {
            $this->rootFolder = OneDriveClient::rootFolderFrom(is_string($value) ? $value : null);
        } catch (OneDriveException) {
            throw new ValidationException('root_folder may not contain "." or ".." segments.');
        }

        if ($this->rootFolder === null) {
            $store->del(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER);

            return;
        }

        $store->set(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER, $this->rootFolder);
    }

    /**
     * The frontend sends the screen to come back to; otherwise the app's default, and with neither
     * the consent ends on the API's JSON answer.
     */
    private function redirectUrl(): ?string
    {
        $url = $this->data['redirect_url'] ?? $this->app->get(ConfigurationEnum::OAUTH_RETURN_URL->value);

        if ($url === null || $url === '') {
            return null;
        }

        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('redirect_url must be an http or https URL.');
        }

        return $url;
    }
}
