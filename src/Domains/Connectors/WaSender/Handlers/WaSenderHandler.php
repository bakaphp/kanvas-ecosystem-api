<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Handlers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BaseIntegration;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\WaSenderInbound;
use Kanvas\Connectors\WaSender\WaSenderProvisioner;
use Kanvas\Connectors\WaSender\WhatsAppTransport;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * WhatsApp through a WaSender session. A company's api key is its session's own, so a company has
 * one WaSender number, and that number is the connection.
 */
class WaSenderHandler extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections;

    #[Override]
    public function setup(): bool
    {
        $validated = $this->validateCredentials(
            $this->data['base_url'] ?? '',
            $this->data['api_key'] ?? '',
        );

        if (! $validated) {
            throw new ValidationException('Failed to validate WaSender connection');
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->company->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);

        return true;
    }

    protected function validateCredentials(string $baseUrl, string $apiKey): bool
    {
        if (empty($baseUrl) || empty($apiKey)) {
            throw new ValidationException('All WaSender configuration fields are required');
        }

        return Client::validateCredentials($baseUrl, $apiKey);
    }

    #[Override]
    public static function slug(): string
    {
        return 'wasender';
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::WASENDER;
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class];
    }

    /**
     * The number the session was linked with: saved on the integration row when one was selected
     * there, else the one an agent connected (ConnectWhatsAppSessionAction keeps it on the agent).
     * WaSender itself is never asked — a payload does not name our number either.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $agents = self::agents($integrationCompany);
        $connected = $agents->first(fn (Agent $agent): bool => self::connectedNumber($agent) !== null);
        $number = Str::trimToNull(Str::toE164((string) ($integrationCompany->config['phone_number'] ?? '')))
            ?? ($connected !== null ? self::connectedNumber($connected) : null);

        if ($number === null) {
            return [];
        }

        // Who answers: the agent that declares the number its own, else the one that linked the
        // session, else the one a legacy receiver named (carried onto the row at cutover).
        $owner = Agent::owningWhatsAppNumber($integrationCompany, $number)
            ?? $agents->first(fn (Agent $agent): bool => self::connectedNumber($agent) === $number)
            ?? $agents->firstWhere('id', (int) ($integrationCompany->config['agent_id'] ?? 0));

        $sessionId = $integrationCompany->config['session_id']
            ?? $owner?->get(ConnectionFieldEnum::SESSION_ID->value);

        return [new AccountSelection(
            key: $number,
            label: $number . ($owner !== null ? ' (' . $owner->name . ')' : ''),
            isDefault: true,
            attributes: array_filter([
                'agent_id' => $owner?->getId(),
                'session_id' => $sessionId !== null ? (int) $sessionId : null,
            ])
        )];
    }

    /**
     * The company's live WaSender connection, which is the one number it has.
     */
    public static function connectionFor(AppInterface $app, CompanyInterface $company): ?Connection
    {
        return Connection::query()
            ->live()
            ->fromApp($app->getId())
            ->fromCompany($company->getId())
            ->where('provider', self::slug())
            ->orderBy('id')
            ->first();
    }

    /**
     * Whether a session is this company's: the one its connection runs on, or one an agent of the
     * company linked. A session id alone names any session on the app's WaSender account.
     */
    public static function ownsSession(AppInterface $app, CompanyInterface $company, int $sessionId): bool
    {
        if ($sessionId <= 0) {
            return false;
        }

        if ((int) (self::connectionFor($app, $company)?->config['session_id'] ?? 0) === $sessionId) {
            return true;
        }

        return Agent::getByCustomFieldBuilderTransactionSafe(
            ConnectionFieldEnum::SESSION_ID->value,
            (string) $sessionId,
            $company
        )
            ->fromApp($app->getId())
            ->fromCompany($company->getId())
            ->notDeleted()
            ->exists();
    }

    /**
     * The receiver WaSender posts the company's session to. Its configuration is where the
     * session's secret and the group and assistant settings live.
     */
    public static function receiverFor(AppInterface $app, Companies $company): ?ReceiverWebhook
    {
        return new CommunicationReceivers()->find($app, $company, self::slug());
    }

    /**
     * Every WaSender receiver, for a caller that cannot name one app and one company: the cutover
     * command walks an app's, and an integration row from before may carry no app.
     *
     * @return Builder<ReceiverWebhook>
     */
    public static function receivers(): Builder
    {
        return ReceiverWebhook::query()
            ->notDeleted()
            ->where('configuration->communication->provider', self::slug())
            ->whereHas(
                'action',
                fn (Builder $query) => $query->where('model_name', ProcessCommunicationWebhookJob::class)
            )
            ->orderBy('id');
    }

    /**
     * What a receiver knows about the session posted to it, as the account an integration row
     * selects: who answers, which session, and that agent's number.
     *
     * @return array{phone_number?: string, agent_id?: int, session_id?: int}
     */
    public static function selectionFromReceiver(ReceiverWebhook $receiver): array
    {
        $agentId = (int) ($receiver->configuration['agent_id'] ?? 0);
        $agent = $agentId !== 0
            ? Agent::query()
                ->fromApp((int) $receiver->apps_id)
                ->fromCompany((int) $receiver->companies_id)
                ->notDeleted()
                ->whereKey($agentId)
                ->first()
            : null;

        return array_filter([
            'phone_number' => $agent !== null
                ? Str::trimToNull(Str::toE164((string) $agent->whatsappPhoneNumber())) ?? self::connectedNumber($agent)
                : null,
            'agent_id' => $agent?->getId(),
            'session_id' => isset($receiver->configuration['session_id'])
                ? (int) $receiver->configuration['session_id']
                : null,
        ]);
    }

    /**
     * @return Collection<int, Agent>
     */
    private static function agents(IntegrationsCompany $integrationCompany): Collection
    {
        return Agent::query()
            ->ofIntegration($integrationCompany)
            ->get();
    }

    /**
     * The number an agent linked its session with (ConnectWhatsAppSessionAction), in E.164. An
     * all-digits number comes back from custom-field storage as an int.
     */
    public static function connectedNumber(Agent $agent): ?string
    {
        $number = $agent->get(ConnectionFieldEnum::PHONE_NUMBER->value);

        return is_scalar($number) ? Str::trimToNull(Str::toE164((string) $number)) : null;
    }

    /**
     * Linking a number means scanning a QR with the phone, which no API call can do for the tenant.
     */
    #[Override]
    public static function onboardable(): bool
    {
        return false;
    }

    /**
     * WaSender documents no retry schedule; a receipt for a message a day old is the longest seen.
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.wasender', 72)),
            terminalEvents: ['failed', 'read']
        );
    }

    /**
     * Each session signs with its own secret and posts to its own receiver.
     */
    #[Override]
    public static function sharesAppReceiver(): bool
    {
        return false;
    }

    #[Override]
    public static function provisioner(): string
    {
        return WaSenderProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return WaSenderInbound::class;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [ConfigurationEnum::API_KEY->value];
    }
}
