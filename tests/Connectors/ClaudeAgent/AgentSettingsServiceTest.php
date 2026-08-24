<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSettingsService;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * Every "is this configured?" decision in the connector runs through here — whether to mount repos,
 * whether to declare the MCP server, whether the pushed agent is still current. Each one fails in a
 * different confusing way when the read is wrong, so the reading rules are pinned directly.
 */
final class AgentSettingsServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    /**
     * The agent version is written as an int and reads back as one. Dropping it as "not a string"
     * made the pushed-agent fingerprint compare against 0, which re-pushed the agent on every turn
     * and rotated the session with it.
     */
    public function testANumericFieldReadsBackAsAString(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $agent->set(CustomFieldEnum::CLAUDE_AGENT_VERSION->value, 4);

        $this->assertSame('4', AgentSettingsService::get($agent, CustomFieldEnum::CLAUDE_AGENT_VERSION));
    }

    /** Clearing a field in the UI writes '' rather than deleting the row. */
    public function testABlankFieldCountsAsUnset(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, '   ');

        $this->assertNull(AgentSettingsService::get($agent, CustomFieldEnum::CLAUDE_GITHUB_TOKEN));
    }

    public function testAnUnsetFieldIsNull(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        $this->assertNull(AgentSettingsService::vaultId($agent));
    }

    /** The repo allow-list is an array field; asking for it as a string must not stringify it. */
    public function testAnArrayFieldIsNotStringified(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $agent->set(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value, [['slug' => 'api']]);

        $this->assertNull(AgentSettingsService::get($agent, CustomFieldEnum::CLAUDE_ALLOWED_REPOS));
    }
}
