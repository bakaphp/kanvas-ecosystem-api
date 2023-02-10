<?php
declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class UsersTest extends TestCase
{
    public function testChangePassword()
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation changePassword(
                $new_password: String!
                $new_password_confirmation: String!
            ) {
                changePassword(
                    new_password: $new_password
                    new_password_confirmation: $new_password_confirmation)
            }
        ', [
            'new_password' => 'abc123456',
            'new_password_confirmation' => 'abc123456'
        ])->assertJson([
            'data' => [
                'changePassword' => true
            ]
        ]);
    }
}
