<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\DataTransferObject;

use Kanvas\Guild\Organizations\Models\Organization;

/**
 * A Mercury AR customer (`/ar/customers`) — the party an invoice is billed to.
 *
 * Mercury requires `name` and `email`. An Organization with no email cannot be pushed, and that's a real
 * constraint rather than something to paper over: Mercury delivers the invoice BY email, so an emailless
 * customer produces an invoice nobody ever receives.
 *
 * The address comes from the Organization's structured BILLING address. Mercury validates it all-or-nothing
 * — a half-filled address is rejected outright, not partially accepted — so an incomplete one is simply
 * omitted rather than sent to fail. The address itself is optional to Mercury; the invoice still works
 * without it, it just doesn't print one.
 */
class MercuryCustomer
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $id = null,
        /** @var array<string, string>|null Mercury's structured shape, or null when we can't fill it fully. */
        public readonly ?array $address = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromApi(array $payload): self
    {
        return new self(
            name: (string) ($payload['name'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            id: isset($payload['id']) ? (string) $payload['id'] : null,
            address: isset($payload['address']) ? (array) $payload['address'] : null,
            raw: $payload,
        );
    }

    public static function fromOrganization(Organization $organization, string $email): self
    {
        return new self(
            name: $organization->name,
            email: $email,
            address: self::addressPayload($organization),
        );
    }

    /**
     * The body Mercury expects on POST /ar/customers.
     *
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->address !== null) {
            $payload['address'] = $this->address;
        }

        return $payload;
    }

    /**
     * Maps our billing address onto Mercury's field names. Note `region` is their word for state/province,
     * and `country` must be the ISO 3166-1 alpha-2 code, which is what Countries::$code already holds.
     *
     * @return array<string, string>|null
     */
    private static function addressPayload(Organization $organization): ?array
    {
        $billing = $organization->billingAddress();

        if ($billing === null || ! $billing->isComplete()) {
            return null;
        }

        $payload = [
            'name' => $billing->name ?? $organization->name,
            'address1' => (string) $billing->address,
            'city' => (string) $billing->city,
            'region' => (string) $billing->state,
            'postalCode' => (string) $billing->zip,
            'country' => (string) $billing->countryCode(),
        ];

        if ($billing->address_2 !== null && trim($billing->address_2) !== '') {
            $payload['address2'] = $billing->address_2;
        }

        return $payload;
    }
}
