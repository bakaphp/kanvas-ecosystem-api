<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Support\Setup;
use Kanvas\Inventory\Support\Setup as SupportSetup;
use Kanvas\Roles\Models\Roles;
use Kanvas\Social\Support\Setup as SocialSupportSetup;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

class TestCase extends BaseTestCase
{
    use MakesGraphQLRequests;

    protected string $graphqlVersion = 'graphql';

    protected static ?Users $cachedUser = null;
    protected static bool $domainSetupComplete = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'testing');

        Dotenv::createImmutable(base_path())->load();

        if (static::$cachedUser) {
            $this->actingAs(static::$cachedUser, 'api');
        }
    }

    public function createUser(): Users
    {
        $dto = RegisterPostDataDto::from([
            'email' => fake()->email,
            'password' => fake()->password(8),
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
        ]);

        return new RegisterUsersAction($dto)->execute();
    }

    /**
     * Returns the Stripe test secret key from env, or marks the test skipped
     * when no real key is available. Use in setUp() of any test that hits
     * live Stripe — keeps the suite green in environments without secrets.
     *
     * Checks $_ENV / $_SERVER / getenv() in that order because Dotenv::createImmutable
     * (used in this codebase's TestCase) populates $_ENV/$_SERVER but not getenv().
     */
    protected function requireStripeTestKey(): string
    {
        $key = $_ENV['TEST_STRIPE_SECRET_KEY']
            ?? $_SERVER['TEST_STRIPE_SECRET_KEY']
            ?? getenv('TEST_STRIPE_SECRET_KEY');

        if (! is_string($key) || ! str_starts_with($key, 'sk_') || strlen($key) < 20) {
            $this->markTestSkipped('TEST_STRIPE_SECRET_KEY env var not set; skipping live-Stripe test.');
        }

        return $key;
    }

    protected function graphQLEndpointUrl(array $routeParams = []): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);

        $routeName = match ($this->graphqlVersion) {
            'graphql' => $config->get('lighthouse.route.name'),
            'graphql-2026-01' => $config->get('lighthouse-multi-schema.multi_schemas.schema1.route_name'),
            default => $config->get('lighthouse.route.name'),
        };

        return route($routeName, $routeParams);
    }

    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->app = $app;
        $currentApp = app(Apps::class);

        if (! static::$domainSetupComplete) {
            $data = AppInput::from([
                'name' => $currentApp->name,
                'url' => $currentApp->url,
                'description' => $currentApp->description,
                'domain' => $currentApp->domain ?? 'kanvas-ecosystem-api.test',
                'is_actived' => 1,
                'ecosystem_auth' => 1,
                'payments_active' => 0,
                'is_public' => 1,
                'domain_based' => 0,
            ]);
            new CreateAppsAction($data, new Users())->acl($currentApp);

            $currentApp->set(AppSettingsEnums::ONE_SIGNAL_APP_ID->getValue(), 'test-onesignal-app-id');
            $currentApp->set(AppSettingsEnums::ONE_SIGNAL_REST_API_KEY->getValue(), 'test-onesignal-rest-api-key');

            Roles::firstOrCreate([
                'name' => 'Admins',
                'apps_id' => $currentApp->getId(),
            ], [
                'companies_id' => 1,
                'is_active' => 1,
                'scope' => 0,
            ]);

            $user = $this->createUser();

            $company = $user->getCurrentCompany();
            new Setup($currentApp, $user, $company)->run();
            new SupportSetup($currentApp, $user, $company)->run();
            new SocialSupportSetup($currentApp, $user, $company)->run();

            static::$cachedUser = $user;
            static::$domainSetupComplete = true;
        }

        if (static::$cachedUser) {
            $this->actingAs(static::$cachedUser, 'api');
        }

        return $app;
    }
}
