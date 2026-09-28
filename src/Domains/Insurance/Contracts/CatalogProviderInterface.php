<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

interface CatalogProviderInterface
{
    /**
     * Reference data (vehicle models, address trees, add-ons). Shapes are the
     * insurer's own, so this stays a passthrough — the alternative is one query
     * per catalog per insurer, which is the n+1 this layer exists to avoid.
     *
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    public function getCatalog(string $catalog, array $params = []): array;

    /**
     * @return list<string>
     */
    public function availableCatalogs(): array;
}
