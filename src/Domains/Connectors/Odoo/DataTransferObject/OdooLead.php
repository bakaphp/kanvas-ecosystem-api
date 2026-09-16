<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\DataTransferObject;

use Kanvas\Connectors\Odoo\DataTransferObject\Concerns\FiltersNullFields;
use Kanvas\Guild\Leads\Models\Lead;
use Spatie\LaravelData\Data;

class OdooLead extends Data
{
    use FiltersNullFields;

    public function __construct(
        public string $name,
        public string $type = 'lead',
        public ?string $contact_name = null,
        public ?string $email_from = null,
        public ?string $phone = null,
        public ?string $description = null,
    ) {
    }

    public static function fromLead(Lead $lead): self
    {
        $people = $lead->people;

        return new self(
            name: $lead->title ?: ($people?->name ?: 'Unknown'),
            contact_name: $people?->name,
            email_from: $people?->getEmails()->first()?->value,
            phone: $people?->getPhones()->first()?->value,
            description: $lead->description,
        );
    }
}
