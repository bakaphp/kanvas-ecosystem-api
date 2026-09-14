<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ReadFileTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The refusal paths, which are the ones that matter: what a tool says when it cannot read a file is
 * what the model repeats instead of inventing contents.
 *
 * The happy path fetches over the network (SafeUrlFetcher is the SSRF chokepoint by design), so
 * per-format parsing is covered against local bytes in FileTextExtractorTest instead.
 */
class ReadFileToolTest extends TestCase
{
    use DatabaseTransactions;

    public function testAFileFromAnotherCompanyIsNotReadable(): void
    {
        $foreign = $this->makeFile(Companies::factory()->create()->getId(), 'employees.csv');

        $result = $this->withTenant(new ReadFileTool())->__invoke(filesystem_id: $foreign->getId());

        $this->assertSame('', $result['content']);
        $this->assertStringContainsString('was not found in this company', $result['note']);
    }

    /**
     * A URL is the form the PM actually hands over today, so it has to work — but only back onto a
     * row we own. Fetching an arbitrary LLM-supplied URL would be a read-anything primitive.
     */
    public function testAUrlOutsideTheCompanyIsRefusedRatherThanFetched(): void
    {
        $foreign = $this->makeFile(Companies::factory()->create()->getId(), 'employees.csv');

        $result = $this->withTenant(new ReadFileTool())->__invoke(file_url: $foreign->url);

        $this->assertSame('', $result['content']);
        $this->assertStringContainsString('belongs to this company', $result['note']);
    }

    public function testAnUnsupportedTypeNamesWhatIsSupportedInsteadOfGuessing(): void
    {
        $image = $this->makeFile($this->companyId(), 'logo.png');

        $result = $this->withTenant(new ReadFileTool())->__invoke(filesystem_id: $image->getId());

        $this->assertSame('', $result['content']);
        $this->assertStringContainsString('not a readable document type', $result['note']);
        $this->assertStringContainsString('csv', $result['note']);
        $this->assertStringContainsString('xlsx', $result['note']);
    }

    public function testCallingWithNeitherIdNorUrlSaysWhatIsMissing(): void
    {
        $result = $this->withTenant(new ReadFileTool())->__invoke();

        $this->assertStringContainsString('filesystem_id or file_url', $result['note']);
    }

    /** Every refusal must still be a terminal answer, or the model keeps paging toward nothing. */
    public function testEveryRefusalIsTerminal(): void
    {
        $tool = $this->withTenant(new ReadFileTool());
        $foreign = $this->makeFile(Companies::factory()->create()->getId(), 'employees.csv');

        foreach ([$tool->__invoke(), $tool->__invoke(filesystem_id: $foreign->getId())] as $result) {
            $this->assertFalse($result['has_more']);
            $this->assertNull($result['next_offset']);
            $this->assertSame('', $result['content']);
        }
    }

    /** Paging is keyed per offset so a long file can be read, while one page cannot be re-read forever. */
    public function testRunKeyIsPerFileAndOffset(): void
    {
        $tool = new ReadFileTool();

        $tool->setInputs(['filesystem_id' => 7, 'offset' => 0]);
        $first = $tool->getRunKey();

        $tool->setInputs(['filesystem_id' => 7, 'offset' => 40000]);
        $second = $tool->getRunKey();

        $tool->setInputs(['filesystem_id' => 8, 'offset' => 0]);
        $third = $tool->getRunKey();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first, $third);
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

    private function withTenant(ReadFileTool $tool): ReadFileTool
    {
        /** @var Users $user */
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
