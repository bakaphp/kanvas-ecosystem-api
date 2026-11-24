<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Support\Facades\Auth;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;

/**
 * A document engagement's entity is an invoice or quote: no people on it and no lead custom fields. The stage
 * notification text used to read `custom_fields` and crashed NotifyEngagementPipelineStageJob on every send.
 */
class DocumentEngagementNotificationTextTest extends DocumentDeliveryTestCase
{
    public function test_a_document_engagement_names_the_person_it_was_sent_to(): void
    {
        $link = new OpenDocumentEngagementAction(
            document: $this->acceptedInvoice(),
            people: $this->contact,
            user: Auth::user(),
            action: DocumentActionEnum::VIEW_INVOICE,
        )->execute();

        $text = new MessageNotificationTextAction($link->engagement)->get('contact');

        $this->assertSame(trim((string) $this->contact->name), $text);
        $this->assertNotSame('', $text);
    }
}
