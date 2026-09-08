<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Closure;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Filesystem\Services\FilesystemMapperWalker;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
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
 * Interprets one `FilesystemMapper` (mapping + configuration.links) against one raw source
 * record and creates/updates the Kanvas entity it describes — generic across whatever produced
 * the raw record (a CSV row, a connector's live API response, a webhook payload). Reused instead
 * of duplicating `ImportProductFromFilesystemAction`'s Product-only mapping logic per connector.
 *
 * `$correlatedRecords` (keyed by linked mapper id) covers the case where the caller already has
 * every related raw record on hand (a bulk pull). `$relatedRecordFetcher` covers the case where
 * only the primary record is known yet (a single webhook event) — the caller supplies a closure
 * that knows how to fetch a related record from its own source; this class never talks to any
 * external API itself, keeping it usable by future connectors without modification.
 */
class ApplyFilesystemMapperAction
{
    protected FilesystemMapperWalker $walker;

    /**
     * @param array<string, mixed> $rawData
     * @param array<int, array<string, mixed>> $correlatedRecords keyed by linked mapper id
     * @param Closure(string, string, string): (array<string, mixed>|null)|null $relatedRecordFetcher
     * @param array<int, int> $visitedMapperIds mapper ids already visited on this call chain — guards
     *        against infinite recursion when a mapper's `configuration.links` forms a cycle
     */
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected FilesystemMapper $mapper,
        protected string $primaryId,
        protected array $rawData,
        protected array $correlatedRecords = [],
        protected ?Closure $relatedRecordFetcher = null,
        protected array $visitedMapperIds = [],
    ) {
        $this->walker = new FilesystemMapperWalker();
    }

    public function execute(): Products|People
    {
        $mapped = $this->walker->walk($this->mapper->mapping, $this->rawData);

        $entity = match ($this->mapper->systemModule->model_name) {
            Products::class => $this->createProduct($mapped),
            People::class => $this->createPeople($mapped),
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
                $this->app,
                $this->company,
                $this->user,
                $linkedMapper,
                (string) ($relatedRaw['Id'] ?? ''),
                $relatedRaw,
                visitedMapperIds: [...$this->visitedMapperIds, $this->mapper->getId()],
            )->execute();

            $entity->set($linkField, (string) $linkedEntity->getId());
        }
    }

    private function configuration(): array
    {
        return is_array($this->mapper->configuration) ? $this->mapper->configuration : [];
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

    private function createProduct(array $mapped): Products
    {
        $productTypeId = $this->configuration()['product_type_id'] ?? null;

        if ($productTypeId === null || $productTypeId === '' || $productTypeId === 0) {
            throw new ValidationException(
                'Product mappers require configuration.product_type_id on the FilesystemMapper.',
            );
        }

        $productType = ProductsTypesRepository::getByIdOrGlobal((int) $productTypeId, $this->company, $this->app);

        $productData = new ProductData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: (string) ($mapped['name'] ?? ''),
            description: $mapped['description'] ?? null,
            productsType: $productType,
            slug: $mapped['slug'] ?? null,
            sku: $mapped['sku'] ?? null,
            attributes: $mapped['attributes'] ?? [],
        );

        return new CreateProductAction($productData, $this->user)->execute();
    }

    private function createPeople(array $mapped): People
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
            branch: $this->company->defaultBranch ?? $this->user->getCurrentCompany()->branch,
            user: $this->user,
            firstname: (string) ($mapped['firstname'] ?? ''),
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: (string) ($mapped['lastname'] ?? ''),
            runWorkflow: false,
        );

        return new CreatePeopleAction($peopleData)->execute();
    }
}
