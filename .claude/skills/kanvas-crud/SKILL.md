---
name: kanvas-crud
description: Canonical pattern for building a new domain CRUD in Kanvas (DTO + Create/Update Actions + GraphQL Mutation + schema + tests). Load when scaffolding a new entity under `src/Domains/{Domain}/{Entity}/` or its GraphQL surface in `app/GraphQL/{Domain}/Mutations/{Entity}/` and `graphql/schemas/{Domain}/`. Skip for connectors (use kanvas-connector) or when only editing existing CRUD code.
---

# Kanvas Domain CRUD Pattern

## Project Structure

```
src/Domains/{DomainName}/{Entity}/
├── Actions/
│   ├── Create{Entity}Action.php    # Business logic for creation
│   └── Update{Entity}Action.php    # Business logic for updates
├── DataTransferObject/
│   └── {Entity}.php                # Spatie LaravelData DTO (named after entity, NOT {Entity}Input)
├── Models/
│   └── {Entity}.php                # Eloquent model
└── Enums/                          # Optional enums

app/GraphQL/{DomainName}/Mutations/{Entity}/
└── {Entity}Mutation.php            # GraphQL mutation resolver

graphql/schemas/{DomainName}/
└── {entity}.graphql                # GraphQL type, input, mutation, query definitions

tests/GraphQL/{DomainName}/
└── {Entity}CrudTest.php            # GraphQL CRUD tests
```

## 1. Data Transfer Object (DTO)

Location: `src/Domains/{Domain}/{Entity}/DataTransferObject/{Entity}.php`

Name the DTO class after the entity (e.g., `Action`, `Pipeline`). When importing in files that also use the model, alias it: `use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class {Entity} extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null,
        // ... other fields
        // Use model objects for FKs: public readonly RelatedModel $related,
    ) {
    }
}
```

## 2. Create Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Create{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};

class Create{Entity}Action
{
    public function __construct(
        protected readonly {Entity}Data $data,
    ) {
    }

    public function execute(): {Entity}
    {
        return DB::connection('{db_connection}')->transaction(function () {
            $entity = new {Entity}();
            $entity->apps_id = $this->data->app->getId();
            $entity->companies_id = $this->data->company->getId(); // 0 for global entities
            $entity->users_id = $this->data->user->getId();
            $entity->name = $this->data->name;
            // ... set other fields
            // For FK relationships: $entity->related_id = $this->data->related->getId();
            $entity->saveOrFail();

            return $entity;
        });
    }
}
```

## 3. Update Action

Location: `src/Domains/{Domain}/{Entity}/Actions/Update{Entity}Action.php`

```php
<?php

declare(strict_types=1);

namespace Kanvas\{Domain}\{Entity}\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};

class Update{Entity}Action
{
    public function __construct(
        protected readonly {Entity} $entity,
        protected readonly {Entity}Data $data,
    ) {
    }

    public function execute(): {Entity}
    {
        return DB::connection('{db_connection}')->transaction(function () {
            $this->entity->name = $this->data->name;
            // ... update other fields
            $this->entity->saveOrFail();

            return $this->entity;
        });
    }
}
```

## 4. GraphQL Mutation Resolver

Location: `app/GraphQL/{Domain}/Mutations/{Entity}/{Entity}Mutation.php`

```php
<?php

declare(strict_types=1);

namespace App\GraphQL\{Domain}\Mutations\{Entity};

use Kanvas\{Domain}\{Entity}\Actions\Create{Entity}Action;
use Kanvas\{Domain}\{Entity}\Actions\Update{Entity}Action;
use Kanvas\{Domain}\{Entity}\DataTransferObject\{Entity} as {Entity}Data;
use Kanvas\{Domain}\{Entity}\Models\{Entity};
use Kanvas\Apps\Models\Apps;

class {Entity}Mutation
{
    public function create(mixed $rootValue, array $request): {Entity}
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        // Look up related models from IDs before constructing DTO
        // $related = RelatedModel::getByIdFromCompanyApp((int) $input['related_id'], $company, $app);

        return new Create{Entity}Action(
            new {Entity}Data(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                // related: $related,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): {Entity}
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        // For global entities (no company scoping):
        $entity = {Entity}::getById((int) $request['id'], $app);
        // For company-scoped entities:
        // $entity = {Entity}::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new Update{Entity}Action(
            $entity,
            new {Entity}Data(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $entity->name,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $entity = {Entity}::getById((int) $request['id'], $app);

        return $entity->softDelete();
    }
}
```

## 5. GraphQL Schema

Location: `graphql/schemas/{Domain}/{entity}.graphql`

```graphql
input {Entity}Input {
    name: String!
    description: String
    # ... other fields
}

input Update{Entity}Input {
    name: String
    description: String
    # ... other fields (all optional for partial updates)
}

# Admin-only CUD operations
extend type Mutation @guardByAdmin {
    create{Entity}(input: {Entity}Input!): {Entity}!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@create")
    update{Entity}(id: ID!, input: Update{Entity}Input!): {Entity}!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@update")
    delete{Entity}(id: ID!): Boolean!
        @field(resolver: "App\\GraphQL\\{Domain}\\Mutations\\{Entity}\\{Entity}Mutation@delete")
}

# Read access for all authenticated users
extend type Query @guard {
    {entityPlural}(
        search: String @search
        where: _ @whereConditions(columns: ["id", "name", "slug"])
        orderBy: _ @orderBy(columns: ["id", "created_at", "updated_at", "name"])
    ): [{Entity}!]!
        @paginate(
            model: "Kanvas\\{Domain}\\{Entity}\\Models\\{Entity}"
            scopes: ["fromApp", "notDeleted"]
            defaultCount: 25
        )
}
```

## 6. Tests

Location: `tests/GraphQL/{Domain}/{Entity}CrudTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\GraphQL\{Domain};

use Tests\TestCase;

class {Entity}CrudTest extends TestCase
{
    public function testCreate{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];

        $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson(['data' => ['create{Entity}' => ['name' => $input['name']]]]);
    }

    public function testUpdate{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.create{Entity}.id');

        $updateInput = ['name' => 'Updated ' . fake()->word()];
        $this->graphQL('
            mutation($id: ID!, $input: Update{Entity}Input!) {
                update{Entity}(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful();
    }

    public function testDelete{Entity}(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: {Entity}Input!) {
                create{Entity}(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.create{Entity}.id');

        $this->graphQL('
            mutation($id: ID!) { delete{Entity}(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['delete{Entity}' => true]]);
    }

    public function testList{Entities}(): void
    {
        $this->graphQL('query { {entityPlural} { data { id name } } }')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['{entityPlural}' => ['data' => [['id', 'name']]]]]);
    }
}
```

## Related skills

- For `@search` on the list query, see `kanvas-search`.
- For connector-style integrations (Shopify, Stripe, etc.), see `kanvas-connector`.
- Cross-cutting conventions (DTO `fromMultiple`, enums, `@can`, `getById*` helpers, no FK ids in GraphQL response types) stay in root `.claude/CLAUDE.md` since they apply outside CRUD too.
