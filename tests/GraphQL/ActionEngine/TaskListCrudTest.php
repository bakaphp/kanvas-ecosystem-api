<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class TaskListCrudTest extends TestCase
{
    private function createCompanyAction(): array
    {
        $actionInput = [
            'name' => 'Action ' . fake()->uuid(),
            'description' => 'Action for task test',
            'is_active' => true,
            'is_published' => true,
        ];

        $actionResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $actionInput])->assertSuccessful();

        $actionId = $actionResponse->json('data.createAction.id');

        $companyActionInput = [
            'actions_id' => $actionId,
            'name' => 'Company Action ' . fake()->uuid(),
        ];

        $response = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $companyActionInput])->assertSuccessful();

        return $response->json('data.createCompanyAction');
    }

    public function testCreateTaskList(): void
    {
        $input = ['name' => 'Task List ' . fake()->word()];

        $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) {
                    id
                    uuid
                    name
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson(['data' => ['createTaskList' => ['name' => $input['name']]]]);
    }

    public function testUpdateTaskList(): void
    {
        $input = ['name' => 'Task List ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createTaskList.id');

        $updateInput = ['name' => 'Updated Task List ' . fake()->word()];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateTaskListInput!) {
                updateTaskList(id: $id, input: $input) {
                    id
                    name
                }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson(['data' => ['updateTaskList' => ['name' => $updateInput['name']]]]);
    }

    public function testDeleteTaskList(): void
    {
        $input = ['name' => 'Task List ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createTaskList.id');

        $this->graphQL('
            mutation($id: ID!) { deleteTaskList(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteTaskList' => true]]);
    }

    public function testListTaskLists(): void
    {
        $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => ['name' => 'Task List ' . fake()->word()]])->assertSuccessful();

        $this->graphQL('
            query {
                taskLists {
                    data {
                        id
                        uuid
                        name
                        config
                        tasks {
                            id
                            name
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'taskLists' => [
                    'data' => [
                        '*' => ['id', 'uuid', 'name'],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateTaskListItem(): void
    {
        $taskListInput = ['name' => 'Task List ' . fake()->word()];

        $taskListResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => $taskListInput])->assertSuccessful();

        $taskListId = $taskListResponse->json('data.createTaskList.id');
        $companyAction = $this->createCompanyAction();

        $input = [
            'task_list_id' => $taskListId,
            'name' => 'Task Item ' . fake()->word(),
            'companies_action_id' => $companyAction['id'],
            'weight' => 1.0,
        ];

        $this->graphQL('
            mutation($input: TaskListItemInput!) {
                createTaskListItem(input: $input) {
                    id
                    name
                    status
                    weight
                    action {
                        id
                        name
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson(['data' => ['createTaskListItem' => ['name' => $input['name']]]]);
    }

    public function testUpdateTaskListItem(): void
    {
        $taskListResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => ['name' => 'Task List ' . fake()->word()]])->assertSuccessful();

        $taskListId = $taskListResponse->json('data.createTaskList.id');
        $companyAction = $this->createCompanyAction();

        $input = [
            'task_list_id' => $taskListId,
            'name' => 'Task Item ' . fake()->word(),
            'companies_action_id' => $companyAction['id'],
            'weight' => 1.0,
        ];

        $createResponse = $this->graphQL('
            mutation($input: TaskListItemInput!) {
                createTaskListItem(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();

        $itemId = $createResponse->json('data.createTaskListItem.id');

        $updateInput = [
            'task_list_id' => $taskListId,
            'name' => 'Updated Task Item ' . fake()->word(),
            'companies_action_id' => $companyAction['id'],
            'weight' => 2.0,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateTaskListItemInput!) {
                updateTaskListItem(id: $id, input: $input) {
                    id
                    name
                    weight
                }
            }
        ', ['id' => $itemId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson(['data' => ['updateTaskListItem' => ['name' => $updateInput['name']]]]);
    }

    public function testDeleteTaskListItem(): void
    {
        $taskListResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => ['name' => 'Task List ' . fake()->word()]])->assertSuccessful();

        $taskListId = $taskListResponse->json('data.createTaskList.id');
        $companyAction = $this->createCompanyAction();

        $input = [
            'task_list_id' => $taskListId,
            'name' => 'Task Item ' . fake()->word(),
            'companies_action_id' => $companyAction['id'],
            'weight' => 1.0,
        ];

        $createResponse = $this->graphQL('
            mutation($input: TaskListItemInput!) {
                createTaskListItem(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $itemId = $createResponse->json('data.createTaskListItem.id');

        $this->graphQL('
            mutation($id: ID!) { deleteTaskListItem(id: $id) }
        ', ['id' => $itemId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteTaskListItem' => true]]);
    }

    public function testListTaskListItems(): void
    {
        $taskListResponse = $this->graphQL('
            mutation($input: TaskListInput!) {
                createTaskList(input: $input) { id }
            }
        ', ['input' => ['name' => 'Task List ' . fake()->word()]])->assertSuccessful();

        $taskListId = $taskListResponse->json('data.createTaskList.id');
        $companyAction = $this->createCompanyAction();

        $this->graphQL('
            mutation($input: TaskListItemInput!) {
                createTaskListItem(input: $input) { id }
            }
        ', ['input' => [
            'task_list_id' => $taskListId,
            'name' => 'Task Item ' . fake()->word(),
            'companies_action_id' => $companyAction['id'],
            'weight' => 1.0,
        ]])->assertSuccessful();

        $this->graphQL('
            query($where: QueryTaskListItemsWhereWhereConditions) {
                taskListItems(where: $where) {
                    data {
                        id
                        name
                        status
                        weight
                        config
                        action {
                            id
                            name
                        }
                        task_list {
                            id
                            name
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'TASK_LIST_ID',
                'operator' => 'EQ',
                'value' => $taskListId,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'taskListItems' => [
                    'data' => [
                        '*' => ['id', 'name', 'status', 'weight'],
                    ],
                ],
            ],
        ]);
    }
}
