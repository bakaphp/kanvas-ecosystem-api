<?php
declare(strict_types=1);
namespace Kanvas\AccessControlList\Templates;

class UsersRoleTemplate extends RoleTemplate
{
    public string $role = 'Users';
    public array $denied = [];
    public array $allowed = [];
}
