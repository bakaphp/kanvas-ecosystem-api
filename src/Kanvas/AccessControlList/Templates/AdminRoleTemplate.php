<?php
declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

class AdminRoleTemplate extends RoleTemplate
{
    public string $role = 'Admin';
    public array $denied = [];
    public array $allowed = [];
}