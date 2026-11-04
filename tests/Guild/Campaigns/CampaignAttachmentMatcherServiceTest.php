<?php

declare(strict_types=1);

namespace Tests\Guild\Campaigns;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Services\CampaignAttachmentMatcherService;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;

class CampaignAttachmentMatcherServiceTest extends TestCase
{
    use DatabaseTransactions;
    use MakesFilesystemRows;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private const string FIELD = 'participant_id';

    private function person(Companies $company, ?string $participantId): People
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        if ($participantId !== null) {
            $person->set(self::FIELD, $participantId);
        }

        return $person;
    }

    public function testMatchesByNameStemCaseInsensitively(): void
    {
        $company = Companies::factory()->create();
        $person = $this->person($company, 'PA40876');
        $file = $this->makeFilesystemRow($company->getId(), 'pa40876.PDF');

        $result = new CampaignAttachmentMatcherService()->match(new Collection([$person]), new Collection([$file]), self::FIELD);

        $this->assertSame([$person->getId() => [$file->url]], $result['matched']);
        $this->assertSame([], $result['unmatched_people']);
        $this->assertSame([], $result['unmatched_files']);
    }

    public function testOnePersonMayReceiveSeveralFiles(): void
    {
        $company = Companies::factory()->create();
        $person = $this->person($company, 'PA1');
        $first = $this->makeFilesystemRow($company->getId(), 'PA1.pdf');
        $second = $this->makeFilesystemRow($company->getId(), 'pa1.pdf');

        $result = new CampaignAttachmentMatcherService()->match(new Collection([$person]), new Collection([$first, $second]), self::FIELD);

        $this->assertSame([$first->url, $second->url], $result['matched'][$person->getId()]);
    }

    public function testPeopleWithoutAFileAndFilesWithoutAPersonAreReported(): void
    {
        $company = Companies::factory()->create();
        $matched = $this->person($company, 'PA1');
        $unmatched = $this->person($company, 'PA2');
        $file = $this->makeFilesystemRow($company->getId(), 'PA1.pdf');
        $orphan = $this->makeFilesystemRow($company->getId(), 'PA9.pdf');

        $result = new CampaignAttachmentMatcherService()->match(new Collection([$matched, $unmatched]), new Collection([$file, $orphan]), self::FIELD);

        $this->assertArrayHasKey($matched->getId(), $result['matched']);
        $this->assertSame([$unmatched->getId()], $result['unmatched_people']);
        $this->assertSame(['PA9.pdf'], $result['unmatched_files']);
    }

    public function testAnEmptyFieldValueNeverMatches(): void
    {
        $company = Companies::factory()->create();
        $person = $this->person($company, null);
        $file = $this->makeFilesystemRow($company->getId(), '.pdf');

        $result = new CampaignAttachmentMatcherService()->match(new Collection([$person]), new Collection([$file]), self::FIELD);

        $this->assertSame([], $result['matched']);
        $this->assertSame([$person->getId()], $result['unmatched_people']);
        $this->assertSame(['.pdf'], $result['unmatched_files']);
    }
}
