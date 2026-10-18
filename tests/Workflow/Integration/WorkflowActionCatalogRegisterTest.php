<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use Tests\TestCase;

final class WorkflowActionCatalogRegisterTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    /**
     * Registering one class must write the row the deploy-time sync would write for it — same name,
     * same key — or the next sync adds a second row and a rule assembled by name resolves elsewhere.
     */
    public function testRegisteringOneClassWritesTheRowTheFullSyncWrites(): void
    {
        $registered = app(WorkflowActionCatalog::class)->register(PushMessageToWordPressActivity::class);

        $this->assertTrue($registered->exists);
        $this->assertSame('Push Message To WordPress', $registered->name);
        $this->assertSame(PushMessageToWordPressActivity::class, $registered->model_name);

        $changes = app(WorkflowActionCatalog::class)->sync();

        $this->assertNotContains('Push Message To WordPress', $changes['created']);
        $this->assertSame(
            1,
            Action::where('model_name', PushMessageToWordPressActivity::class)->count()
        );
        $this->assertSame($registered->getId(), Action::where('name', 'Push Message To WordPress')->value('id'));
    }

    public function testRegisteringAClassThatIsNotAWorkflowActionFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WorkflowActionCatalog::class)->register(self::class);
    }
}
