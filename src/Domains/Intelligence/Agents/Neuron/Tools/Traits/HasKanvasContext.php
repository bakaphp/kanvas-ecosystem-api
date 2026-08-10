<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function withContext(
        Apps $app,
        Companies $company,
        Users $user
    ): static {
        $this->app = $app;
        $this->company = $company;
        $this->user = $user;

        return $this;
    }
}
