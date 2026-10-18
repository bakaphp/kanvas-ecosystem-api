<?php

use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;

return [
    /*
     * Integration handlers that can carry messages. The registry reads this list instead of scanning
     * for implementations, so adding a vendor is a line here and a folder, never an edit to the core.
     */
    'providers' => [
        MetaWhatsAppHandler::class,
    ],

    /*
     * Hours a provider can keep reporting on a message after its last event. Widening one on an
     * observed late event is a config change; narrowing one needs the evidence behind it revisited.
     */
    'retention' => [
        'meta' => (int) env('COMMUNICATION_RETENTION_META_HOURS', 168),
    ],

    /*
     * Minutes a delivery may sit in `sending` before it is treated as a lost outcome and looked up.
     */
    'reconcile_after_minutes' => (int) env('COMMUNICATION_RECONCILE_AFTER_MINUTES', 10),
];
