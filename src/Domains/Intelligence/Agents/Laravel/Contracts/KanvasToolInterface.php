<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Contracts;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Laravel\Ai\Contracts\Tool;

interface KanvasToolInterface extends Tool
{
    /**
     * The function-name shape Gemini accepts; it rejects the whole request over one name outside it.
     */
    public const string FUNCTION_NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_.-]{0,63}$/';

    public function withContext(Apps $app, Companies $company): static;
}
