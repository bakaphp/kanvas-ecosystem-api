<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Exporters;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Intelligence\Agents\Neuron\Exporters\PeopleDuplicatesRecordExporter;
use Tests\TestCase;

final class PeopleDuplicatesRecordExporterTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // The People observer queues its own duplicate check; groups here are written by hand.
        Queue::fake();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    public function test_one_row_per_record_with_the_kept_record_first(): void
    {
        $lastname = 'Cordero' . uniqid();
        $keep = $this->makePerson('Brenda', $lastname);
        $keep->addPhone('13055550602');
        $sameP = $this->makePerson('Brenda', $lastname);
        $sameP->addPhone('+1 305 555 0602');
        $empty = $this->makePerson('Brenda', $lastname);
        $group = $this->queueGroup([$keep, $sameP, $empty]);

        $rows = $this->rowsOf($group);

        $this->assertCount(3, $rows);
        $this->assertSame(['keep', 'merge', 'merge'], array_column($rows, 4));
        $this->assertSame((int) $keep->getId(), $rows[0][5]);
        $this->assertSame('safe_to_merge', $rows[0][3]);
        $this->assertSame('', $rows[1][11], 'Same phone in another format is not a difference.');
        $this->assertSame('phones', $rows[2][11]);
    }

    public function test_a_shared_name_with_different_contacts_needs_review(): void
    {
        $lastname = 'Lead' . uniqid();
        $keep = $this->makePerson('Arfenis', $lastname);
        $keep->addEmail('arfenis-' . uniqid() . '@x.test');
        $other = $this->makePerson('Arfenis', $lastname);
        $other->addEmail('someone-else-' . uniqid() . '@x.test');
        $group = $this->queueGroup([$keep, $other]);

        $rows = $this->rowsOf($group);

        $this->assertSame('review_first', $rows[0][3]);
        $this->assertSame('emails', $rows[1][11]);
    }

    public function test_an_email_match_names_the_shared_email(): void
    {
        $email = 'shared-' . uniqid() . '@x.test';
        $keep = $this->makePerson('Hugo', 'Fonseca' . uniqid());
        $keep->addEmail($email);
        $other = $this->makePerson('Hugh', 'Fonsec' . uniqid());
        $other->addEmail(strtoupper($email));
        $group = $this->queueGroup([$keep, $other], 'email_match');

        $rows = $this->rowsOf($group, ['reason' => 'email_match']);

        $this->assertSame($email, $rows[0][2]);
        $this->assertSame('safe_to_merge', $rows[0][3]);
    }

    public function test_safe_groups_are_listed_before_groups_to_review(): void
    {
        $lastname = 'Order' . uniqid();
        $reviewA = $this->makePerson('Rev', $lastname);
        $reviewA->addEmail('a-' . uniqid() . '@x.test');
        $reviewB = $this->makePerson('Rev', $lastname);
        $reviewB->addEmail('b-' . uniqid() . '@x.test');
        $review = $this->queueGroup([$reviewA, $reviewB]);

        $safeLastname = 'Safe' . uniqid();
        $safe = $this->queueGroup([$this->makePerson('Ok', $safeLastname), $this->makePerson('Ok', $safeLastname)]);

        $groupIds = array_values(array_unique(array_column($this->rows([]), 0)));

        $this->assertLessThan(
            array_search($review->getId(), $groupIds, true),
            array_search($safe->getId(), $groupIds, true)
        );
    }

    public function test_an_unknown_reason_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->rows(['reason' => 'same_vibe']);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rowsOf(DuplicateReviewGroup $group, array $filters = []): array
    {
        return array_values(array_filter(
            $this->rows($filters),
            fn (array $row): bool => $row[0] === $group->getId()
        ));
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rows(array $filters): array
    {
        return new PeopleDuplicatesRecordExporter()->rows($this->currentApp, $this->currentCompany, $filters);
    }

    /**
     * @param list<People> $members
     */
    private function queueGroup(array $members, string $reason = 'exact_name'): DuplicateReviewGroup
    {
        $ids = array_map(fn (People $people): int => (int) $people->getId(), $members);
        sort($ids);

        return DuplicateReviewGroup::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'entity_type' => People::class,
            'canonical_id' => $ids[0],
            'member_ids' => $ids,
            'signature' => DuplicateReviewGroup::signatureFor($ids),
            'reason' => $reason,
            'status' => DuplicateReviewStatusEnum::PENDING->value,
        ]);
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }
}
