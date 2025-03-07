<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Tests\TestCase;

class ActionsTest extends TestCase
{
    public function testGetWorkflowActions(): void
    {
        $response = $this->graphQL('
            query {
                actions {
                    data {
                        id
                        name
                        model_name
                    }
                }
            }');

        $this->assertNotEmpty($response->json()['data']['actions']['data'][0]);
    }
}
