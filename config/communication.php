<?php

use Kanvas\Communication\Channels\Email\Mail\MailCarrier;
use Kanvas\Communication\Setup\Enums\DefaultRuleEnum;
use Kanvas\Connectors\Google\Activities\CreateGoogleCalendarEventActivity;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum as GoogleConfigurationEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Social\Messages\Workflows\Activities\PrivatizeConversationMessageActivity;

return [
    /*
     * Integration handlers that can carry messages. The registry reads this list instead of scanning
     * for implementations, so adding a vendor is a line here and a folder, never an edit to the core.
     */
    'providers' => [
        TwilioHandlers::class,
        MailgunHandler::class,
        MetaWhatsAppHandler::class,
        RespondIOHandler::class,
        WaSenderHandler::class,
        // Built in: the Kanvas mailer (the company's mail server, else the app's, else the default),
        // used for email until the company connects an email provider.
        MailCarrier::class,
    ],

    /*
     * Providers held back from the sweep and the integration hooks until an app or a company opts in
     * (setting `communication_providers`, a list of slugs). Empty today: the first
     * `communication:setup` after a deploy moves every provider's inbound here and retires what
     * answered it before. A provider added mid-rollout goes here until its cutover is planned.
     */
    'opt_in' => [],

    /*
     * Hours a provider can keep reporting on a message after its last event. Widening one on an
     * observed late event is a config change; narrowing one needs the evidence behind it revisited.
     */
    'retention' => [
        'twilio' => (int) env('COMMUNICATION_RETENTION_TWILIO_HOURS', 72),
        'mailgun' => (int) env('COMMUNICATION_RETENTION_MAILGUN_HOURS', 168),
        'meta' => (int) env('COMMUNICATION_RETENTION_META_HOURS', 168),
        'respondio' => (int) env('COMMUNICATION_RETENTION_RESPONDIO_HOURS', 168),
        'wasender' => (int) env('COMMUNICATION_RETENTION_WASENDER_HOURS', 72),
        'mail' => (int) env('COMMUNICATION_RETENTION_MAIL_HOURS', 168),
    ],

    /*
     * What each default rule (DefaultRuleEnum) runs, in order, and the company setting it waits for
     * (`requires_company_setting`).
     * Kept here because some of these activities belong to a connector, and the domain never imports
     * one: swapping the vendor behind a rule is an edit to this list.
     */
    'default_rules' => [
        DefaultRuleEnum::PRIVATIZE_MESSAGES->value => [
            'activities' => [PrivatizeConversationMessageActivity::class],
        ],
        DefaultRuleEnum::GOOGLE_CALENDAR_EVENT->value => [
            'activities' => [CreateGoogleCalendarEventActivity::class],
            'requires_company_setting' => GoogleConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value,
        ],
    ],

    /*
     * Minutes a delivery may sit in `sending` before it is treated as a lost outcome and looked up.
     */
    'reconcile_after_minutes' => (int) env('COMMUNICATION_RECONCILE_AFTER_MINUTES', 10),
];
