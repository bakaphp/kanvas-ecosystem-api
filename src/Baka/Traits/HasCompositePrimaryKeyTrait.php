<?php

declare(strict_types=1);

namespace Baka\Traits;

use Thiagoprz\CompositeKey\HasCompositeKey;

/**
 * @deprecated , move to use HasCompositeKey directly
 */
trait HasCompositePrimaryKeyTrait
{
    use HasCompositeKey;
}
