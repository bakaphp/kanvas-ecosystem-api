<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AttachFileToNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AttachFileToNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListNervousSystemPlanFilesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListNervousSystemTaskFilesTool;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/** The handoff: a document reaches a worker as a file on the work item, not as a URL in prose. */
class PlanFileHandoffTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    /** The point of attaching at plan level: it is handed over once and every task inherits it. */
    public function testAPlanFileIsVisibleToAWorkerListingItsOwnTask(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan);

        $this->attachToPlan($plan, "name,dept\nAda,Engineering", 'directory.csv');

        $listing = $this->withTenant(new ListNervousSystemTaskFilesTool())->__invoke(task_id: $task->getId());

        $this->assertSame(1, $listing['count']);
        $this->assertSame('plan', $listing['files'][0]['source']);
        $this->assertSame('directory.csv', $listing['files'][0]['file_name']);
        $this->assertTrue($listing['files'][0]['readable']);
    }

    public function testTheListingReturnsAFilesystemIdReadFileCanUse(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan);

        $attached = $this->attachToPlan($plan, 'hello', 'brief.md');

        $listing = $this->withTenant(new ListNervousSystemTaskFilesTool())->__invoke(task_id: $task->getId());

        $this->assertSame($attached['filesystem_id'], $listing['files'][0]['filesystem_id']);
        $this->assertStringContainsString('read_file', $listing['note']);
    }

    public function testATaskDeliverableRollsUpToThePlanListing(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan);

        $this->withTenant(new AttachFileToNervousSystemTaskTool())->__invoke(
            task_id: $task->getId(),
            file_name: 'headcount.md',
            content: '# Headcount',
        );

        $listing = $this->withTenant(new ListNervousSystemPlanFilesTool())->__invoke(plan_id: $plan->getId());

        $this->assertSame(1, $listing['count']);
        $this->assertSame('task', $listing['files'][0]['source']);
        $this->assertSame($task->getId(), $listing['files'][0]['task_id']);
    }

    /** An empty listing must not read as "there is nothing to read" — it is a reason to block. */
    public function testAnEmptyListingTellsTheAgentToBlockRatherThanAssume(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan);

        $listing = $this->withTenant(new ListNervousSystemTaskFilesTool())->__invoke(task_id: $task->getId());

        $this->assertSame(0, $listing['count']);
        $this->assertStringContainsString('block the task', $listing['note']);
    }

    /** An image is attachable but not extractable; the worker has to know before it plans around it. */
    public function testAnUnreadableAttachmentIsFlaggedInTheListing(): void
    {
        $plan = $this->makePlan();

        $file = new Filesystem();
        $file->apps_id = app(Apps::class)->getId();
        $file->companies_id = $plan->companies_id;
        $file->users_id = auth()->user()->getId();
        $file->name = 'diagram.png';
        $file->path = 'test/' . uniqid() . '/diagram.png';
        $file->url = 'https://cdn.example.test/' . uniqid() . '/diagram.png';
        $file->size = '10';
        $file->file_type = 'png';
        $file->is_deleted = 0;
        $file->saveOrFail();
        $plan->addFile($file, $file->name);

        $listing = $this->withTenant(new ListNervousSystemPlanFilesTool())->__invoke(plan_id: $plan->getId());

        $this->assertFalse($listing['files'][0]['readable']);
        $this->assertStringContainsString('0 readable', $listing['note']);
    }

    public function testAPlanInAnotherCompanyCannotBeAttachedTo(): void
    {
        $foreign = $this->makePlan(['companies_id' => Companies::factory()->create()->getId()]);

        $result = $this->withTenant(new AttachFileToNervousSystemPlanTool())->__invoke(
            plan_id: $foreign->getId(),
            file_name: 'brief.md',
            content: 'hello',
        );

        $this->assertNotSame('success', $result['status'] ?? null);
        $this->assertSame(0, $foreign->files()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function attachToPlan(Plan $plan, string $content, string $fileName): array
    {
        $result = $this->withTenant(new AttachFileToNervousSystemPlanTool())->__invoke(
            plan_id: $plan->getId(),
            file_name: $fileName,
            content: $content,
        );

        $this->assertSame('success', $result['status'], (string) ($result['message'] ?? ''));

        return $result;
    }

    /**
     * @template T of object
     * @param T $tool
     * @return T
     */
    private function withTenant(object $tool): object
    {
        /** @var Users $user */
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    /**
     * The allow-list is checked against file_name, so that has to be the name actually stored.
     * Deriving it from the URL path instead lets a model pass the extension gate with "report.md"
     * and land an arbitrary type on the record.
     */
    public function testAUrlUploadStoresTheNameThatWasValidatedNotTheUrlPath(): void
    {
        // A resolvable public host: SafeUrl does a real DNS lookup before the faked request.
        Http::fake(['example.com/*' => Http::response('name,dept', 200)]);

        $plan = $this->makePlan();

        $result = $this->withTenant(new AttachFileToNervousSystemPlanTool())->__invoke(
            plan_id: $plan->getId(),
            file_name: 'directory.csv',
            file_url: 'https://example.com/' . uniqid() . '/payload.exe',
        );

        $this->assertSame('success', $result['status'], (string) ($result['message'] ?? ''));
        $this->assertSame('directory.csv', $result['file_name']);
        $this->assertSame('directory.csv', $plan->files()->first()?->name);
    }
}
