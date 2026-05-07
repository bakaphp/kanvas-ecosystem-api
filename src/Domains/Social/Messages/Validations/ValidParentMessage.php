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

        $exists = ModelsMessage::withTrashed()
            ->where('id', $value)
            ->where('apps_id', $this->appId)
            ->exists();

        if (! $exists) {
            $fail('The parent message is invalid');
        }
    }
}
