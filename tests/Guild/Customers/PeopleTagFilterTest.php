<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\Models\TagEntity;
use Tests\TestCase;

/**
 * `hasTags` runs on the crm connection with a subquery into the social database. Neither stays in a
 * transaction here: an open crm transaction fixes its snapshot at its first read, across every database
 * on the server, so a tag committed after that read is invisible and the filter looks broken. Whether a
 * read lands first depends on the queue driver, so it passes locally and fails in CI. The test cleans up.
 */
final class PeopleTagFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    public function test_the_people_query_filters_by_tag(): void
    {
        $user = static::$cachedUser;
        $tagName = 'Nivel: ' . Str::random(12);

        $tagged = $this->people();
        $untagged = $this->people();
        $tagged->addTags(
            [$tagName],
            app(Apps::class),
            $user,
            $user->getCurrentCompany(),
        );

        try {
            $response = $this->graphQL('query($tag: Mixed) {
                peoples(hasTags: {column: NAME, operator: EQ, value: $tag}, first: 100) {
                    data { id }
                }
            }', ['tag' => $tagName]);

            $response->assertSuccessful()->assertJsonMissingPath('errors');
            $ids = $response->json('data.peoples.data.*.id');

            $this->assertContains((string) $tagged->getId(), $ids);
            $this->assertNotContains((string) $untagged->getId(), $ids);
        } finally {
            $tagId = Tag::query()->where('name', $tagName)->value('id');
            TagEntity::query()->where('tags_id', $tagId)->delete();
            Tag::query()->where('id', $tagId)->delete();
            DB::connection('crm')->table('peoples')->whereIn('id', [$tagged->getId(), $untagged->getId()])->delete();
        }
    }

    private function people(): People
    {
        $user = static::$cachedUser;

        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
    }
}
