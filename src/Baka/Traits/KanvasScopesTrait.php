<?php

declare(strict_types=1);

namespace Baka\Traits;

trait KanvasScopesTrait
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;
    use NotDeletedScopesTrait;
    use IsPublicScopesTrait;
}
