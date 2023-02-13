<?php

declare(strict_types=1);

namespace Kanvas\Companies\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class DeleteCompaniesAction
{
    /**
     * Construct function.
     *
     * @param CompaniesPutData $data
     */
    public function __construct(
        protected Users $user
    ) {
    }

    /**
     * Invoke function.
     *
     * @param int $id
     *
     * @return Companies
     */
    public function execute(int $id): Companies
    {
        $companies = Companies::getById($id);

        if (!$companies->isOwner($this->user)) {
            throw new AuthorizationException('User cant delete this company');
        }

        $companies->softDelete();

        return $companies;
    }
}
