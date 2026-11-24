<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Resolves a Guild Organization from an extracted PDF / inbound payload OR creates one when no match
 * exists. Drops the "operator must pick a vendor before approval" friction — drafts land with their
 * vendor already linked, and any duplicates are cleaned up later via MergeOrganizationsAction.
 *
 * Match precedence (high → low):
 *   1. tax id exact match    (organizations.tax_id — the legal identity)
 *   2. email exact match     (organizations.email — near-zero false positives)
 *   3. name exact match      (trimmed, case-insensitive)
 *   4. name fuzzy match      (similar_text ≥ 90 %, but only when shorter input ≥ 4 chars)
 *
 * No match → CREATE a new Organization with the payload data (tax id included) and return it.
 *
 * The payload shape mirrors the Gemini classifier extraction:
 *   ['vendor_name' => string, 'vendor_email' => ?string, 'vendor_tax_id' => ?string, ...]
 * plus an optional `vendor_tax_id_type` (TaxIdTypeEnum) when the caller knows the id's kind.
 *
 * Caller is responsible for providing a fallback `vendor_name` when the LLM extraction is empty
 * (this Action will not create an Org with a blank name — it returns null in that case).
 */
class ResolveOrCreateOrganizationFromVendorPayloadAction
{
    public const NAME_FUZZY_THRESHOLD = 90.0;
    public const NAME_MIN_LENGTH_FOR_FUZZY = 4;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        /** @var array<string, mixed> */
        public readonly array $payload,
    ) {
    }

    public function execute(): ?Organization
    {
        $name = $this->trimmedString($this->payload['vendor_name'] ?? null);
        $email = $this->trimmedString($this->payload['vendor_email'] ?? null);
        $taxId = Str::normalizeTaxId(is_scalar($this->payload['vendor_tax_id'] ?? null) ? (string) $this->payload['vendor_tax_id'] : null);

        $existing = Organization::findByTaxId($this->app, $this->company, $taxId)
            ?? $this->findExistingByEmail($email)
            ?? $this->findExistingByName($name)
            ?? $this->findExistingByFuzzyName($name);

        if ($existing !== null) {
            return $existing;
        }

        if ($name === null || $name === '') {
            return null;
        }

        return $this->createOrganization($name, $email, $taxId);
    }

    private function findExistingByEmail(?string $email): ?Organization
    {
        if ($email === null) {
            return null;
        }

        return $this->tenantScopedQuery()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
            ->first();
    }

    private function findExistingByName(?string $name): ?Organization
    {
        if ($name === null || $name === '') {
            return null;
        }

        return $this->tenantScopedQuery()
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])
            ->first();
    }

    /**
     * `similar_text` is O(n*m). Only iterate when both inputs are short enough that this is cheap,
     * and bail when no obvious candidate exists in the first 200 rows (production-tenants with
     * thousands of orgs should rely on the exact-match branches above).
     */
    private function findExistingByFuzzyName(?string $name): ?Organization
    {
        if ($name === null || strlen($name) < self::NAME_MIN_LENGTH_FOR_FUZZY) {
            return null;
        }

        $candidates = $this->tenantScopedQuery()
            ->select(['id', 'name'])
            ->limit(200)
            ->get();

        $bestId = null;
        $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate->name;
            if ($candidateName === '') {
                continue;
            }
            similar_text(strtolower($name), strtolower($candidateName), $score);
            if ($score >= self::NAME_FUZZY_THRESHOLD && $score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $candidate->id;
            }
        }

        return $bestId === null
            ? null
            : Organization::query()->where('id', $bestId)->first();
    }

    private function createOrganization(string $name, ?string $email, ?string $taxId): Organization
    {
        return Organization::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => Str::limit($name, 128, ''),
            'email' => $email,
            'tax_id' => $taxId,
            'tax_id_type' => ($this->payload['vendor_tax_id_type'] ?? null) instanceof TaxIdTypeEnum
                ? $this->payload['vendor_tax_id_type']
                : null,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function tenantScopedQuery(): Builder
    {
        return Organization::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false);
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return Str::trimToNull($value);
    }
}
