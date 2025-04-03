<?php
declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

use Kanvas\AccessControlList\Templates\ModulesRepositories;

class OwnerRoleTemplate extends RoleTemplate
{
    public string $role = 'Owner';
    public array $denied = [];
    public array $allowed = [];
}