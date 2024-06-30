<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Validations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Social\MessagesComments\Models\MessageComment;

class ValidParentComment implements ValidationRule
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

        $parentMessage = MessageComment::where('id', $value)
            ->where('apps_id', $this->appId);

        if (! $parentMessage->exists()) {
            $fail('The parent comment is invalid');
        }
    }
}
