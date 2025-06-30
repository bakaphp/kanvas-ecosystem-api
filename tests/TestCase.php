<?php

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Guild\Support\Setup;
use Kanvas\Inventory\Support\Setup as SupportSetup;
use Kanvas\Social\Support\Setup as SocialSupportSetup;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

class TestCase extends BaseTestCase
{
    use MakesGraphQLRequests;

    protected string $graphqlVersion = 'graphql';

    protected function setUp(): void
    {
        parent::setUp();
        Dotenv::createImmutable(base_path())->load(); //load .env not .env.testing
    }

    /**
     * createUser.
     */
    public function createUser(): Users
    {
        $dto = RegisterPostDataDto::from([
            'email' => fake()->email,
            'password' => fake()->password(8),
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
        ]);
        $user = (new RegisterUsersAction($dto))->execute();

        return $user;
    }

    protected function graphQLEndpointUrl(array $routeParams = []): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);

        $routeName = match ($this->graphqlVersion) {
            'graphql' => $config->get('lighthouse.route.name'),
            'graphql-2025-01' => $config->get('lighthouse-multi-schema.multi_schemas.schema1.route_name'),
            default => $config->get('lighthouse.route.name'),
        };

        return route($routeName, $routeParams);
    }

    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        //  $kanvasApp = Apps::factory(1)->create();

        /*    $app->bind(Apps::class, function () use ($kanvasApp) {
               return $kanvasApp;
           }); */

        //$user = Users::where('id', '>', 0)->first();
        //$user = Users::factory(1)->create()->first();
        $this->app = $app;
        $user = $this->createUser();
        $this->actingAs($user, 'api');

        //setup CRM
        $company = $user->getCurrentCompany();
        $setupGuild = new Setup(
            app(Apps::class),
            $user,
            $company
        );
        $setupGuild->run();

        //setup inventory
        $setupInventory = new SupportSetup(
            app(Apps::class),
            $user,
            $company
        );
        $setupInventory->run();

        //setup social
        $setupSocial = new SocialSupportSetup(
            app(Apps::class),
            $user,
            $company
        );
        $setupSocial->run();

        return $app;
    }
}
