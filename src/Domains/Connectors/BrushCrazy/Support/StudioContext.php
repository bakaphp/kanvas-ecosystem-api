<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Resolved Kanvas tenancy for one BrushCrazy studio. Every sync action reads its company from here
 * rather than from a constructor argument, so the same code works under both studio modes.
 *
 * `themeArea` matters more than it looks: the Kanvas Event domain has no `branches_id` column
 * anywhere, so `events.theme_area_id` is the only per-studio axis available inside the domain.
 */
final readonly class StudioContext
{
    public function __construct(
        public int $bcStudioId,
        public Companies $company,
        public CompaniesBranches $branch,
        public ThemeArea $themeArea,
        public string $timezone,
        public ?Warehouses $warehouse = null,
    ) {
    }

    public function companyId(): int
    {
        return (int) $this->company->getId();
    }

    public function themeAreaId(): int
    {
        return (int) $this->themeArea->getId();
    }
}
