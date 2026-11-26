<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Services\EngagementUploadTokenService;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class EngagementUploadTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'action_engine', 'ecosystem', 'social'];

    private Apps $currentApp;

    private int $companyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $user = auth()->user();
        $company = new CreateCompaniesAction(
            Company::viaRequest(['name' => fake()->unique()->company], $user)
        )->execute();
        $this->companyId = $company->getId();
        $this->userId = $user->getId();

        Storage::fake('s3');
        new Setup($this->currentApp, $user, $company)->run();
    }

    public function testUploadsWithAValidTokenAndUsesTheEngagementCompany(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 2);

        $response = $this->upload($engagement, $token, $this->pdfUpload());

        $response->assertCreated()->assertJsonPath('uploaded', 1);
        $file = Filesystem::query()->where('name', 'statement.pdf')->firstOrFail();
        $this->assertNotSame($this->companyId, auth()->user()->getCurrentCompany()->getId());
        $this->assertSame($this->companyId, (int) $file->companies_id);
        $this->assertSame($this->currentApp->getId(), (int) $file->apps_id);
        $this->assertTrue($engagement->fresh()->files()->where('filesystem.id', $file->getId())->exists());
    }

    public function testRejectsAnExpiredToken(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 1, Carbon::now()->subSecond());

        $this->upload($engagement, $token, $this->pdfUpload())->assertUnauthorized();
    }

    public function testRejectsATokenForAnotherEngagement(): void
    {
        $tokenEngagement = $this->makeEngagement();
        $routeEngagement = $this->makeEngagement();
        $token = $this->issueToken($tokenEngagement, 1);

        $this->upload($routeEngagement, $token, $this->pdfUpload())->assertForbidden();
    }

    public function testRejectsUploadsAfterTheTokenLimitIsReached(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 1);

        $this->upload($engagement, $token, $this->pdfUpload())->assertCreated();
        $this->upload($engagement, $token, $this->pdfUpload('second.pdf'))->assertStatus(409);
        $this->assertSame(1, (int) $engagement->fresh()->upload_count);
    }

    public function testRejectsUploadsAfterTheEngagementIsSubmitted(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 1);
        $submittedStage = PipelineStage::query()
            ->where('pipelines_id', $engagement->stage->pipelines_id)
            ->where('slug', 'submitted')
            ->firstOrFail();
        $engagement->pipelines_stages_id = $submittedStage->getId();
        $engagement->saveOrFail();

        $this->upload($engagement, $token, $this->pdfUpload())->assertStatus(409);
        $this->assertSame(0, (int) $engagement->fresh()->upload_count);
    }

    public function testRejectsUnsupportedFileTypes(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 1);
        $unsupported = UploadedFile::fake()->createWithContent('notes.txt', 'not a supported upload');

        $this->upload($engagement, $token, $unsupported)->assertUnprocessable();
        $this->assertSame(0, (int) $engagement->fresh()->upload_count);
    }

    public function testRejectsFilesLargerThanOneHundredMegabytes(): void
    {
        $engagement = $this->makeEngagement();
        $token = $this->issueToken($engagement, 1);
        $oversized = UploadedFile::fake()->create('large.pdf', 102401, 'application/pdf');

        $this->upload($engagement, $token, $oversized)->assertStatus(413);
        $this->assertSame(0, (int) $engagement->fresh()->upload_count);
    }

    private function makeEngagement(): Engagement
    {
        $stage = PipelineStage::query()
            ->where('slug', 'sent')
            ->whereHas('pipeline', fn ($query) => $query->where('companies_id', $this->companyId))
            ->firstOrFail();
        $message = Message::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->companyId)
            ->create([
                'users_id' => $this->userId,
                'message' => ['verb' => 'get-docs', 'status' => 'sent'],
            ]);
        $uuid = (string) Str::uuid();

        $id = DB::connection('action_engine')->table('engagements')->insertGetId([
            'uuid' => $uuid,
            'companies_id' => $this->companyId,
            'apps_id' => $this->currentApp->getId(),
            'users_id' => $this->userId,
            'companies_actions_id' => 0,
            'message_id' => $message->getId(),
            'leads_id' => 0,
            'pipelines_stages_id' => $stage->getId(),
            'entity_uuid' => (string) Str::uuid(),
            'slug' => 'get-docs',
            'created_at' => now(),
        ]);

        return Engagement::query()->findOrFail($id);
    }

    private function issueToken(Engagement $engagement, int $maxFiles, ?Carbon $expiresAt = null): string
    {
        $expiresAt ??= Carbon::now()->addHours(72);
        $engagement->upload_count = 0;
        $engagement->upload_limit = $maxFiles;
        $engagement->upload_expires_at = $expiresAt;
        $engagement->saveOrFail();

        return new EngagementUploadTokenService()->issue($engagement, $maxFiles, $expiresAt);
    }

    private function upload(Engagement $engagement, string $token, UploadedFile $file): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/v1/engagements/' . $engagement->uuid . '/uploads', ['file' => $file]);
    }

    private function pdfUpload(string $name = 'statement.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nUpload test\n%%EOF");
    }
}
