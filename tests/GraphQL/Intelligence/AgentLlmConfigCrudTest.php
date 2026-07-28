<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Tests\TestCase;

class AgentLlmConfigCrudTest extends TestCase
{
    private function validInput(): array
    {
        return [
            'name' => 'Box ' . fake()->unique()->word(),
            'provider' => 'openai_like',
            'base_uri' => 'https://box.example/v1',
            'api_key' => 'secret-key-value',
            'model' => 'Qwen3.6-35B-A3B-4bit',
            'is_active' => true,
        ];
    }

    public function testCreateAgentLlmConfig(): void
    {
        $input = $this->validInput();

        $this->graphQL('
            mutation($input: AgentLlmConfigInput!) {
                createAgentLlmConfig(input: $input) {
                    id
                    name
                    provider
                    base_uri
                    model
                    has_api_key
                    is_active
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson(['data' => ['createAgentLlmConfig' => [
            'name' => $input['name'],
            'provider' => 'openai_like',
            'base_uri' => 'https://box.example/v1',
            'model' => 'Qwen3.6-35B-A3B-4bit',
            'has_api_key' => true,
            'is_active' => true,
        ]]]);
    }

    public function testApiKeyIsNeverExposedInSchema(): void
    {
        $input = $this->validInput();

        // The response type has no api_key field — selecting it must be a validation error.
        $this->graphQL('
            mutation($input: AgentLlmConfigInput!) {
                createAgentLlmConfig(input: $input) { id api_key }
            }
        ', ['input' => $input])
        ->assertGraphQLErrorMessage('Cannot query field "api_key" on type "AgentLlmConfig".');
    }

    public function testUpdateAgentLlmConfigKeepsKeyWhenOmitted(): void
    {
        $created = $this->graphQL('
            mutation($input: AgentLlmConfigInput!) {
                createAgentLlmConfig(input: $input) { id has_api_key }
            }
        ', ['input' => $this->validInput()])->assertSuccessful();
        $id = $created->json('data.createAgentLlmConfig.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAgentLlmConfigInput!) {
                updateAgentLlmConfig(id: $id, input: $input) {
                    id
                    model
                    has_api_key
                }
            }
        ', ['id' => $id, 'input' => ['model' => 'Qwen-updated']])
        ->assertSuccessful()
        ->assertJson(['data' => ['updateAgentLlmConfig' => [
            'model' => 'Qwen-updated',
            'has_api_key' => true,
        ]]]);
    }

    public function testDeleteAgentLlmConfig(): void
    {
        $created = $this->graphQL('
            mutation($input: AgentLlmConfigInput!) {
                createAgentLlmConfig(input: $input) { id }
            }
        ', ['input' => $this->validInput()])->assertSuccessful();
        $id = $created->json('data.createAgentLlmConfig.id');

        $this->graphQL('
            mutation($id: ID!) { deleteAgentLlmConfig(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAgentLlmConfig' => true]]);
    }

    public function testListAgentLlmConfigs(): void
    {
        $this->graphQL('
            mutation($input: AgentLlmConfigInput!) {
                createAgentLlmConfig(input: $input) { id }
            }
        ', ['input' => $this->validInput()])->assertSuccessful();

        $this->graphQL('
            query {
                agentLlmConfigs(orderBy: [{ column: ID, order: DESC }]) {
                    data { id name provider has_api_key }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['agentLlmConfigs' => ['data' => [['id', 'name', 'provider', 'has_api_key']]]]]);
    }
}
