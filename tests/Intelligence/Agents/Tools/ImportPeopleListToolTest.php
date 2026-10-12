<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ImportPeopleListTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ImportPeopleListToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private function tool(Companies $company, ?Users $user = null): ImportPeopleListTool
    {
        return new ImportPeopleListTool()->withContext(app(Apps::class), $company, $user ?? auth()->user());
    }

    public function testImportsDistinctRowsAndDedupsARepeatedEmail(): void
    {
        $company = Companies::factory()->create();
        $email = 'tester.' . uniqid() . '@example.test';

        $result = $this->tool($company)->__invoke([
            ['firstname' => 'Alpha', 'lastname' => 'Tester', 'email' => $email],
            ['firstname' => 'Alpha', 'lastname' => 'UpdatedTester', 'email' => $email],
            ['firstname' => 'Bravo', 'lastname' => 'Tester', 'email' => 'bravo.' . uniqid() . '@example.test'],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['matched']);
        // One id per processed row (not deduped), so the matched row's id repeats.
        $this->assertCount(3, $result['people_ids']);
        $this->assertCount(2, array_unique($result['people_ids']));
        $this->assertSame($result['people_ids'][0], $result['people_ids'][1]);
    }

    public function testRowsWithoutAFirstnameAreSkippedNotFatal(): void
    {
        $company = Companies::factory()->create();

        $result = $this->tool($company)->__invoke([
            ['lastname' => 'NoFirstname'],
            ['firstname' => 'Charlie', 'email' => 'charlie.' . uniqid() . '@example.test'],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped_rows']);
    }

    public function testEmptyRowsIsRejected(): void
    {
        $result = $this->tool(Companies::factory()->create())->__invoke([]);

        $this->assertSame('error', $result['status']);
    }

    public function testNonAdminIsRejected(): void
    {
        // A bare factory user has no roles → isAdmin() is false.
        $nonAdmin = Users::factory()->create();
        $company = Companies::factory()->create();

        $result = new ImportPeopleListTool()->withContext(app(Apps::class), $company, $nonAdmin)->__invoke([
            ['firstname' => 'Denied', 'email' => 'denied.' . uniqid() . '@example.test'],
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('administrator', $result['message']);
    }
}
