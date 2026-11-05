<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Deals\Models\Deal;
use Tests\TestCase;
use Tests\Traits\MakesGuildEntities;

/**
 * `attachFileToX(file: Upload!)` and `attachFilesToX(file: [Upload!]!)` share one resolver, so the
 * trait must take a single upload or a list under the same key. Tested on the trait directly because
 * multipart GraphQL requests hang under paratest and would leave this unguarded in CI.
 */
final class HasMutationUploadFilesTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGuildEntities;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testASingleUploadUnderFileIsAttached(): void
    {
        $deal = $this->makeDeal();

        $this->uploader()->uploadFileToEntity(
            model: $deal,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['file' => UploadedFile::fake()->create('application.pdf', 10)]
        );

        $this->assertSame(['application.pdf'], $this->attachedFileNames($deal));
    }

    public function testAListOfUploadsUnderFileIsAttachedOneByOne(): void
    {
        $deal = $this->makeDeal();

        $this->uploader()->uploadFileToEntity(
            model: $deal,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['file' => [
                UploadedFile::fake()->create('application.pdf', 10),
                UploadedFile::fake()->create('bank-statement.pdf', 10),
            ]]
        );

        $this->assertSame(['application.pdf', 'bank-statement.pdf'], $this->attachedFileNames($deal));
    }

    public function testAListUnderFilesStillWorks(): void
    {
        $deal = $this->makeDeal();

        $this->uploader()->uploadFileToEntity(
            model: $deal,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['files' => [UploadedFile::fake()->create('tax-return.pdf', 10)]]
        );

        $this->assertSame(['tax-return.pdf'], $this->attachedFileNames($deal));
    }

    public function testALeadTakesTheSameListUnderFile(): void
    {
        $lead = $this->makeLead();

        $this->uploader()->uploadFileToEntity(
            model: $lead,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['file' => [
                UploadedFile::fake()->create('id-front.pdf', 10),
                UploadedFile::fake()->create('id-back.pdf', 10),
            ]]
        );

        $this->assertSame(['id-back.pdf', 'id-front.pdf'], $this->attachedFileNames($lead));
    }

    public function testPeopleTakeBothASingleFileAndAListUnderFiles(): void
    {
        $people = $this->makePeople();

        $this->uploader()->uploadFileToEntity(
            model: $people,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['file' => UploadedFile::fake()->create('selfie.jpg', 10)]
        );
        $this->uploader()->uploadFileToEntity(
            model: $people,
            app: app(Apps::class),
            user: auth()->user(),
            request: ['files' => [UploadedFile::fake()->create('passport.pdf', 10)]]
        );

        $this->assertSame(['passport.pdf', 'selfie.jpg'], $this->attachedFileNames($people));
    }

    private function uploader(): object
    {
        return new class () {
            use HasMutationUploadFiles;
        };
    }

    private function makeDeal(): Deal
    {
        return Deal::factory()
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
