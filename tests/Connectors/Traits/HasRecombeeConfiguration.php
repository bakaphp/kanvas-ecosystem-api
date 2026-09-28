<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

trait HasRecombeeConfiguration
{
    /**
     * Skips instead of writing an unset env var through: `HashTableTrait::set()` persists to Redis
     * and the `ecosystem` connection, both shared by every paratest process and rolled back by
     * nothing, so a `false` here hands every sibling Recombee test an unusable credential.
     */
    protected function configureRecombeeOrSkip(AppInterface $app): void
    {
        if (empty(getenv('TEST_RECOMBEE_DATABASE')) || empty(getenv('TEST_RECOMBEE_API_KEY')) || empty(getenv('TEST_RECOMBEE_REGION'))) {
            $this->markTestSkipped('Recombee test credentials not set.');
        }

        $app->set(ConfigurationEnum::RECOMBEE_DATABASE->value, getenv('TEST_RECOMBEE_DATABASE'));
        $app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, getenv('TEST_RECOMBEE_API_KEY'));
        $app->set(ConfigurationEnum::RECOMBEE_REGION->value, getenv('TEST_RECOMBEE_REGION'));
    }

    protected function skipWithoutRecombeeEcomCredentials(): void
    {
        if (empty(getenv('TEST_RECOMBEE_DATABASE_ECOM')) || empty(getenv('TEST_RECOMBEE_DATABASE_ECOM_KEY')) || empty(getenv('TEST_RECOMBEE_DATABASE_ECOM_REGION'))) {
            $this->markTestSkipped('Recombee ecom test credentials not set.');
        }
    }
}
