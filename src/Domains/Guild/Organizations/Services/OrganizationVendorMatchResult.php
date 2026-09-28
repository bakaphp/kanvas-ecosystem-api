<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Services;

use Kanvas\Guild\Organizations\Models\Organization;

/** Result of OrganizationVendorMatcherService::match() — exactly one of organization or candidates is populated. */
final class OrganizationVendorMatchResult
{
    /**
     * @param list<Organization> $candidates
     */
    private function __construct(
        public readonly ?Organization $organization,
        public readonly float $score,
        public readonly array $candidates,
    ) {
    }

    public static function matched(Organization $organization, float $score): self
    {
        return new self($organization, $score, []);
    }

    /**
     * @param list<Organization> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(null, 0.0, $candidates);
    }

    public static function none(): self
    {
        return new self(null, 0.0, []);
    }

    public function isMatched(): bool
    {
        return $this->organization !== null;
    }
}
