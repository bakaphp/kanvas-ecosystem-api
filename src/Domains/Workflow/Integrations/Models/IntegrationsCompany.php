<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Baka\Contracts\BaseIntegration;
use Baka\Support\AllowedClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\Importable;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportOption;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use RuntimeException;

/**
 * @property int $id
 * @property int|null $apps_id null on rows written before the column existed
 * @property int $companies_id
 * @property int $integrations_id
 * @property int $status_id
 * @property int $region_id
 * @property array|null $config
 * @property bool $is_active
 */
class IntegrationsCompany extends BaseModel
{
    /**
     * Config key an OAuth handler stores its receiver's uuid under, from persistedConfig().
     */
    public const string OAUTH_RECEIVER = 'oauth_receiver_uuid';

    protected $table = 'integration_companies';

    protected $fillable = [
        'apps_id',
        'companies_id',
        'integrations_id',
        'status_id',
        'region_id',
        'config',
        'is_active',
    ];

    protected $attributes = [
        'is_deleted' => 0,
        'is_active' => 1,
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integrations_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'region_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(EntityIntegrationHistory::class, 'integrations_company_id');
    }

    /**
     * The receiver an OAuth integration's consent starts from, when its handler created one.
     */
    public function getOAuthReceiver(): ?ReceiverWebhook
    {
        $uuid = $this->config[self::OAUTH_RECEIVER] ?? null;
        $company = $this->company;

        if (! is_string($uuid) || $uuid === '' || ! $company instanceof Companies) {
            return null;
        }

        return ReceiverWebhook::query()
            ->where('uuid', $uuid)
            ->fromCompany($company)
            ->notDeleted()
            ->first();
    }

    public function getOAuthUrl(): ?string
    {
        return $this->getOAuthReceiver()?->getOAuthUrl();
    }

    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }

    public function isActive(bool $isActive): bool
    {
        $this->is_active = $isActive;
        $this->saveOrFail();

        return $this->is_active;
    }

    public function isImportable(): bool
    {
        return $this->is_active && $this->status?->slug === StatusEnum::ACTIVE->value;
    }

    /**
     * @return list<Importable>
     */
    public function importables(): array
    {
        return BaseIntegration::forIntegrationCompany($this, $this->app ?? app(Apps::class))?->importables() ?? [];
    }

    /**
     * What the client needs to render the import screen. Declared in code, so it never calls the
     * external system.
     *
     * @return list<array{entity: string, label: string, options: list<array{name: string, type: string, label: string, required: bool, multiple: bool}>, last_import: ?IntegrationImport}>
     */
    public function getImportables(): array
    {
        if (! $this->isImportable()) {
            return [];
        }

        return array_map(fn (Importable $importable) => [
            'entity' => $importable->entity,
            'label' => $importable->label,
            'options' => array_map(fn (ImportOption $option) => [
                'name' => $option->name,
                'type' => $option->type->value,
                'label' => $option->label,
                'required' => $option->required,
                'multiple' => $option->multiple,
            ], $importable->options),
            'last_import' => IntegrationImport::latestFor($this, $importable->entity),
        ], $this->importables());
    }

    public function importerFor(string $entity, Users $user): IntegrationImporter
    {
        $importable = $this->importableFor($entity)
            ?? throw new RuntimeException(sprintf('The integration no longer offers %s.', $entity));
        $class = AllowedClass::resolve($importable->importer, IntegrationImporter::class)
            ?? throw new RuntimeException(sprintf('%s is not an allowed importer.', $importable->importer));

        return new $class($this, $user);
    }

    public function importableFor(string $entity): ?Importable
    {
        return array_find($this->importables(), fn (Importable $importable) => $importable->entity === $entity);
    }

    public static function getManageable(int $id, AppInterface $app): self
    {
        $integrationCompany = self::getById($id);

        if (! $integrationCompany->isManageableFrom($app)) {
            throw new ModelNotFoundException('Integration not found for this app.');
        }

        return $integrationCompany;
    }

    /**
     * A row with no recorded app predates app ownership. It is not this app's row, but nothing says
     * it is another app's either, so it stays manageable from any app the company lives in — which is
     * what every row allowed before — until its owner is established.
     */
    public function isManageableFrom(AppInterface $app): bool
    {
        return $this->apps_id === null || (int) $this->apps_id === (int) $app->getId();
    }

    /**
     * Get the integration company using the integration name.
     *
     * With $app the lookup is strict: only that app's row, never a row with no recorded app. Without
     * it the lookup is the pre-ownership one, kept for the connectors that have not been moved yet.
     *
     * @param Status $status Current status of the integration company
     * @param string $name name of the integration
     * @param Regions $region The region of the company integration
     */
    public static function getByIntegration(
        Companies $company,
        Status $status,
        string $name,
        Regions $region,
        ?AppInterface $app = null
    ): ?IntegrationsCompany {
        $integration = Integrations::getByName($name);

        return IntegrationsCompany::fromCompany($company)
            ->when($app, fn ($query) => $query->fromApp($app))
            ->where('integrations_id', $integration->getId())
            ->where('status_id', $status->getId())
            ->where('region_id', $region->getId())
            ->where('is_active', 1)
            ->first();
    }
}
