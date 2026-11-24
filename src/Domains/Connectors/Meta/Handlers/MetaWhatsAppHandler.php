<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Meta\Handlers;

use Baka\Contracts\BaseIntegration;
use Baka\Support\Str;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Kanvas\Communication\Connections\Actions\ReleaseUnusedRegistrationsAction;
use Kanvas\Communication\Connections\Concerns\ProvisionsConnections;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Connectors\Meta\Client;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
use Kanvas\Connectors\Meta\MetaInbound;
use Kanvas\Connectors\Meta\MetaProvisioner;
use Kanvas\Connectors\Meta\WhatsAppTransport;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use RuntimeException;

/**
 * WhatsApp Cloud API (Meta). As a Meta partner we get a WABA and a business token per company, kept
 * on the company, and the integration_companies row keeps which of the WABA's numbers it connected:
 * one integration, several numbers. The app secret that signs the webhooks is ours, not the
 * company's: an app setting.
 */
class MetaWhatsAppHandler extends BaseIntegration implements CommunicationProvider
{
    use ProvisionsConnections {
        removing as retireConnections;
    }

    /**
     * Where the row keeps the numbers the company connected, as `phone_number_id` and
     * `display_phone_number` pairs.
     */
    public const string SELECTION = 'phone_numbers';

    /** @var list<array{phone_number_id: string, display_phone_number: string}> */
    protected array $selection = [];

    /**
     * Connects `phone_number_id` beside the numbers the company already has. A `phone_number_ids`
     * that lists any is the whole selection instead: a connected number left out of it is released.
     */
    #[Override]
    public function setup(): bool
    {
        $wabaId = Str::trimToNull($this->data['waba_id'] ?? null);
        $requested = $this->requestedPhoneNumberIds();

        // The WABA too: its webhook subscription is what brings its numbers' messages in.
        if ($requested === [] || $wabaId === null) {
            throw new ValidationException('The WhatsApp phone number id and business account id are required.');
        }

        $connected = $this->connectedNumbers($wabaId);
        $accessToken = $this->accessToken($connected !== []);
        $available = $this->fetchPhoneNumbers($wabaId, $accessToken);

        foreach ($requested as $phoneNumberId) {
            if (! isset($available[$phoneNumberId])) {
                throw new ValidationException(
                    'WhatsApp number ' . $phoneNumberId . ' is not in WhatsApp Business Account ' . $wabaId . '.'
                );
            }
        }

        // A null or empty list is a form's untouched field, not a selection of nothing: it adds.
        // A number connected before that Meta no longer lists is gone there, so it is not kept.
        $replaces = (array) ($this->data['phone_number_ids'] ?? []) !== [];
        $kept = $replaces ? [] : array_column($connected, 'phone_number_id');
        $this->selection = array_values(array_intersect_key(
            $available,
            array_flip([...$kept, ...$requested])
        ));

        // The company's default number stays the first it connected, for as long as it keeps it.
        $current = (string) $this->company->get(ConfigurationEnum::PHONE_NUMBER_ID->value);
        $default = collect($this->selection)->firstWhere('phone_number_id', $current) ?? $available[$requested[0]];

        $this->company->set(ConfigurationEnum::PHONE_NUMBER_ID->value, $default['phone_number_id']);
        $this->company->set(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value, $default['display_phone_number']);
        $this->company->setEncrypted(ConfigurationEnum::ACCESS_TOKEN->value, $accessToken);
        $this->company->set(ConfigurationEnum::WABA_ID->value, $wabaId);

        // Answered at once: no window collecting a flurry into one turn, no random delay. A window
        // the company already chose is left alone.
        foreach ([ConfigurationEnum::BURST_IDLE_SECONDS, ConfigurationEnum::BURST_JITTER_SECONDS] as $window) {
            if ($this->company->get($window->value) === null) {
                $this->company->set($window->value, 0);
            }
        }

        return true;
    }

    /**
     * The numbers, not the credentials: which of the WABA's numbers this company communicates through.
     */
    #[Override]
    public function persistedConfig(): array
    {
        return $this->selection === [] ? [] : [self::SELECTION => $this->selection];
    }

    /**
     * The WABA is unsubscribed here rather than by the sweep: teardown() deletes the token the sweep
     * would need to reach Meta, and a WABA left subscribed keeps posting to the shared callback.
     */
    #[Override]
    public function removing(IntegrationsCompany $integrationCompany): void
    {
        $this->retireConnections($integrationCompany);

        new ReleaseUnusedRegistrationsAction($this->app)->releaseLeftBy(
            Connection::query()
                ->where('integrations_company_id', $integrationCompany->getId())
                ->pluck('id')
                ->all()
        );
    }

    #[Override]
    public static function slug(): string
    {
        return 'meta';
    }

    #[Override]
    public static function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::META_WHATSAPP;
    }

    #[Override]
    public static function transports(): array
    {
        return [ChannelTypeEnum::WHATSAPP->value => WhatsAppTransport::class];
    }

    /**
     * One account per connected number. Keyed by the number itself, in E.164, because that is what
     * a webhook names (`metadata.display_phone_number`) and what routes it to the connection. The
     * agent holding a number travels with it: that is who answers what arrives on it.
     */
    #[Override]
    public static function accounts(IntegrationsCompany $integrationCompany): array
    {
        $company = $integrationCompany->company;
        $wabaId = Str::trimToNull((string) $company->get(ConfigurationEnum::WABA_ID->value));
        $defaultId = (string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value);
        $accounts = [];

        foreach (self::selectedNumbers($integrationCompany) as $selected) {
            $number = Str::toE164($selected['display_phone_number']);

            if ($number === '') {
                continue;
            }

            $accounts[$number] ??= new AccountSelection(
                key: $number,
                label: $number,
                isDefault: $selected['phone_number_id'] === $defaultId,
                attributes: array_filter([
                    'phone_number_id' => $selected['phone_number_id'],
                    'waba_id' => $wabaId,
                    'agent_id' => Agent::owningWhatsAppNumber($integrationCompany, $number)?->getId(),
                ])
            );
        }

        return array_values($accounts);
    }

    /**
     * What the row keeps, else the one number in the company's settings: an integration completed
     * before a company could hold several has nothing on its row.
     *
     * @return list<array{phone_number_id: string, display_phone_number: string}>
     */
    public static function selectedNumbers(IntegrationsCompany $integrationCompany): array
    {
        $selected = [];

        foreach ((array) ($integrationCompany->config[self::SELECTION] ?? []) as $number) {
            $phoneNumberId = Str::trimToNull((string) ($number['phone_number_id'] ?? ''));
            $displayPhoneNumber = Str::trimToNull((string) ($number['display_phone_number'] ?? ''));

            if ($phoneNumberId !== null && $displayPhoneNumber !== null) {
                $selected[] = ['phone_number_id' => $phoneNumberId, 'display_phone_number' => $displayPhoneNumber];
            }
        }

        if ($selected !== []) {
            return $selected;
        }

        $company = $integrationCompany->company;
        $phoneNumberId = Str::trimToNull((string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $displayPhoneNumber = Str::trimToNull((string) $company->get(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value));

        return $phoneNumberId === null || $displayPhoneNumber === null
            ? []
            : [['phone_number_id' => $phoneNumberId, 'display_phone_number' => $displayPhoneNumber]];
    }

    #[Override]
    public static function onboardable(): bool
    {
        return true;
    }

    /**
     * Meta retries a webhook it could not deliver for up to 7 days, and `read` can follow
     * `delivered` at any point inside that.
     */
    #[Override]
    public static function eventRetention(): Retention
    {
        return new Retention(
            window: CarbonInterval::hours((int) config('communication.retention.meta', 168)),
            terminalEvents: ['failed', 'read']
        );
    }

    /**
     * Meta signs with the app secret and takes one callback for the whole app.
     */
    #[Override]
    public static function sharesAppReceiver(): bool
    {
        return true;
    }

    #[Override]
    public static function provisioner(): string
    {
        return MetaProvisioner::class;
    }

    #[Override]
    public static function inbound(): string
    {
        return MetaInbound::class;
    }

    protected function exchangeCode(string $code): string
    {
        try {
            return Client::exchangeCode($this->app, $code);
        } catch (RuntimeException $exception) {
            throw new ValidationException($exception->getMessage());
        } catch (RequestException $exception) {
            throw new ValidationException(
                'Meta rejected the signup code: '
                . ($exception->response->json('error.message') ?? $exception->getMessage())
            );
        } catch (ConnectionException) {
            // Its message carries the request URL, and the app secret travels in that query string.
            throw new ValidationException('Meta could not be reached to finish the signup. Try again.');
        }
    }

    /**
     * @return list<string>
     */
    protected function requestedPhoneNumberIds(): array
    {
        $requested = [];

        foreach ([$this->data['phone_number_id'] ?? null, ...(array) ($this->data['phone_number_ids'] ?? [])] as $id) {
            $id = is_scalar($id) ? Str::trimToNull((string) $id) : null;

            if ($id === null) {
                continue;
            }

            // Checked before Meta is asked anything: it is what the selection is matched on.
            if (! ctype_digit($id)) {
                throw new ValidationException('The WhatsApp phone number id must be numeric.');
            }

            $requested[] = $id;
        }

        return array_values(array_unique($requested));
    }

    /**
     * The numbers this company already has on the integration being saved. They are one WABA's,
     * reached with one token, so numbers of another WABA are refused rather than swapped in.
     *
     * @return list<array{phone_number_id: string, display_phone_number: string}>
     */
    protected function connectedNumbers(string $wabaId): array
    {
        // By id: given the model, fromCompany() widens to every company of the app on a request made
        // with the app key.
        $integrationCompany = IntegrationsCompany::query()
            ->fromApp($this->app->getId())
            ->fromCompany($this->company->getId())
            ->notDeleted()
            ->where('region_id', $this->region->getId())
            ->whereHas('integration', fn (Builder $query) => $query->where('name', self::integration()->value))
            ->first();

        $connected = $integrationCompany !== null ? self::selectedNumbers($integrationCompany) : [];
        $connectedWabaId = Str::trimToNull((string) $this->company->get(ConfigurationEnum::WABA_ID->value));

        if ($connected !== [] && $connectedWabaId !== null && $connectedWabaId !== $wabaId) {
            throw new ValidationException(
                'This company is connected to WhatsApp Business Account ' . $connectedWabaId
                . '. Disconnect the integration before connecting numbers of another account.'
            );
        }

        return $connected;
    }

    /**
     * An embedded signup hands over a code, never the token itself. A company adding a number to
     * the WABA it already connected may send neither: the token it has reaches that number too.
     */
    protected function accessToken(bool $alreadyConnected): string
    {
        $code = Str::trimToNull($this->data['code'] ?? null);
        $stored = $alreadyConnected
            ? Str::trimToNull((string) $this->company->get(ConfigurationEnum::ACCESS_TOKEN->value))
            : null;

        return Str::trimToNull($this->data['access_token'] ?? null)
            ?? ($code !== null ? $this->exchangeCode($code) : null)
            ?? $stored
            ?? throw new ValidationException('The WhatsApp access token, or the code from Meta\'s signup, is required.');
    }

    /**
     * The WABA's numbers Meta gave a display number for, by their id.
     *
     * @return array<string, array{phone_number_id: string, display_phone_number: string}>
     */
    protected function fetchPhoneNumbers(string $wabaId, string $accessToken): array
    {
        try {
            $numbers = Client::forApp($this->app, $accessToken)->phoneNumbers($wabaId);
        } catch (InvalidArgumentException) {
            throw new ValidationException('The WhatsApp business account id must be numeric.');
        } catch (RequestException $exception) {
            throw new ValidationException(
                'Meta rejected the WhatsApp business account id or access token: '
                . ($exception->response->json('error.message') ?? $exception->getMessage())
            );
        } catch (ConnectionException) {
            throw new ValidationException('Meta could not be reached to read the account\'s numbers. Try again.');
        }

        $available = [];

        foreach ($numbers as $number) {
            $phoneNumberId = (string) ($number['id'] ?? '');
            $displayPhoneNumber = Str::trimToNull($number['display_phone_number'] ?? null);

            if ($phoneNumberId !== '' && $displayPhoneNumber !== null) {
                $available[$phoneNumberId] = [
                    'phone_number_id' => $phoneNumberId,
                    'display_phone_number' => $displayPhoneNumber,
                ];
            }
        }

        return $available;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::PHONE_NUMBER_ID->value,
            ConfigurationEnum::ACCESS_TOKEN->value,
            ConfigurationEnum::WABA_ID->value,
            ConfigurationEnum::DISPLAY_PHONE_NUMBER->value,
            ConfigurationEnum::BURST_IDLE_SECONDS->value,
            ConfigurationEnum::BURST_JITTER_SECONDS->value,
        ];
    }
}
