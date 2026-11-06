<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Sources;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadVariantInterestProjectionService;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Override;

/**
 * Message and person rows crowd out reference documents with repeated words and surnames.
 * Conversation belongs in the history window and rolling summary; this source indexes record cards.
 */
final class LeadKnowledgeSource implements KnowledgeSource
{
    public function __construct(
        private readonly LeadVariantInterestProjectionService $variantInterests = new LeadVariantInterestProjectionService(),
    ) {
    }

    public function entityType(): string
    {
        return Lead::class;
    }

    #[Override]
    public function find(int $entityId, int $appId, int $companyId): ?Model
    {
        return Lead::query()
            ->whereKey($entityId)
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', 0)
            ->first();
    }

    #[Override]
    public function isEnabledFor(Apps $app): bool
    {
        return KnowledgeComponents::knowledgeEnabled($app);
    }

    public function build(Model $entity): array
    {
        if (! $entity instanceof Lead) {
            throw new InvalidArgumentException('LeadKnowledgeSource only supports Lead entities.');
        }

        $entity->loadMissing(['app', 'company', 'organization']);

        return array_values(array_filter([
            $this->document(
                $entity,
                'lead',
                (string) $entity->getId(),
                $this->join([
                    'Lead: ' . trim($entity->firstname . ' ' . $entity->lastname),
                    'Title: ' . $entity->title,
                    'Email: ' . $entity->email,
                    'Phone: ' . $entity->phone,
                    'Description: ' . $entity->description,
                ]),
            ),
            $this->document(
                $entity,
                'company',
                (string) $entity->company->getId(),
                $this->join([
                    'Company: ' . $entity->company->name,
                    'Website: ' . $entity->company->website,
                ]),
            ),
            $entity->organization === null ? null : $this->document(
                $entity,
                'organization',
                (string) $entity->organization->getId(),
                $this->join([
                    'Organization: ' . $entity->organization->name,
                    'Description: ' . $entity->organization->description,
                ]),
            ),
            $this->variantInterestDocument($entity),
        ]));
    }

    private function variantInterestDocument(Lead $lead): ?KnowledgeDocument
    {
        $projection = $this->variantInterests->build($lead);
        if ($projection['items'] === []) {
            return null;
        }

        $content = collect($projection['items'])
            ->map(function (array $item): string {
                $attributes = collect($item['attributes'])
                    ->map(fn (array $attribute): string => $attribute['name'] . ': ' . $attribute['value'])
                    ->implode(', ');

                return $this->join([
                    'Variant: ' . $item['name'],
                    'SKU: ' . $item['sku'],
                    'Product: ' . $item['product_name'],
                    'Interest type: ' . $item['interest_type'],
                    $item['current_price'] === null ? null : 'Current price: ' . $item['current_price'],
                    $item['price_at_interest'] === null ? null : 'Price at interest: ' . $item['price_at_interest'],
                    $attributes === '' ? null : 'Attributes: ' . $attributes,
                ]);
            })
            ->implode("\n\n");

        return $this->document(
            $lead,
            'variant-interests',
            (string) $lead->getId(),
            $content,
        );
    }

    private function document(
        Lead $lead,
        string $type,
        string $id,
        string $content,
    ): KnowledgeDocument {
        return new KnowledgeDocument(
            id: implode('-', ['lead', $lead->getId(), $type, $id]),
            content: $content,
            metadata: [
                'apps_id' => $lead->apps_id,
                'companies_id' => $lead->companies_id,
                'entity_type' => $lead::class,
                'entity_id' => $lead->getId(),
                'source_type' => $type,
                'source_id' => $id,
            ],
        );
    }

    /** @param list<string|null> $parts */
    private function join(array $parts): string
    {
        return collect($parts)
            ->map(fn (?string $part): string => trim((string) $part))
            ->reject(fn (string $part): bool => $part === '' || str_ends_with($part, ':'))
            ->implode("\n");
    }
}
