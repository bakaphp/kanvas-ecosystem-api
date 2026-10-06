<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * How a BrushCrazy studio maps onto Kanvas tenancy. Persisted on the app at first import —
 * switching it after rows exist requires a full re-import, since it changes which company every
 * imported row belongs to.
 */
enum StudioModeEnum: string
{
    /** One Companies per studio. Isolates events per studio, at the cost of splitting a customer
     * who visits two studios into two company-scoped People rows. */
    case COMPANY = 'company';

    /** One Companies for all studios, one CompaniesBranches each. Keeps customers whole, but the
     * Event domain has no branches_id, so the studio axis rides on theme_area_id alone. */
    case BRANCH = 'branch';
}
