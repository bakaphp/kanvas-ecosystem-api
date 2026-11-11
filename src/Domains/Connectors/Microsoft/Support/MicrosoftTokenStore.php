<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDriveEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Where one Microsoft connection keeps its tokens and settings. A company connects once for its mail and
 * its own OneDrive (one consent, tokens on the company); the app connects a OneDrive every company of the
 * app shares (tokens on the app, under keys that never collide with a company's).
 */
final class MicrosoftTokenStore
{
    public const string RECEIVER_CONNECTION = 'connection';

    public const string ONEDRIVE_CONNECTION = 'onedrive';

    private const string ONEDRIVE_SUFFIX = '-onedrive';

    /**
     * What a connection owns: what a disconnect removes. The mail sync cursor goes too, so a reconnect
     * starts from a fresh window instead of a stale one.
     */
    private const array CONNECTION_KEYS = [
        ConfigurationEnum::ACCESS_TOKEN,
        ConfigurationEnum::REFRESH_TOKEN,
        ConfigurationEnum::TOKEN_EXPIRES_AT,
        ConfigurationEnum::GRANTED_SCOPES,
        ConfigurationEnum::ONEDRIVE_ROOT_FOLDER,
        ConfigurationEnum::LAST_SYNC_AT,
    ];

    private function __construct(
        private readonly AppInterface $app,
        private readonly ?CompanyInterface $company,
    ) {
    }

    public static function forCompanyMail(AppInterface $app, CompanyInterface $company): self
    {
        return new self($app, $company);
    }

    public static function forAppOneDrive(AppInterface $app): self
    {
        return new self($app, null);
    }

    /**
     * The connection a consent receiver completes: the app's OneDrive when the receiver says so,
     * otherwise its company's mail (receivers made before OneDrive existed say nothing).
     */
    public static function forReceiver(ReceiverWebhook $receiver, AppInterface $app): self
    {
        $configuration = is_array($receiver->configuration) ? $receiver->configuration : [];

        return ($configuration[self::RECEIVER_CONNECTION] ?? null) === self::ONEDRIVE_CONNECTION
            ? self::forAppOneDrive($app)
            : self::forCompanyMail($app, $receiver->company);
    }

    public function get(ConfigurationEnum $key): string
    {
        return (string) $this->owner()->get($key->value . $this->suffix());
    }

    public function set(ConfigurationEnum $key, string $value): void
    {
        $this->owner()->set($key->value . $this->suffix(), $value);
    }

    public function forget(): void
    {
        foreach (self::CONNECTION_KEYS as $key) {
            $this->owner()->del($key->value . $this->suffix());
        }
    }

    public function del(ConfigurationEnum $key): void
    {
        $this->owner()->del($key->value . $this->suffix());
    }

    /**
     * Whether this connection can reach a drive: connected, and with file access granted. A company
     * that connected for mail only (or before file access was asked) has none.
     */
    public function hasFiles(): bool
    {
        return $this->get(ConfigurationEnum::REFRESH_TOKEN) !== ''
            && MicrosoftScopes::grantsFiles($this->get(ConfigurationEnum::GRANTED_SCOPES));
    }

    public function drive(): OneDriveDriveEnum
    {
        return $this->isAppOneDrive() ? OneDriveDriveEnum::APP : OneDriveDriveEnum::COMPANY;
    }

    /**
     * The `connection` its consent receiver carries; a company's carries none.
     */
    public function receiverConnection(): ?string
    {
        return $this->isAppOneDrive() ? self::ONEDRIVE_CONNECTION : null;
    }

    /**
     * App Registration settings. A company with its own registration overrides the app's for its mail;
     * the app's OneDrive connection always uses the app's registration.
     */
    public function credential(ConfigurationEnum $key): string
    {
        return (string) ($this->company?->get($key->value) ?? $this->app->get($key->value));
    }

    /**
     * Mail scopes stay what the app configured; OneDrive asks for files only.
     */
    public function authorizationScopes(): string
    {
        return $this->isAppOneDrive() ? MicrosoftScopes::ONEDRIVE : MicrosoftScopes::forAuthorization($this->app);
    }

    private function isAppOneDrive(): bool
    {
        return $this->company === null;
    }

    private function owner(): AppInterface|CompanyInterface
    {
        return $this->company ?? $this->app;
    }

    private function suffix(): string
    {
        return $this->isAppOneDrive()
            ? self::ONEDRIVE_SUFFIX
            : ConfigurationEnum::companySuffix($this->app, $this->company);
    }
}
