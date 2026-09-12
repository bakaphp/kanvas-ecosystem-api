<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

/**
 * MCP servers live in `integrations` too, but are connected per agent — the generic config form must
 * never show them. `type` is what lets each UI ask for only the rows it can set up.
 */
final class IntegrationTypeFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'workflow'];

    public function testANewIntegrationIsKeyTypedByDefault(): void
    {
        $name = $this->makeIntegration(null);

        $this->assertSame([['name' => $name, 'type' => 'key']], $this->integrationsNamed($name, 'EQ', 'key'));
    }

    public function testMcpServersCanBeFilteredOutOfTheIntegrationsList(): void
    {
        $name = $this->makeIntegration(IntegrationTypeEnum::MCP);

        $this->assertSame([], $this->integrationsNamed($name, 'NEQ', 'mcp'));
        $this->assertSame([['name' => $name, 'type' => 'mcp']], $this->integrationsNamed($name, 'EQ', 'mcp'));
    }

    private function makeIntegration(?IntegrationTypeEnum $type): string
    {
        $integration = new Integrations();
        $integration->uuid = (string) Str::uuid();
        $integration->name = 'type_filter_' . Str::random(12);
        $integration->handler = InternalHandler::class;
        $integration->apps_id = 0;
        $integration->config = [];
        $integration->is_deleted = 0;

        if ($type !== null) {
            $integration->type = $type->value;
        }

        $integration->saveOrFail();

        return $integration->name;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function integrationsNamed(string $name, string $operator, string $type): array
    {
        $response = $this->graphQL(sprintf(
            'query {
                integrations(where: { AND: [
                    { column: NAME, operator: EQ, value: "%s" },
                    { column: TYPE, operator: %s, value: "%s" }
                ] }) {
                    data { name type }
                }
            }',
            $name,
            $operator,
            $type
        ));

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), (string) $response->getContent());

        return $response->json('data.integrations.data');
    }
}
