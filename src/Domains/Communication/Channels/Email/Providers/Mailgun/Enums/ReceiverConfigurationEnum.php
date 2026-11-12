<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums;

/**
 * Keys inside `receiver_webhooks.configuration`. Separate from ConfigurationEnum, which holds
 * app/company hashtable keys — these are per-receiver and read by the inbound job.
 */
enum ReceiverConfigurationEnum: string
{
    case AGENT_ID = 'agent_id';
    case MAILBOX_ADDRESS = 'mailbox_address';

    // Consumed by ProcessWebhookAttemptAction, not by us — it uploads the email's attachments into
    // Filesystem before the job runs, which is the only chance to keep them.
    case CAPTURE_FILES = 'capture_files';

    // The accounting inbox (ScribePdfInboundWebhookJob): the gate, the address it receives on and the
    // Mailgun route that forwards it.
    case SCRIBE_PDF_INBOX = 'scribe_pdf_inbox';
    case INBOX_ADDRESS = 'inbox_address';
    case ROUTE_ID = 'route_id';
}
