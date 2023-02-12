<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Customers\Models\PeoplesRelationships;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Leads;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Spatie\LaravelData\Data;

class LeadsParticipant extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Leads $lead,
        public readonly Peoples $people,
        public ?PeoplesRelationships $relationship = null,
    ) {
    }

    public static function viaRequest(array $request): self
    {
        $company = auth()->user()->getCurrentCompany();
        $lead = LeadsRepository::getById($request['lead_id']);
        $people = PeoplesRepository::getById($request['people_id']);
        $relationship = $request['relationship_id'] ? PeoplesRepository::getRelationshipTypeById($request['relationship_id'], $company) : null;

        return new self(
            app(Apps::class),
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $company,
            auth()->user(),
            $lead,
            $people,
            $relationship
        );
    }
}
