<?php

declare(strict_types=1);

namespace Kanvas\Companies\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Actions\DeleteCompaniesAction;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Auth;
use Baka\Traits\KanvasJobsTrait;

class DeleteCompanyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        public int $companiesId,
        public Users $user,
        public Apps $app
    ) {
    }

    public function handle(): void
    {
        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppService($this->app);

        $companyDelete = new DeleteCompaniesAction($this->user, $this->app);
        $companyDelete->execute($this->companiesId);
    }
}
