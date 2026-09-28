<?php

declare(strict_types=1);

namespace Tests\Connectors\Kernel;

use Kanvas\Connectors\Kernel\Services\BrowserFiles;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Kernel\SaveBrowserDownloadsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Kernel\SaveBrowserFileTool;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Plan\Actions\AttachAgentFilesToPlanAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Workflow\Models\Integrations;
use Tests\Connectors\Mcp\McpTestCase;

/**
 * Kernel deletes a browser session's filesystem the moment the session ends, so an export nobody copied
 * out is gone for good. These tools are the copy-out, and they hand the agent a plan, never the bytes.
 */
final class KernelBrowserFileToolsTest extends McpTestCase
{
    private const string EXPORT_PATH = '/home/kernel/Downloads/Account Summary 20260920.xlsx';

    public function testAFileIsCopiedOutOfTheSessionAndLandsOnAPlan(): void
    {
        [$agent] = $this->kernelServer();
        $csv = "order,qty\nDN-1,2\n";
        $tool = $this->fileTool($agent, ['/tmp/outbound.csv' => $csv]);

        $result = $tool('session-1', '/tmp/outbound.csv');

        $this->assertSame('success', $result['status']);
        $this->assertSame('outbound.csv', $result['saved'][0]['name']);
        $this->assertSame(strlen($csv), $result['saved'][0]['size']);

        $plan = Plan::find($result['plan_id']);
        $this->assertSame(AttachAgentFilesToPlanAction::PLAN_TYPE, $plan?->plan_type);
        $this->assertSame(['outbound.csv'], $plan?->getFiles()->pluck('name')->all());

        // The point of the plan: a day's export must not travel through the conversation.
        $this->assertStringNotContainsString($csv, (string) json_encode($result));
        $this->assertStringContainsString('do not paste their contents', $result['note']);
    }

    public function testAFileTooBigToCarryIsRefusedWithSomethingToDoAboutIt(): void
    {
        [$agent] = $this->kernelServer();
        $tool = $this->fileTool($agent, [], ['/tmp/huge.csv' => BrowserFiles::MAX_BYTES + 1]);

        $result = $tool('session-1', '/tmp/huge.csv');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('narrower range', $result['skipped']['/tmp/huge.csv']);
    }

    public function testAMissingFileSaysSoRatherThanClaimingSuccess(): void
    {
        [$agent] = $this->kernelServer();

        $result = $this->fileTool($agent, [])('session-1', '/tmp/not-there.csv');

        // An agent once told a person it had saved a file it never wrote; the tool must not repeat that.
        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['saved']);
        $this->assertStringContainsString('not found in the session', $result['skipped']['/tmp/not-there.csv']);
    }

    public function testEverythingTheSessionProducedIsCollectedWhenTheNameIsUnknown(): void
    {
        [$agent] = $this->kernelServer();
        $tool = $this->downloadsTool($agent, [
            self::EXPORT_PATH => 'xlsx-bytes',
            '/tmp/summary.csv' => 'order,qty',
        ]);

        $result = $tool('session-1');

        // A portal names its export whatever it likes — those UUID file names — so the run takes what is there.
        $this->assertSame('success', $result['status']);
        $this->assertSame(
            ['Account Summary 20260920.xlsx', 'summary.csv'],
            array_column($result['saved'], 'name')
        );
    }

    public function testTheSweepLeavesTheSessionsOwnClutterBehind(): void
    {
        [$agent] = $this->kernelServer();
        $tool = $this->downloadsTool($agent, [
            self::EXPORT_PATH => 'xlsx-bytes',
            '/tmp/.X1-lock' => '11',
            '/tmp/export.mjs' => 'const run = () => {}',
            '/tmp/Outbound.csv.crdownload' => 'half a file',
        ]);

        $result = $tool('session-1');

        // A plan once collected an X11 lock file, the agent's own scripts and a part-downloaded export.
        $this->assertSame(['Account Summary 20260920.xlsx'], array_column($result['saved'], 'name'));
    }

    public function testTheSameExportIsNotSavedTwiceToOnePlan(): void
    {
        [$agent] = $this->kernelServer();
        $contents = [self::EXPORT_PATH => 'xlsx-bytes', '/tmp/summary.csv' => 'order,qty'];

        $first = $this->downloadsTool($agent, $contents)('session-1');
        $second = $this->downloadsTool($agent, $contents)('session-1', null, $first['plan_id']);

        // Calling it twice for one session used to pile the same files onto the plan again.
        $this->assertSame('error', $second['status']);
        $this->assertSame([], $second['saved']);
        $this->assertSame('already saved', $second['skipped'][self::EXPORT_PATH]);
        $this->assertCount(2, Plan::find($first['plan_id'])?->getFiles() ?? []);
    }

    public function testWhatItReportsIsWhatIsOnThePlan(): void
    {
        [$agent] = $this->kernelServer();

        $result = $this->downloadsTool($agent, ['/tmp/summary.csv' => 'order,qty'])('session-1');

        // The agent narrates from this payload — it once announced a file name and id that never existed.
        $onPlan = Plan::find($result['plan_id'])?->getFiles()
            ->map(static fn ($file): array => ['name' => $file->name, 'id' => $file->getId(), 'size' => (int) $file->size])
            ->all();

        $this->assertSame($onPlan, $result['saved']);
        $this->assertStringContainsString('Name only these files', $result['note']);
    }

    public function testAFilterNarrowsWhatIsCollected(): void
    {
        [$agent] = $this->kernelServer();
        $tool = $this->downloadsTool($agent, [
            self::EXPORT_PATH => 'xlsx-bytes',
            '/tmp/unrelated.json' => '{}',
        ]);

        $result = $tool('session-1', '.xlsx');

        $this->assertSame(['Account Summary 20260920.xlsx'], array_column($result['saved'], 'name'));
    }

    public function testASessionWithNothingInItPointsAtTheDownloadBlock(): void
    {
        [$agent] = $this->kernelServer();

        $result = $this->downloadsTool($agent, [])('session-1');

        // Chromium blocks automated downloads until the session allows them — the likeliest reason a
        // session is empty right after an export, and the one thing worth naming.
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('download behaviour', $result['message']);
    }

    public function testFilesGoToAnExistingPlanWhenOneIsNamed(): void
    {
        [$agent] = $this->kernelServer();
        $first = $this->fileTool($agent, ['/tmp/a.csv' => 'a'])('session-1', '/tmp/a.csv');

        $second = $this->fileTool($agent, ['/tmp/b.csv' => 'b'])('session-1', '/tmp/b.csv', $first['plan_id']);

        $this->assertSame($first['plan_id'], $second['plan_id']);
        $this->assertSame(['a.csv', 'b.csv'], Plan::find($first['plan_id'])?->getFiles()->pluck('name')->all());
    }

    public function testTheToolIsOnlyOfferedWhileTheServerIsConnected(): void
    {
        [$agent, $integration] = $this->kernelServer();
        $host = new KernelToolHostStub($agent);
        $catalogRow = $this->catalogTool();

        $this->assertInstanceOf(SaveBrowserFileTool::class, $host->resolve($catalogRow));

        new McpConnectionService($agent, $integration)->markFailed('revoked at the vendor');

        // A tool in the list is a promise to the model; one that can only answer "not connected" costs a
        // round trip and points it at a dead end.
        $this->assertNull($host->resolve($catalogRow));
    }

    public function testACustomerFacingAgentIsNeverOfferedTheseTools(): void
    {
        [$agent] = $this->kernelServer();

        // These reach a third-party browser the same way an MCP toolkit does, and a toolkit is already
        // refused on a customer surface — a marker can be added to an agent that already holds the grant.
        $this->assertNull(new CustomerFacingToolHostStub($agent)->resolve($this->catalogTool()));
        $this->assertInstanceOf(
            SaveBrowserFileTool::class,
            new KernelToolHostStub($agent)->resolve($this->catalogTool())
        );
    }

    /**
     * @return array{0: Agent, 1: Integrations}
     */
    private function kernelServer(): array
    {
        // The real global row, not a fixture: the connection gate resolves the server by name, and a
        // second row answering to `kernel_mcp` is not a state production can be in.
        $integration = BrowserFiles::integration();

        if ($integration === null) {
            $this->markTestSkipped('The kernel_mcp server row is not in this database.');
        }

        $agent = $this->makeAgent();
        $catalogRow = CapabilityTool::query()
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('integrations_id', $integration->getId())
            ->where('apps_id', 0)
            ->first();

        if ($catalogRow === null) {
            $this->markTestSkipped('The Kernel catalog row is not in this database.');
        }

        // The server's own catalog row, granted with a stored credential: a second row for the same
        // integration is not a state production can be in, and the gate resolves the row by integration.
        $this->grantWithCredential($agent, $catalogRow);

        return [$agent, $integration];
    }

    /**
     * @param array<string, string> $contents
     * @param array<string, int> $oversized
     */
    private function fileTool(Agent $agent, array $contents, array $oversized = []): SaveBrowserFileTool
    {
        return new SaveBrowserFileTool($agent, new FakeBrowserFiles($agent, $contents, $oversized))
            ->withContext($this->mcpApp, $this->mcpCompany, $this->mcpUser);
    }

    /**
     * @param array<string, string> $contents
     */
    private function downloadsTool(Agent $agent, array $contents): SaveBrowserDownloadsTool
    {
        return new SaveBrowserDownloadsTool($agent, new FakeBrowserFiles($agent, $contents))
            ->withContext($this->mcpApp, $this->mcpCompany, $this->mcpUser);
    }

    private function catalogTool(): CapabilityTool
    {
        $tool = new CapabilityTool();
        $tool->apps_id = 0;
        $tool->name = 'Save Browser File ' . uniqid();
        $tool->description = 'Copy one file out of a Kernel browser session.';
        $tool->tool_type = 'system';
        $tool->handler = SaveBrowserFileTool::class;
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        return $tool;
    }
}
