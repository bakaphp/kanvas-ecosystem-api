<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use GraphQL\Language\AST\DirectiveNode;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Tests\TestCase;

final class UserMutationGuardTest extends TestCase
{
    private function directivesOf(string $field): array
    {
        $mutation = app(SchemaBuilder::class)->schema()->getMutationType();

        $this->assertTrue($mutation->hasField($field), $field . ' is missing from the schema.');

        return array_map(
            fn (DirectiveNode $directive) => $directive->name->value,
            iterator_to_array($mutation->getField($field)->astNode->directives)
        );
    }

    /**
     * uploadFileToUser sat in the unguarded block and only failed for anonymous
     * callers because the resolver fatals on a null user — a null dereference is
     * not an auth check.
     */
    public function testUserMutationsAreGuarded(): void
    {
        $mutations = [
            'updateUser',
            'updateEmail',
            'changePassword',
            'uploadFileToUser',
            'updatePhotoProfile',
            'requestDeleteAccount',
        ];

        foreach ($mutations as $mutation) {
            $this->assertContains('guard', $this->directivesOf($mutation), $mutation . ' is not guarded.');
        }
    }
}
