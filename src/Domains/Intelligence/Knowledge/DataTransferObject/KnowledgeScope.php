<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\DataTransferObject;

final readonly class KnowledgeScope
{
    /**
     * A scope filters the shared knowledge collection down to one tenant, and
     * optionally one entity. Entity-scoped docs (a Lead and its messages) carry
     * a real entity_type/entity_id; tenant-scoped docs (a company's uploaded
     * policy/FAQ files) carry none and are stored with entity_id = 0.
     *
     * @param class-string|null $entityType FQCN of the owning model; null = tenant-scoped
     */
    public function __construct(
        public int $appId,
        public int $companyId,
        public ?string $entityType = null,
        public ?int $entityId = null,
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

    /** Company-wide knowledge: no entity, so a search matches only entity_id = 0 rows. */
    public static function forTenant(int $appId, int $companyId): self
    {
        return new self(appId: $appId, companyId: $companyId);
    }

    public function isEntityScoped(): bool
    {
        return $this->entityType !== null && $this->entityId !== null;
    }

    /**
     * Typesense filter_by. companies_id is ALWAYS present — this is what closes
     * the cross-company leak. A tenant-scoped search pins entity_id = 0 so it
     * returns only company knowledge, never another entity's indexed rows.
     */
    public function filter(): string
    {
        $filter = sprintf('apps_id:=%d && companies_id:=%d', $this->appId, $this->companyId);

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
