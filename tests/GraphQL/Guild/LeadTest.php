<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadTest extends TestCase
{
    public function testGetLeads(): void
    {
        $this->graphQL('
            query {
                leads {
                    data {
                        uuid
                        title
                    }
                }
            }')->assertSee('uuid');
    }
}
