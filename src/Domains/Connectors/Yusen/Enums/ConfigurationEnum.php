<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Enums;

enum ConfigurationEnum: string
{
    case MATCH_FIELD = 'yusen_match_field';
    case PRIMARY_WAREHOUSE_ID = 'yusen_primary_warehouse_id';
    case NETSUITE_SAVED_SEARCH_ID = 'yusen_netsuite_saved_search_id';
    case NETSUITE_LOCATION_ID = 'yusen_netsuite_location_id';
    case QUANTITY_TOLERANCE = 'yusen_quantity_tolerance';
    case RECONCILE_WITH_NETSUITE = 'yusen_reconcile_with_netsuite';
}
