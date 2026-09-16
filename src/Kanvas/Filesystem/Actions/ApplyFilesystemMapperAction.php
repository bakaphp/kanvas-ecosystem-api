<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Users\Contracts\UserInterface;
use Closure;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Filesystem\Services\FilesystemMapperWalkerService;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductData;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Spatie\LaravelData\DataCollection;

/**
 * Applies one `FilesystemMapper` to one raw source record, whatever produced it — a CSV row, a
 * connector's API response, a webhook payload.
 *
 * `$correlatedRecords` covers the caller that already holds every related raw record (a bulk pull);
 * `$relatedRecordFetcher` covers the caller that holds only the primary one (a single webhook
 * event) and must fetch the rest on demand. This class never talks to an external API itself, so a
 * new connector can reuse it by supplying its own fetcher.
 */
class ApplyFilesystemMapperAction
{
    protected FilesystemMapperWalkerService $walker;
    protected Apps $app;
    protected Companies $company;

    /**
     * @param array<string, mixed> $rawData
     * @param array<int, array<string, mixed>> $correlatedRecords keyed by linked mapper id
     * @param Closure(string, string, string): (array<string, mixed>|null)|null $relatedRecordFetcher
     * @param array<int, int> $visitedMapperIds mapper ids already visited on this call chain — guards
     *        against infinite recursion when a mapper's `configuration.links` forms a cycle
     */
    public function __construct(
        protected UserInterface $user,
        protected FilesystemMapper $mapper,
        protected string $primaryId,
        protected array $rawData,
        protected array $correlatedRecords = [],
        protected ?Closure $relatedRecordFetcher = null,
        protected array $visitedMapperIds = [],
    ) {
        $this->walker = new FilesystemMapperWalkerService();
        $this->app = $mapper->app;
        $this->company = $mapper->company;
    }

    public function execute(): Products|People
    {
        $mapped = $this->walker->walk($this->mapper->mapping, $this->rawData);

        $entity = match ($this->mapper->systemModule->model_name) {
            Products::class => $this->syncProduct($mapped),
            People::class => $this->syncPeople($mapped),
            default => throw new ValidationException(
                'ApplyFilesystemMapperAction does not support entity type: ' . $this->mapper->systemModule->model_name,
            ),
        };

        $this->applyLinks($entity);

        return $entity;
    }

    private function applyLinks(Products|People $entity): void
    {
        foreach ($this->configuration()['links'] ?? [] as $link) {
            $linkedMapperId = (int) ($link['mapper_id'] ?? 0);
            $linkField = (string) ($link['link_field'] ?? '');

            if ($linkedMapperId === 0 || $linkField === '' || in_array($linkedMapperId, $this->visitedMapperIds, true)) {
                continue;
            }

            $relatedRaw = $this->correlatedRecords[$linkedMapperId] ?? $this->fetchRelatedRecord($link);
            if ($relatedRaw === null) {
                continue;
            }

            $linkedMapper = FilesystemMapper::getByIdFromCompanyApp($linkedMapperId, $this->company, $this->app);

            $linkedEntity = new self(
                user: $this->user,
                mapper: $linkedMapper,
                primaryId: (string) ($relatedRaw['Id'] ?? ''),
                rawData: $relatedRaw,
                visitedMapperIds: [...$this->visitedMapperIds, $this->mapper->getId()],
            )->execute();

            $entity->set($linkField, $linkedEntity->getId());
        }
    }

    private function configuration(): array
    {
        return is_array($this->mapper->configuration) ? $this->mapper->configuration : [];
    }

    /**
     * The custom field holding the source system's own id for this record. Without it a re-run of
     * the same source record can only be matched on mutable data (a product's name, a person's
     * email), which duplicates the row as soon as that data changes upstream.
     */
    private function externalIdField(): string
    {
        $field = (string) ($this->configuration()['external_id_field'] ?? '');

        if ($field === '') {
            throw new ValidationException(
                'FilesystemMapper ' . $this->mapper->getId()
                . ' requires configuration.external_id_field so records can be matched on re-import.',
            );
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>|null
     */
    private function fetchRelatedRecord(array $link): ?array
    {
        if ($this->relatedRecordFetcher === null) {
            return null;
        }

        $sourceObject = (string) ($link['source_object'] ?? '');
        $matchField = (string) ($link['match_field'] ?? '');

        if ($sourceObject === '' || $matchField === '') {
            return null;
        }

        return ($this->relatedRecordFetcher)($sourceObject, $matchField, $this->primaryId);
    }

    private function syncProduct(array $mapped): Products
    {
        $externalIdField = $this->externalIdField();
        $productType = ProductsTypesRepository::getFromConfiguredId(
            $this->configuration()['product_type_id'] ?? null,
            $this->company,
            $this->app,
        );

        // Transaction-safe variant: CreateProductAction opens its own `inventory` transaction, and
        // the plain builder joins `apps_custom_fields` across connections — see HasCustomFields.
        /** @var Products|null $existing */
        $existing = Products::getByCustomFieldTransactionSafe($externalIdField, $this->primaryId, $this->company);

        $productData = new ProductData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: (string) ($mapped['name'] ?? ''),
            description: $mapped['description'] ?? null,
            productsType: $productType,
            // CreateProductAction matches on slug, so reusing the existing product's slug is what
            // turns this into an update rather than a second row under a renamed source record.
            slug: $existing?->slug ?? $mapped['slug'] ?? null,
            sku: $mapped['sku'] ?? null,
            attributes: $mapped['attributes'] ?? [],
        );

        $product = new CreateProductAction($productData, $this->user)->execute();
        $product->set($externalIdField, $this->primaryId);

        return $product;
    }

    private function syncPeople(array $mapped): People
    {
        $contacts = [];
        if (! empty($mapped['email'])) {
            $contacts[] = ['value' => $mapped['email'], 'contacts_types_id' => ContactTypeEnum::EMAIL->value, 'weight' => 0];
        }
        if (! empty($mapped['phone'])) {
            $contacts[] = ['value' => $mapped['phone'], 'contacts_types_id' => ContactTypeEnum::PHONE->value, 'weight' => 0];
        }

        $peopleData = new PeopleData(
            app: $this->app,
            branch: $this->mapper->branch,
            user: $this->user,
            firstname: (string) ($mapped['firstname'] ?? ''),
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: (string) ($mapped['lastname'] ?? ''),
            custom_fields: [$this->externalIdField() => $this->primaryId],
            runWorkflow: false,
            // Matching is handled by the external id below — a shared phone/email with an
            // unrelated existing People is a duplicate for the merge flow to catch, not a reason
            // to fold this record into that one.
            skipDuplicateContactCheck: true,
        );

        return new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();
    }
}
