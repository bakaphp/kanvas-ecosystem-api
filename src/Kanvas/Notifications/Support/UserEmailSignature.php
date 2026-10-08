<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

use Kanvas\Users\Models\Users;

/** A user's opt-in plain-text signature, rendered as an email-safe identity block. */
final readonly class UserEmailSignature
{
    public function __construct(
        public string $text,
        public ?string $photoUrl = null,
    ) {
    }

    public static function fromUser(?Users $user): ?self
    {
        if ($user === null) {
            return null;
        }

        $setting = $user->get('email_signature');
        if (is_string($setting)) {
            $setting = json_decode($setting, true);
        }

        if (! is_array($setting)
            || ! filter_var($setting['append'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ! is_string($setting['text'] ?? null)
            || trim($setting['text']) === '') {
            return null;
        }

        // getPhoto() can return an app-wide avatar: use only a photo attached to THIS user.
        $photoUrl = $user->getFileByName('photo')?->filesystem?->url;
        $photoUrl = is_string($photoUrl)
            && filter_var($photoUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($photoUrl, PHP_URL_SCHEME)), ['https', 'http'], true)
                ? $photoUrl
                : null;

        return new self(trim($setting['text']), $photoUrl);
    }

    public function toHtml(): string
    {
        $lines = preg_split('/\R/u', $this->text) ?: [$this->text];

        // Some company templates call nl2br(content): avoid inserting <br> between table tags.
        return str_replace(["\r", "\n"], '', view('emails.partials.user-signature', [
            'name' => array_shift($lines),
            'lines' => $lines,
            'photoUrl' => $this->photoUrl,
        ])->render());
    }
}
