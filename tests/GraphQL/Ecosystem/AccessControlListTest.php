<?php
declare(strict_types=1);
namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class AccessControlListTest extends TestCase
{
    /**
     * testCreateRole
     *
     * @return void
     */
    public function testCreateRole() : void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin'
            ]
        )->assertJson([
            'data' => [
                'createRole' => [
                    'name' => 'No Admin',
                    'title' => 'No Admin'
                ]
            ]
        ]);
    }

    /**
     * testGetRole
     *
     * @return void
     */
    public function testGetRole(): void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin'
            ]
        )->assertJson([
            'data' => [
                'createRole' => [
                    'name' => 'No Admin',
                    'title' => 'No Admin'
                ]
            ]
        ]);
        $response = $this->graphQL(/** @lang GraphQL */
            '
            {
                roles{
                    name,
                    id
                }
            }
            '
        );
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * testUpdateRole
     *
     * @return void
     */
    public function testUpdateRole(): void
    {
        $user = auth()->user();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation(
                $name: String!
                $title: String
            ) {
                createRole(
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'name' => 'No Admin',
                'title' => 'No Admin'
            ]
        )->assertJson([
            'data' => [
                'createRole' => [
                    'name' => 'No Admin',
                    'title' => 'No Admin'
                ]
            ]
        ]);
        $response = $this->graphQL(/** @lang GraphQL */
            '
            {
                roles{
                    name,
                    id
                }
            }
            '
        );
        $id = $response->json('data.roles.*.id');

        $this->graphQL(/** @lang GraphQL */
            '
            mutation(
                $id: Int!
                $name: String!
                $title: String
            ) {
                updateRole(
                    id: $id
                    name: $name
                    title: $title
                ) {
                    id,
                    name
                    title
                }
            }',
            [
                'id' => $id[0],
                'name' => 'Role Updated',
                'title' => 'Role Updated'
            ]
        )->assertJson([
            'data' => [
                'updateRole' => [
                    'name' => 'Role Updated',
                    'title' => 'Role Updated',
                    'id' => $id[0]
                ]
            ]
        ]);
    }
}
