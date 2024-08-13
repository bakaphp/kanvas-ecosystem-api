<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Workflows\Activities\DefaultMessageActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

class DefaultMessageActivityTest extends TestCase
{
    public function testExecute(): void
    {
        $lead = Lead::factory()->create();
        $lead->set('address', '1234 Fake St');
        $lead->set('city', 'Springfield');
        $lead->set('custom_comments', 'This is a test comment');
        $app = app(Apps::class);
        $activity = new DefaultMessageActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $customFields = [
            'address',
            'city',
            'custom_comments',
        ];
        $result = $activity->execute($lead, $app, ['customsFields' => $customFields]);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Default message activity executed', $result['message']);

    }
}
