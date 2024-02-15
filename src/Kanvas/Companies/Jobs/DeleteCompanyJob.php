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

class DeleteCompanyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $companiesId,
        public Users $user
    ) {
    }

    public function handle(): void
    {
        $companyDelete = new DeleteCompaniesAction($this->user);
        $companyDelete->execute($this->companiesId);
    }
}
