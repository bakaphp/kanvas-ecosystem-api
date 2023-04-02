<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function testGet()
    {
        $app = app(Apps::class);

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                appUsers(first: 10) {
                    data {
                        id,
                        email,
                        created_at
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $this->assertArrayHasKey('data', $response);
    }

   
}
