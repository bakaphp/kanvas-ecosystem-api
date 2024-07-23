<?php

declare(strict_types=1);

namespace Kanvas\Social\Integration;

use Kanvas\Social\Messages\Workflows\Activities\DefaultMessageActivity;
use PHPUnit\Event\Code\Test;
use Tests\TestCase;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\StoredWorkflow;

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
        $result = $activity->execute($lead, $app, []);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Default message activity executed', $result['message']);
    }
}
