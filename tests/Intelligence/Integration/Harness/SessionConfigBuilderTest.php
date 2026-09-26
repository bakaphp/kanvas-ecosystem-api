<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\SessionConfigBuilder;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial because it writes app settings, which live in Redis as well as `ecosystem` and are therefore
 * shared by every paratest process.
 */
#[Group('serial')]
class SessionConfigBuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string PROVIDER_ID = 'kanvas-test-oai';
    private const string MODEL = 'gpt-test-1';
    private const string BASE_URL = 'https://provider.example.test/v1';
    private const string ENV_VAR = 'KANVAS_TEST_PROVIDER_KEY';
    private const string API_KEY = 'sk-test-never-written-to-disk-9f3a';

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);

        // Only the `ecosystem` half of a setting is inside the test transaction, so whatever this app
        // already had has to be put back by hand.
        foreach ($this->managedSettings() as $setting) {
            $this->originalAppSettings[$setting->value] = $app->get($setting->value);
        }
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }

        parent::tearDown();
    }

    public function testInstructionsNameTheKanvasOwnedFilesAndTheRepositoryHouseRules(): void
    {
        $instructions = new SessionConfigBuilder(app(Apps::class))->toArray()['instructions'];

        $this->assertContains('.kanvas/agent.md', $instructions);
        $this->assertContains('.kanvas/context.md', $instructions);
        $this->assertContains('.claude/CLAUDE.md', $instructions);
    }

    public function testBashPermissionsDenyByDefaultAndAllowTheRepositoryToolchain(): void
    {
        $bash = new SessionConfigBuilder(app(Apps::class))->toArray()['permission']['bash'];

        $this->assertSame('deny', $bash['*']);
        $this->assertSame('allow', $bash['git *']);
    }

    public function testProviderIsDeclaredOpenAiCompatibleAndTheApiKeyNeverReachesTheFile(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::PROVIDER_ID->value, self::PROVIDER_ID);
        $app->set(ConfigurationEnum::MODEL->value, self::MODEL);
        $app->set(ConfigurationEnum::PROVIDER_BASE_URL->value, self::BASE_URL);
        $app->set(ConfigurationEnum::PROVIDER_ENV_VAR->value, self::ENV_VAR);
        $app->set(ConfigurationEnum::PROVIDER_API_KEY->value, self::API_KEY);

        $builder = new SessionConfigBuilder($app);
        $config = $builder->toArray();

        $this->assertArrayHasKey(self::PROVIDER_ID, $config['provider']);
        $provider = $config['provider'][self::PROVIDER_ID];

        $this->assertSame('@ai-sdk/openai-compatible', $provider['npm']);
        $this->assertSame([self::ENV_VAR], $provider['env']);
        $this->assertSame(self::BASE_URL, $provider['options']['baseURL']);
        $this->assertSame(self::PROVIDER_ID . '/' . self::MODEL, $config['model']);

        // The whole point of naming the variable rather than inlining the secret: the config file is
        // written into a workspace the agent can read.
        $json = $builder->toJson();
        $this->assertStringContainsString(self::ENV_VAR, $json);
        $this->assertFalse(str_contains($json, self::API_KEY));
    }

    public function testProtectedPathsBecomeBashDenyRules(): void
    {
        $repository = new CodingRepository(
            slug: 'widgets',
            cloneUrl: 'https://git.example.test/acme/widgets.git',
            protectedPaths: ['.github/', 'config/'],
        );

        $bash = new SessionConfigBuilder(app(Apps::class), $repository)->toArray()['permission']['bash'];

        $this->assertSame('deny', $bash['* .github/*']);
        $this->assertSame('deny', $bash['* config/*']);
    }

    /**
     * @return list<ConfigurationEnum>
     */
    private function managedSettings(): array
    {
        return [
            ConfigurationEnum::PROVIDER_ID,
            ConfigurationEnum::MODEL,
            ConfigurationEnum::PROVIDER_BASE_URL,
            ConfigurationEnum::PROVIDER_ENV_VAR,
            ConfigurationEnum::PROVIDER_API_KEY,
        ];
    }
}
