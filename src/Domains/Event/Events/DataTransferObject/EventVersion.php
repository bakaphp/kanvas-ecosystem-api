<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Models\Event;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class EventVersion extends Data
{
    public function __construct(
        public readonly Event $event,
        public readonly UserInterface $user,
        public readonly Currencies $currency,
        public readonly string $name,
        public readonly string|int $version,
        #[DataCollectionOf(EventDate::class)]
        public readonly DataCollection $dates,
        public readonly float $pricePerTicket = 0,
        public readonly ?string $agenda = null,
        public readonly ?string $description = null,
        public readonly ?string $metadata = null,
        public readonly ?string $slug = null,
    ) {
    }

    public static function fromMultiple(
        Event $event,
        UserInterface $user,
        Currencies $currencies,
        array $data
    ) {
        return new self(
            event: $event,
            user: $user,
            currency: $currencies,
            name: $data['name'],
            version: $data['version'] ?? 1,
            dates: EventDate::collect($data['dates'] ?? [], DataCollection::class),
            pricePerTicket: $data['price_per_ticket'] ?? 0,
            agenda: $data['agenda'] ?? null,
            description: $data['description'] ?? null,
            metadata: $data['metadata'] ?? null,
            slug: $data['slug'] ?? null
        );
    }
}
