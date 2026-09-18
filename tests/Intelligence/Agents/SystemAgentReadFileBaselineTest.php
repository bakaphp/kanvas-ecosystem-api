<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Coding\ProgrammingAgent;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * read_file is baseline for internal system agents and must never become baseline anywhere wider:
 * it resolves any filesystem_id in the tenant, so a customer surface holding it hands a prospect the
 * whole company drive one coaxed id away.
 *
 * Two guarantees, because the subclasses assemble their toolsets three different ways — some call
 * parent::tools(), some call identityTools() directly, one does neither:
 *  - every internal system agent actually ends up with it
 *  - a customer-facing subclass would not, even though it inherits the baseline
 */
class SystemAgentReadFileBaselineTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);
    }

    /**
     * @return list<string>
     */
    private function toolNames(SystemUserAgent $handler): array
    {
        $handler->setConfiguration(agent: $this->makeAgent(), user: auth()->user());

        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_values(array_map(
            static fn (object $tool): string => method_exists($tool, 'getName')
                ? (string) $tool->getName()
                : (string) ($tool->name ?? ''),
            $tools,
        ));
    }

    /**
     * One per assembly style: parent::tools() (AP, CompanyBrain), identityTools() spread
     * (SalesManager, Programming), and identityTools() merged with a hand-built core (PM).
     */
    public static function internalAgentProvider(): array
    {
        return [
            'accounts payable (parent::tools)' => [AccountsPayableAgent::class],
            'company brain (parent::tools)' => [CompanyBrainAgent::class],
            'sales manager (identityTools)' => [SalesManagerAgent::class],
            'programming (identityTools spread)' => [ProgrammingAgent::class],
            'project manager (identityTools merge)' => [ProjectManagerAgent::class],
        ];
    }

    #[DataProvider('internalAgentProvider')]
    public function testInternalSystemAgentsCanReadFiles(string $handlerClass): void
    {
        $names = $this->toolNames(new $handlerClass());

        $this->assertContains('read_file', $names, "{$handlerClass} should hold read_file");
    }

    /** The PM inherits it and also hand-builds its core, so a second `read_file` is one edit away. */
    public function testProjectManagerRegistersReadFileExactlyOnce(): void
    {
        $names = $this->toolNames(new ProjectManagerAgent());

        $this->assertSame(1, count(array_keys($names, 'read_file', true)));
    }

    public function testCustomerFacingSubclassIsDeniedReadFile(): void
    {
        $handler = new class () extends SystemUserAgent implements ConversesWithCustomer {};

        $this->assertNotContains(
            'read_file',
            $this->toolNames($handler),
            'A customer-facing agent must never inherit tenant-wide file access',
        );
    }

    public function testUnconfiguredAgentExposesNothing(): void
    {
        $handler = new SystemUserAgent();

        $this->assertSame([], new ReflectionMethod($handler, 'tools')->invoke($handler));
    }
}
