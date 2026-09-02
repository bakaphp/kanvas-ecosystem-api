<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\GetFileLinkTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The tenant boundary is the whole point: this tool is the one place an agent can turn an id into a
 * URL, so an id from another company must come back as unavailable rather than as a working link.
 */
class GetFileLinkToolTest extends TestCase
{
    use DatabaseTransactions;

    public function testItReturnsTheStoredUrlForAFileTheCompanyOwns(): void
    {
        $file = $this->makeFile($this->companyId(), 'pvolve-final-delivery-brief.md');

        $result = $this->tool()->__invoke([$file->getId()]);

        $this->assertSame(1, $result['count']);
        $this->assertSame($file->url, $result['links'][0]['url']);
        $this->assertSame('pvolve-final-delivery-brief.md', $result['links'][0]['file_name']);
        $this->assertSame([], $result['unavailable']);
    }

    public function testAFileFromAnotherCompanyIsNeverLinked(): void
    {
        $foreign = $this->makeFile(Companies::factory()->create()->getId(), 'someone-elses-contract.pdf');

        $result = $this->tool()->__invoke([$foreign->getId()]);

        $this->assertSame([], $result['links']);
        $this->assertSame($foreign->getId(), $result['unavailable'][0]['filesystem_id']);
        $this->assertStringNotContainsString($foreign->url, json_encode($result));
        $this->assertStringContainsString('not available to link', $result['note']);
    }

    public function testADeletedFileIsNotLinked(): void
    {
        $file = $this->makeFile($this->companyId(), 'retired.md');
        $file->is_deleted = 1;
        $file->saveOrFail();

        $result = $this->tool()->__invoke([$file->getId()]);

        $this->assertSame([], $result['links']);
        $this->assertCount(1, $result['unavailable']);
    }

    /** A mixed batch has to report both halves, or the model links three of four and says nothing. */
    public function testAMixedBatchReportsBothHalves(): void
    {
        $mine = $this->makeFile($this->companyId(), 'usecases.md');
        $foreign = $this->makeFile(Companies::factory()->create()->getId(), 'theirs.md');

        $result = $this->tool()->__invoke([$mine->getId(), $foreign->getId()]);

        $this->assertSame(1, $result['count']);
        $this->assertSame($mine->getId(), $result['links'][0]['filesystem_id']);
        $this->assertCount(1, $result['unavailable']);
        $this->assertStringContainsString('could not be linked', $result['note']);
    }

    /** Asked for one document, models pass the bare id — a TypeError there is invisible to them. */
    public function testABareIdIsAcceptedAsWellAsAList(): void
    {
        $file = $this->makeFile($this->companyId(), 'pitchdeck.md');

        foreach ([$file->getId(), (string) $file->getId()] as $input) {
            $result = $this->tool()->__invoke($input);

            $this->assertSame(1, $result['count']);
            $this->assertSame($file->url, $result['links'][0]['url']);
        }
    }

    public function testAFileWithNoStoredUrlIsReportedRatherThanLinkedToNothing(): void
    {
        $file = $this->makeFile($this->companyId(), 'no-url.md');
        $file->url = '';
        $file->saveOrFail();

        $result = $this->tool()->__invoke([$file->getId()]);

        $this->assertSame([], $result['links']);
        $this->assertStringContainsString('no stored URL', $result['unavailable'][0]['reason']);
    }

    /** Silently dropping the tail hands over a list with documents quietly missing from it. */
    public function testIdsBeyondTheCapAreReportedRatherThanDropped(): void
    {
        $file = $this->makeFile($this->companyId(), 'brief.md');
        $ids = array_merge([$file->getId()], range(900000, 900030));

        $result = $this->tool()->__invoke($ids);

        $this->assertSame(1, $result['count']);
        $this->assertNotEmpty(array_filter(
            $result['unavailable'],
            fn (array $entry): bool => str_contains($entry['reason'], 'only 25 ids are linked per call')
        ));
    }

    public function testCallingWithNoIdsSaysWhatIsMissing(): void
    {
        $result = $this->tool()->__invoke();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('filesystem_ids', $result['message']);
    }

    public function testWithoutTenantContextItRefusesInsteadOfFatalling(): void
    {
        $result = new GetFileLinkTool()([1]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('No app in scope', $result['message']);
    }

    private function makeFile(int $companyId, string $name): Filesystem
    {
        $file = new Filesystem();
        $file->apps_id = app(Apps::class)->getId();
        $file->companies_id = $companyId;
        $file->users_id = auth()->user()->getId();
        $file->name = $name;
        $file->path = 'test/' . uniqid() . '/' . $name;
        $file->url = 'https://cdn.example.test/' . uniqid() . '/' . $name;
        $file->size = '10';
        $file->file_type = pathinfo($name, PATHINFO_EXTENSION);
        $file->is_deleted = 0;
        $file->saveOrFail();

        return $file;
    }

    private function companyId(): int
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user->getCurrentCompany()->getId();
    }

    private function tool(): GetFileLinkTool
    {
        /** @var Users $user */
        $user = auth()->user();

        return new GetFileLinkTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
