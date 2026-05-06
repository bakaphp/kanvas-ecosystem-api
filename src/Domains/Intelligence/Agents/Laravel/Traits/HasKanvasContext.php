<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;

    public function withContext(Apps $app, Companies $company): static
    {
        $this->app = $app;
        $this->company = $company;

        return $this;
    }
}
