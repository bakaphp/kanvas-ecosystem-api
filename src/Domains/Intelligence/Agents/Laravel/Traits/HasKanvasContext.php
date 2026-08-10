<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected ?Agent $agent = null;

    public function withContext(Apps $app, Companies $company, ?Agent $agent = null): static
    {
        $this->app = $app;
        $this->company = $company;
        $this->agent = $agent;

        return $this;
    }
}
