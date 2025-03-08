<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class CustomFieldsTypeTest extends TestCase
{
    public function testCustomFieldsType(): void
    {
        $this->graphQL( /** @lang GraphQL */
            '
            query {
                customFieldTypes {
                    data {
                        id,
                        name,
                        description
                    }
                }
            }'
        )->assertJsonStructure([
            'data' => [
                'customFieldTypes' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description'
                        ]
                    ]
                ]
            ]
        ]);
    }
}
