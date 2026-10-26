<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Support;

use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

/**
 * A user's opt-in email signature: either the plain-text block rendered by the standard partial, or an
 * approved HTML design (see SignatureHtml) sent as it was approved. `text` is kept either way — it is the
 * text/plain part of the email.
 */
final readonly class UserEmailSignature
{
    public const string SETTING = 'email_signature';

    /** On the outer element of every rendered signature; how a body is checked for an already-placed one. */
    public const string MARKER = 'data-kanvas-user-signature';

    public function __construct(
        public string $text,
        public ?string $photoUrl = null,
        public ?string $html = null,
    ) {
    }

    public static function companySettingKey(CompanyInterface $company): string
    {
        return self::SETTING . '_company_' . $company->getId();
    }

    /**
     * user_config is keyed by user alone, so a per-company signature is its own key. A user with none
     * for this company falls back to their global one — the only kind that existed before.
     */
    public static function fromUser(?Users $user, ?CompanyInterface $company = null): ?self
    {
        if ($user === null) {
            return null;
        }

        $setting = self::enabledSetting($company !== null ? $user->get(self::companySettingKey($company)) : null)
            ?? self::enabledSetting($user->get(self::SETTING));

        if ($setting === null) {
            return null;
        }

        $html = is_string($setting['html'] ?? null) && trim($setting['html']) !== '' ? $setting['html'] : null;

        if ($html !== null) {
            return new self(trim($setting['text']), html: $html);
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

    /**
     * The owner's signature for a send, only when the sending agent was granted signing (see
     * Agent::signsEmailsAsOwner()). Any other send — no agent, an agent without the grant, a
     * customer-facing persona — goes out without it.
     */
    public static function forSender(?Users $owner, CompanyInterface $company, ?Agent $fromAgent): ?self
    {
        return ($fromAgent?->signsEmailsAsOwner() ?? false) ? self::fromUser($owner, $company) : null;
    }

    /**
     * The stored `{append, text, html}` as an array whether the row holds it as an array or a JSON string,
     * enabled or not.
     */
    public static function decodeSetting(mixed $setting): ?array
    {
        if (is_string($setting)) {
            $setting = json_decode($setting, true);
        }

        return is_array($setting) ? $setting : null;
    }

    /**
     * Leaves a body that already carries the signature alone (a template that placed
     * `{{ user_signature|raw }}` itself), otherwise puts it inside `</body>` when the template is a full
     * document so it is not appended after the closing tag.
     */
    public static function ensureIn(string $body, ?string $signatureHtml): string
    {
        if ($signatureHtml === null || $signatureHtml === '' || str_contains($body, self::MARKER)) {
            return $body;
        }

        $closingBody = strripos($body, '</body>');

        return $closingBody === false
            ? $body . $signatureHtml
            : substr_replace($body, $signatureHtml, $closingBody, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSetting(bool $enabled): array
    {
        return array_filter(
            ['append' => $enabled, 'text' => $this->text, 'html' => $this->html],
            static fn (mixed $value): bool => $value !== null
        );
    }

    public function toHtml(): string
    {
        if ($this->html !== null) {
            return '<div ' . self::MARKER . '="1" style="margin: 20px 0 0;">' . SignatureHtml::clean($this->html) . '</div>';
        }

        $lines = preg_split('/\R/u', $this->text) ?: [$this->text];

        // Some company templates call nl2br(content): avoid inserting <br> between table tags.
        return str_replace(["\r", "\n"], '', view('emails.partials.user-signature', [
            'name' => array_shift($lines),
            'lines' => $lines,
            'photoUrl' => $this->photoUrl,
        ])->render());
    }

    private static function enabledSetting(mixed $setting): ?array
    {
        $setting = self::decodeSetting($setting);

        if ($setting === null
            || ! filter_var($setting['append'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ! is_string($setting['text'] ?? null)
            || trim($setting['text']) === '') {
            return null;
        }

        return $setting;
    }
}
