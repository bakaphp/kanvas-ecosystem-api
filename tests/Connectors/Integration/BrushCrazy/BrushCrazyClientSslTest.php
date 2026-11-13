<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the source credentials are app settings, which live in Redis and on `ecosystem` and are
 * rolled back by neither. Laravel resolves the PDO lazily, so building the client never connects.
 */
#[Group('serial')]
final class BrushCrazyClientSslTest extends TestCase
{
    private const array APP_SETTINGS = [
        ConfigurationEnum::BRUSHCRAZY_DB_HOST,
        ConfigurationEnum::BRUSHCRAZY_DB_DATABASE,
        ConfigurationEnum::BRUSHCRAZY_DB_SSL_CA,
    ];

    /** @var array<string, mixed> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);

        foreach (self::APP_SETTINGS as $key) {
            $this->originalSettings[$key->value] = $app->get($key->value);
        }

        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value, 'BrushCrazyProd');
        $app->del(ConfigurationEnum::BRUSHCRAZY_DB_SSL_CA->value);
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->originalSettings as $key => $value) {
            $value !== null ? $app->set($key, $value) : $app->del($key);
        }

        parent::tearDown();
    }

    public function testAnRdsHostWithoutACaSettingUsesTheBundledRdsCa(): void
    {
        $options = $this->pdoOptionsFor('brushcrazy-main-database.example.us-east-2.rds.amazonaws.com');

        $this->assertSame(Client::RDS_CA_BUNDLE, $options[PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertFileExists($options[PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
    }

    public function testAnExplicitCaSettingWinsOverTheBundledOne(): void
    {
        app(Apps::class)->set(ConfigurationEnum::BRUSHCRAZY_DB_SSL_CA->value, '/etc/ssl/custom-ca.pem');

        $options = $this->pdoOptionsFor('brushcrazy-main-database.example.us-east-2.rds.amazonaws.com');

        $this->assertSame('/etc/ssl/custom-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA]);
    }

    public function testANonRdsHostWithoutACaSettingConnectsWithoutSsl(): void
    {
        $this->assertSame([], $this->pdoOptionsFor('mysql.internal'));
    }

    /**
     * @return array<int, mixed>
     */
    private function pdoOptionsFor(string $host): array
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value, $host);

        new Client($app);

        return Config::get('database.connections.' . Client::connectionName($app) . '.options');
    }
}
