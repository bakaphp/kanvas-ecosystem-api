<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Agents\Models\Agent;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function testGetLeads(): void
    {
        $this->graphQL('
            query {
                agents {
                    data {
                        member_id,
                        name,
                        status {
                            name
                        },
                        total_leads,
                        user {
                            displayname
                        }
                    }
                }
            }')->assertOk();
    }

    public function testSearchAgents(): void
    {
        $name = 'Agent-' . fake()->unique()->uuid();
        $company = auth()->user()->getCurrentCompany();

        Agent::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $name,
            'member_id' => Agent::getNextAgentNumber($company),
            'owner_id' => auth()->user()->getId(),
            'status_id' => 1,
        ]);

        $response = $this->graphQL(
            '
            query agents($search: String) {
                agents(search: $search) {
                    data {
                        name
                    }
                }
            }
            ',
            ['search' => $name]
        )->assertJsonStructure(['data' => ['agents' => ['data' => ['*' => ['name']]]]])
            ->decodeResponseJson()->json;

        $names = array_column(json_decode($response, true)['data']['agents']['data'], 'name');
        $this->assertContains($name, $names);
    }
}
