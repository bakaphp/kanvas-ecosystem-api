<?php


declare(strict_types=1);

namespace Kanvas\Social\Topics\DataTransferObject;

class TopicInput
{
    public function __construct(
        public int $apps_id,
        public int $companies_id,
        public int $users_id,
        public string $name,
        public string $slug,
        public int $weight,
        public int $is_feature,
        public bool $status
    ) {
    }
}
