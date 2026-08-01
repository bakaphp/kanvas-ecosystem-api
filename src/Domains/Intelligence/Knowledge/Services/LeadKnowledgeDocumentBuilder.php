<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Illuminate\Support\Collection;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Enums\LeadRagConfigurationEnum;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Document;

class LeadKnowledgeDocumentBuilder
{
    /**
     * @return Document[]
     */
    public function build(Lead $lead): array
    {
        $lead->loadMissing(['app', 'company', 'organization', 'people', 'socialChannels']);
        $sources = array_values(array_filter([
            $this->profileSource($lead),
            $this->peopleSource($lead),
            $this->companySource($lead),
            $this->organizationSource($lead),
            ...$this->messageSources($lead),
        ]));

        return collect($sources)
            ->flatMap(
                fn (array $source): array => $this->documentsFromSource($lead, $source)
            )
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, id: string, content: string, channels: string, created_at: int}
     */
    private function profileSource(Lead $lead): array
    {
        return $this->source(
            type: 'lead',
            id: (string) $lead->getId(),
            content: $this->join([
                'Lead: ' . trim($lead->firstname . ' ' . $lead->lastname),
                'Title: ' . $lead->title,
                'Email: ' . $lead->email,
                'Phone: ' . $lead->phone,
                'Description: ' . $lead->description,
            ])
        );
    }

    /**
     * @return array{type: string, id: string, content: string, channels: string, created_at: int}|null
     */
    private function peopleSource(Lead $lead): ?array
    {
        $people = $lead->people;
        if ($people === null) {
            return null;
        }

        return $this->source(
            type: 'people',
            id: (string) $people->getId(),
            content: $this->join([
                'Person: ' . trim((string) $people->name),
                'First name: ' . $people->firstname,
                'Last name: ' . $people->lastname,
                'Email: ' . $people->email,
                'Phone: ' . $people->phone,
            ])
        );
    }

    /**
     * @return array{type: string, id: string, content: string, channels: string, created_at: int}
     */
    private function companySource(Lead $lead): array
    {
        return $this->source(
            type: 'company',
            id: (string) $lead->company->getId(),
            content: $this->join([
                'Company: ' . $lead->company->name,
                'Website: ' . $lead->company->website,
            ])
        );
    }

    /**
     * @return array{type: string, id: string, content: string, channels: string, created_at: int}|null
     */
    private function organizationSource(Lead $lead): ?array
    {
        $organization = $lead->organization;
        if ($organization === null) {
            return null;
        }

        return $this->source(
            type: 'organization',
            id: (string) $organization->getId(),
            content: $this->join([
                'Organization: ' . $organization->name,
                'Description: ' . $organization->description,
            ])
        );
    }

    /**
     * @return list<array{type: string, id: string, content: string, channels: string, created_at: int}>
     */
    private function messageSources(Lead $lead): array
    {
        $maximum = max(
            1,
            (int) ($lead->app->get(LeadRagConfigurationEnum::MAX_MESSAGES->value) ?? 500)
        );
        $messages = $lead->socialChannels
            ->flatMap(fn (Channel $channel): Collection => $channel->messages()
                ->where('messages.apps_id', $lead->apps_id)
                ->where('messages.companies_id', $lead->companies_id)
                ->where('messages.is_deleted', 0)
                ->latest('messages.id')
                ->limit($maximum)
                ->get()
                ->map(fn (Message $message): array => [
                    'channel' => $channel,
                    'message' => $message,
                ]))
            ->groupBy(fn (array $item): int => $item['message']->getId())
            ->take($maximum);

        return $messages
            ->map(function (Collection $items): ?array {
                /** @var Message $message */
                $message = $items->first()['message'];
                $content = trim($message->contentText());
                if ($content === '') {
                    return null;
                }

                $channelNames = $items
                    ->map(fn (array $item): string => (string) $item['channel']->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return $this->source(
                    type: in_array(ChannelNameEnum::NOTES->value, $channelNames, true)
                        ? 'note'
                        : 'message',
                    id: (string) $message->getId(),
                    content: $content,
                    channels: implode(',', $channelNames),
                    createdAt: $message->created_at?->timestamp ?? 0,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Neuron's StringDataLoader owns chunking and produces the Document objects used by
     * its embedding, vector-store, retrieval, and prompt-augmentation pipeline.
     *
     * @param array{type: string, id: string, content: string, channels: string, created_at: int} $source
     *
     * @return Document[]
     */
    private function documentsFromSource(Lead $lead, array $source): array
    {
        $documents = StringDataLoader::for($source['content'])->getDocuments();
        $sourceName = sprintf(
            'company-%d-lead-%d',
            $lead->companies_id,
            $lead->getId()
        );

        foreach ($documents as $index => $document) {
            $document->id = implode('-', [
                'lead',
                $lead->getId(),
                $source['type'],
                $source['id'],
                $index,
            ]);
            $document->sourceType = 'lead';
            $document->sourceName = $sourceName;
            $document->addMetadata('apps_id', $lead->apps_id);
            $document->addMetadata('companies_id', $lead->companies_id);
            $document->addMetadata('entity_type', 'lead');
            $document->addMetadata('entity_id', $lead->getId());
            $document->addMetadata('source_type', $source['type']);
            $document->addMetadata('source_id', $source['id']);
            $document->addMetadata('channel_names', $source['channels']);
            $document->addMetadata('created_at', $source['created_at']);
        }

        return $documents;
    }

    /**
     * @return array{type: string, id: string, content: string, channels: string, created_at: int}
     */
    private function source(
        string $type,
        string $id,
        string $content,
        string $channels = '',
        int $createdAt = 0,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'content' => $content,
            'channels' => $channels,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param list<string|null> $parts
     */
    private function join(array $parts): string
    {
        return collect($parts)
            ->map(fn (?string $part): string => trim((string) $part))
            ->reject(fn (string $part): bool => $part === '' || str_ends_with($part, ':'))
            ->implode("\n");
    }
}
