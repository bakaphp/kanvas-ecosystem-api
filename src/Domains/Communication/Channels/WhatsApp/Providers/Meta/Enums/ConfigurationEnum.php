<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums;

enum ConfigurationEnum: string
{
    /**
     * Settings decode a numeric string to an int, so read this and WABA_ID back with (string).
     */
    case PHONE_NUMBER_ID = 'meta_whatsapp_phone_number_id';

    /**
     * Encrypted: the business token the number sends with.
     */
    case ACCESS_TOKEN = 'meta_whatsapp_access_token';
    case WABA_ID = 'meta_whatsapp_waba_id';

    /**
     * What Meta answered for the number at setup, so it can be shown without asking Meta again.
     */
    case DISPLAY_PHONE_NUMBER = 'meta_whatsapp_display_phone_number';

    /**
     * App setting, so a Graph upgrade is a config change rather than a release.
     */
    case GRAPH_API_VERSION = 'meta_graph_api_version';

    /**
     * App setting: the Meta app's secret, which signs every webhook (`X-Hub-Signature-256`). Set by
     * an operator; without it no webhook is accepted.
     */
    case APP_SECRET = 'meta_app_secret';

    /**
     * App setting: what Meta echoes back when it verifies a callback URL. Generated on first use.
     */
    case WEBHOOK_VERIFY_TOKEN = 'meta_webhook_verify_token';
}
