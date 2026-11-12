<?php

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;

return [
    /*
     * Integration handlers that can carry messages. The registry reads this list instead of scanning
     * for implementations, so adding a vendor is a line here and a folder, never an edit to the core.
     */
    'providers' => [
        TwilioHandlers::class,
        MailgunHandler::class,
        MetaWhatsAppHandler::class,
    ],

    /*
     * Providers held back from the sweep and the integration hooks until an app or a company opts in
     * (setting `communication_providers`, a list of slugs). Twilio and Mailgun predate the domain:
     * every tenant already has the integration, and moving a number's webhook is a cutover done per
     * tenant, not by a deploy.
     */
    'opt_in' => ['twilio', 'mailgun'],

    /*
     * Hours a provider can keep reporting on a message after its last event. Widening one on an
     * observed late event is a config change; narrowing one needs the evidence behind it revisited.
     */
    'retention' => [
        'twilio' => (int) env('COMMUNICATION_RETENTION_TWILIO_HOURS', 72),
        'mailgun' => (int) env('COMMUNICATION_RETENTION_MAILGUN_HOURS', 168),
        'meta' => (int) env('COMMUNICATION_RETENTION_META_HOURS', 168),
    ],

    /*
     * Minutes a delivery may sit in `sending` before it is treated as a lost outcome and looked up.
     */
    'reconcile_after_minutes' => (int) env('COMMUNICATION_RECONCILE_AFTER_MINUTES', 10),
];
