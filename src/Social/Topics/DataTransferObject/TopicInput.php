<?php


declare(strict_types=1);

namespace Kanvas\Social\Topics\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class TopicInput
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public string $name,
        public int $is_feature,
        public bool $status,
        public int $weight = 0
    ) {
    }
}
