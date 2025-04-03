<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Templates;

abstract class RoleTemplate
{
    public string $role;
    public array $denied;
    public array $allowed;
}
