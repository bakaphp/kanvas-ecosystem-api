<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Validations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Social\Messages\Models\Message as ModelsMessage;

class ValidParentMessage implements ValidationRule
{
    protected $appId;

    public function __construct(int $appId)
    {
        $this->appId = $appId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        // A deleted parent must fail here: the tree builds a child's path from its live parent, so
        // letting one through fatals on insert instead of returning this message.
        $exists = ModelsMessage::query()
            ->where('id', $value)
            ->where('apps_id', $this->appId)
            ->exists();

        if (! $exists) {
            $fail('The parent message is invalid');
        }
    }
}
