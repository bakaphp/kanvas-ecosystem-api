<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class AgentTest extends TestCase
{
    public function testGetLeads(): void
    {
        $this->graphQL('
            query {
                agents {
                    data {
                        member_id
                        name
                        user {
                            displayname
                        }
                    }
                }
            }')->assertOk();
    }
}
