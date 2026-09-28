<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\DataTransferObject;

use Illuminate\Database\Eloquent\Model;

final readonly class KnowledgeScope
{
    /**
     * A scope filters the shared knowledge collection down to one tenant, and
     * optionally one entity. Organization-wide reads keep only the tenant pair.
     * Entity-scoped docs (a Lead and its messages) carry
     * a real entity_type/entity_id; tenant-scoped docs (a company's uploaded
     * policy/FAQ files) carry none and are stored with entity_id = 0.
     *
     * @param class-string|null $entityType FQCN of the owning model; null = tenant/organization-scoped
     */
    public function __construct(
        public int $appId,
        public int $companyId,
        public ?string $entityType = null,
        public ?int $entityId = null,
        public bool $organizationWide = false,
    ) {
    }

    public static function fromEntity(KnowledgeEntity $entity): self
    {
        return new self(
            appId: $entity->appId,
            companyId: $entity->companyId,
            entityType: $entity->type,
            entityId: $entity->id,
        );
    }

    /** Scope a search/index to one Eloquent model. Throws InvalidArgumentException for a global (apps_id/companies_id = 0) row. */
    public static function forModel(Model $model): self
    {
        return self::fromEntity(KnowledgeEntity::fromModel($model));
    }

    /** Company-wide knowledge: no entity, so a search matches only entity_id = 0 rows. */
    public static function forTenant(int $appId, int $companyId): self
    {
        return new self(appId: $appId, companyId: $companyId);
    }

    public static function forOrganization(int $appId, int $companyId): self
    {
        return new self(
            appId: $appId,
            companyId: $companyId,
            organizationWide: true,
        );
    }

    public function isEntityScoped(): bool
    {
        return $this->entityType !== null && $this->entityId !== null;
    }

    /**
     * Typesense filter_by. companies_id is ALWAYS present — this is what closes
     * the cross-company leak. Organization-wide reads stop at that tenant pair;
     * tenant-document searches pin entity_id = 0, and entity searches add both
     * entity_type and entity_id.
     */
    public function filter(): string
    {
        $filter = sprintf('apps_id:=%d && companies_id:=%d', $this->appId, $this->companyId);

        if ($this->organizationWide) {
            return $filter;
        }

        if ($this->isEntityScoped()) {
            return $filter . sprintf(
                ' && entity_type:=%s && entity_id:=%d',
                self::escapeFilterValue((string) $this->entityType),
                $this->entityId,
            );
        }

        return $filter . ' && entity_id:=0';
    }

    /** Backtick-guard a Typesense filter value (FQCNs and source names carry special chars). */
    public static function escapeFilterValue(string $value): string
    {
        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $value) . '`';
    }
}
