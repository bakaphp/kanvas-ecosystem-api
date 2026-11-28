<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Session\SessionTokenService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Mockery;

trait CreatesVinSolutionAppointmentLead
{
    protected function makeVinSolutionMappedLead(
        ?string $vinLeadId = '700200300',
        ?string $contactId = '800200300',
        bool $withSession = true
    ): Lead {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId(Users::factory()->create()->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->people_id = $people->getId();
        $lead->saveQuietly();
        $lead->refresh();

        $company->set(ConfigurationEnum::COMPANY->value, '50010');
        $lead->user->set(ConfigurationEnum::getUserKey($company, $lead->user), '60020');

        if ($vinLeadId !== null) {
            $lead->set(CustomFieldEnum::LEADS->value, $vinLeadId);
        }

        if ($contactId !== null) {
            $people->set(CustomFieldEnum::CONTACT->value, $contactId);
        }

        if ($withSession) {
            $company->set(ConfigurationEnum::SESSION_COOKIES->value, 'ASP.NET_SessionId=x; .ASPXAUTH=y');
        } else {
            $company->del(ConfigurationEnum::SESSION_COOKIES->value);
        }

        return $lead;
    }

    protected function vinSolutionSessionReturning(string $token = 'token-xyz'): SessionTokenService
    {
        $session = Mockery::mock(SessionTokenService::class);
        $session->shouldReceive('getAccessToken')->andReturn($token);

        return $session;
    }
}
