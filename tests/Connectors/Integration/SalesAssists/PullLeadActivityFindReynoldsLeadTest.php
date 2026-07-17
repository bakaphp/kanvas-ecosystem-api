<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PullLeadActivityFindReynoldsLeadTest extends TestCase
{
    /**
     * Regression for KANVAS-ECOSYSTEM-61X: the phone predicate used
     * `->where('REGEXP_REPLACE(pc.value, "[^0-9]", "") = ?', $phone)`, which
     * Laravel treats as `where(column, value)` — it backtick-quotes the raw SQL
     * as a column name and MySQL throws SQLSTATE[42S22] Unknown column. The fix
     * is `->whereRaw(...)`. This test drives the phone branch and asserts the
     * query executes and matches the lead by its (formatting-stripped) phone.
     */
    public function testFindReynoldsLeadMatchesByPhone(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $phone = Str::sanitizePhoneNumber($lead->people->getCellPhones()->first()->value);

        $match = $this->invokeFindReynoldsLead($app, $company, null, null, $phone);

        $this->assertNotNull($match);
        $this->assertSame($lead->getId(), $match->getId());
    }

    public function testFindReynoldsLeadMatchesByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $email = $lead->people->getEmails()->first()->value;

        $match = $this->invokeFindReynoldsLead($app, $company, null, $email, null);

        $this->assertNotNull($match);
        $this->assertSame($lead->getId(), $match->getId());
    }

    public function testFindReynoldsLeadReturnsNullWhenNoIdentifiers(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->assertNull($this->invokeFindReynoldsLead($app, $company, null, null, null));
    }

    private function invokeFindReynoldsLead(
        Apps $app,
        Companies $company,
        ?string $leadId,
        ?string $email,
        ?string $phone,
    ): ?Lead {
        // findReynoldsLead reads only its parameters, so a construct-less instance
        // is enough — the Workflow\Activity base needs 3 constructor args we don't have.
        $activity = new ReflectionClass(PullLeadActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PullLeadActivity::class, 'findReynoldsLead');

        return $method->invoke($activity, $app, $company, $leadId, $email, $phone);
    }
}
