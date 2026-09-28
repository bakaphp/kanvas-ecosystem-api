<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Sources;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadVariantInterestProjectionService;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Enums\LeadRagConfigurationEnum;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

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

    public function build(Model $entity): array
    {
        if (! $entity instanceof Lead) {
            throw new InvalidArgumentException('LeadKnowledgeSource only supports Lead entities.');
        }

        $entity->loadMissing(['app', 'company', 'organization', 'people', 'socialChannels']);
        $documents = array_values(array_filter([
            $this->document($entity, 'lead', (string) $entity->getId(), $this->join([
                'Lead: ' . trim($entity->firstname . ' ' . $entity->lastname),
                'Title: ' . $entity->title,
                'Email: ' . $entity->email,
                'Phone: ' . $entity->phone,
                'Description: ' . $entity->description,
            ])),
            $entity->people === null ? null : $this->document($entity, 'people', (string) $entity->people->getId(), $this->join([
                'Person: ' . trim((string) $entity->people->name),
                'First name: ' . $entity->people->firstname,
                'Last name: ' . $entity->people->lastname,
                'Email: ' . $entity->people->email,
                'Phone: ' . $entity->people->phone,
            ])),
            $this->document($entity, 'company', (string) $entity->company->getId(), $this->join([
                'Company: ' . $entity->company->name,
                'Website: ' . $entity->company->website,
            ])),
            $entity->organization === null ? null : $this->document($entity, 'organization', (string) $entity->organization->getId(), $this->join([
                'Organization: ' . $entity->organization->name,
                'Description: ' . $entity->organization->description,
            ])),
            $this->variantInterestDocument($entity),
        ]));

        return [...$documents, ...$this->messageDocuments($entity)];
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

    /** @return list<KnowledgeDocument> */
    private function messageDocuments(Lead $lead): array
    {
        $channelIds = $lead->socialChannels
            ->map(fn (Channel $channel): int => (int) $channel->getKey())
            ->filter(fn (int $channelId): bool => $channelId > 0)
            ->unique()
            ->values()
            ->all();
        if ($channelIds === []) {
            return [];
        }

        $maximum = max(1, (int) ($lead->app->get(LeadRagConfigurationEnum::MAX_MESSAGES->value) ?? 500));

        return Message::query()
            ->where('messages.apps_id', $lead->apps_id)
            ->where('messages.companies_id', $lead->companies_id)
            ->where('messages.is_deleted', 0)
            ->whereHas('channels', fn ($query) => $query->whereIn('channels.id', $channelIds))
            ->with(['channels' => fn ($query) => $query->whereIn('channels.id', $channelIds)])
            ->latest('messages.id')
            ->limit($maximum)
            ->get()
            ->map(function (Message $message) use ($lead): ?KnowledgeDocument {
                $content = trim($message->contentText());
                if ($content === '') {
                    return null;
                }

                $channels = $message->channels
                    ->map(fn (Channel $channel): string => (string) $channel->name)
                    ->filter()->unique()->values()->all();

                return $this->document(
                    $lead,
                    in_array(ChannelNameEnum::NOTES->value, $channels, true) ? 'note' : 'message',
                    (string) $message->getId(),
                    $content,
                    implode(',', $channels),
                    $message->created_at?->timestamp ?? 0,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function document(
        Lead $lead,
        string $type,
        string $id,
        string $content,
        string $channels = '',
        int $createdAt = 0,
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
                'channel_names' => $channels,
                'created_at' => $createdAt,
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
