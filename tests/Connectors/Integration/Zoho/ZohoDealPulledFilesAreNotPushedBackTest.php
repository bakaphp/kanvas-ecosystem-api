<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\Actions\Concerns\TracksZohoSyncedFiles;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Support\RecordingFilePusher;
use Tests\Connectors\Integration\Zoho\Support\StubbedPushDealToZohoAction;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * The pull remembers a Zoho attachment it brought down; the next push must not send that same
 * file back up. Both sides go through TracksZohoSyncedFiles, so this pins the contract between them.
 */
final class ZohoDealPulledFilesAreNotPushedBackTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use TracksZohoSyncedFiles;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testAFilePulledFromZohoIsSkippedByTheNextPush(): void
    {
        $deal = $this->makeDeal($this->makeZohoCompany());
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-500');
        $deal->addFileFromUrl('https://example.com/zoho/pulled.pdf', 'pulled.pdf');
        $deal->addFileFromUrl('https://example.com/zoho/local.pdf', 'local.pdf');

        /** @var Filesystem $pulled */
        $pulled = $deal->files()->where('filesystem.name', 'pulled.pdf')->firstOrFail();
        /** @var Filesystem $local */
        $local = $deal->files()->where('filesystem.name', 'local.pdf')->firstOrFail();

        $this->rememberSyncedFiles($deal, CustomFieldEnum::ZOHO_DEAL_SYNC_FILES, [$pulled->getId()]);

        $module = $this->createStub(ZohoRecordsModule::class);
        $module->method('get')->willReturn(new Record(['id' => 'zd-500'], $module));
        $module->method('update')->willReturn(new Record(['id' => 'zd-500'], $module));
        $pusher = new RecordingFilePusher($module, 'zd-500');

        $result = new StubbedPushDealToZohoAction($deal, $module, $pusher)->execute();

        $this->assertContains($pulled->getId(), $pusher->skipIdsSeen);
        $this->assertSame([$local->getId()], $result['files']['uploaded']);
    }
}
