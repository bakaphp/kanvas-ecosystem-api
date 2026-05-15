<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Enums;

enum ConfigurationEnum: string
{
    /**
     * App-level config key holding the cloud-sync setup as a JSON blob:
     *
     *   {
     *     "key": "<shared secret>",
     *     "id":  "<shared identifier>",
     *     "ftps": [
     *       { "host": "...", "username": "...", "password": "...", "port": 22 }
     *     ]
     *   }
     *
     * The receiver job validates the inbound `key` + `id` body fields against
     * the configured pair and pulls inventory CSVs from each FTP listed.
     */
    case CLOUD_SYNC = 'dealer_app_center_cloud_sync';
}
