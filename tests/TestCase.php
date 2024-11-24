<?php

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

    protected string $graphqlVersion = 'graphql';

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
}
