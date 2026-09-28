<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\DataTransferObject;

use Illuminate\Support\Str;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;

/**
 * One inbound WhatsApp message resolved into "where the conversation lives" (conversationJid) and
 * "who spoke" (sender*). Those two collapse into the same value in a DM and diverge in a group,
 * which is what the old single-`$chatJid` expression could not express.
 */
final readonly class InboundMessage
{
    public function __construct(
        public string $conversationJid,
        public ConversationTypeEnum $conversationType,
        public string $messageId,
        public bool $isFromMe,
        public ?string $senderJid = null,
        public ?string $senderLid = null,
        public ?string $senderPhone = null,
        public ?string $pushName = null,
        public array $mentionedJids = [],
        public ?string $quotedMessageId = null,
        public ?string $quotedParticipant = null,
        public ?string $albumId = null,
    ) {
    }

    /**
     * Returns null when the payload carries no routable conversation — an expected condition, not
     * a fault. Callers skip silently.
     */
    public static function fromWebhookMessage(array $messageData): ?self
    {
        $key = (array) ($messageData['key'] ?? []);
        $remoteJid = self::asString($key['remoteJid'] ?? $messageData['remoteJid'] ?? null);
        $conversationType = ConversationTypeEnum::fromJid($remoteJid);

        $conversationJid = $conversationType === ConversationTypeEnum::DIRECT
            ? (self::asString($key['remoteJidAlt'] ?? null) ?? self::asString($key['senderPn'] ?? null) ?? $remoteJid)
            : $remoteJid;

        if ($conversationJid === null) {
            return null;
        }

        $content = MessageTypeEnum::unwrap((array) ($messageData['message'] ?? []));
        $contextInfo = (array) ($content[MessageTypeEnum::contentKey($content) ?? '']['contextInfo'] ?? []);

        [$senderJid, $senderLid, $senderPhone] = $conversationType === ConversationTypeEnum::GROUP
            ? self::resolveGroupSender($key)
            : self::resolveDirectSender($key, $conversationJid);

        return new self(
            conversationJid: $conversationJid,
            conversationType: $conversationType,
            messageId: self::asString($key['id'] ?? null) ?? Str::uuid()->toString(),
            isFromMe: (bool) ($key['fromMe'] ?? false),
            senderJid: $senderJid,
            senderLid: $senderLid,
            senderPhone: $senderPhone,
            pushName: self::asString($messageData['pushName'] ?? null),
            mentionedJids: array_values(array_filter((array) ($contextInfo['mentionedJid'] ?? []), 'is_string')),
            quotedMessageId: self::asString($contextInfo['stanzaId'] ?? null),
            // The quoted author's JID rides on the quote itself, so "did they quote us?" is
            // answerable even when we never filed the quoted message.
            quotedParticipant: self::toBareId(self::asString($contextInfo['participant'] ?? null)),
            albumId: self::resolveAlbumId((array) ($messageData['message'] ?? [])),
        );
    }

    public function isGroup(): bool
    {
        return $this->conversationType === ConversationTypeEnum::GROUP;
    }

    public function isDirect(): bool
    {
        return $this->conversationType === ConversationTypeEnum::DIRECT;
    }

    /**
     * Stable per-speaker key: phone when WhatsApp disclosed it, lid otherwise.
     */
    public function senderIdentity(): ?string
    {
        return $this->senderPhone ?? $this->senderLid ?? $this->senderJid;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function resolveGroupSender(array $key): array
    {
        $participant = self::asString($key['participant'] ?? null);
        $participantPn = self::asString($key['participantPn'] ?? null);
        $lid = self::asString($key['participantLid'] ?? null);

        if ($lid === null && $participant !== null && str_contains($participant, '@lid')) {
            $lid = $participant;
        }

        return [
            $participantPn ?? $participant,
            self::toBareId($lid),
            self::asString($key['cleanedParticipantPn'] ?? null) ?? self::toPhone($participantPn),
        ];
    }

    /**
     * In a DM the counterparty is the conversation itself. Under lid addressing `remoteJid` holds
     * the lid while `remoteJidAlt` holds the phone form.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function resolveDirectSender(array $key, string $conversationJid): array
    {
        $remoteJid = self::asString($key['remoteJid'] ?? null);
        $lid = $remoteJid !== null && str_contains($remoteJid, '@lid') ? $remoteJid : null;

        return [
            $conversationJid,
            self::toBareId($lid),
            self::toPhone($conversationJid),
        ];
    }

    /**
     * WhatsApp binds the parts of one album under a shared parent message key. Undocumented by the
     * vendor — a hint we use when present, never a dependency.
     */
    private static function resolveAlbumId(array $messageContent): ?string
    {
        $association = (array) ($messageContent['messageContextInfo']['messageAssociation'] ?? []);

        if (($association['associationType'] ?? null) !== 'MEDIA_ALBUM') {
            return null;
        }

        return self::asString($association['parentMessageKey']['id'] ?? null);
    }

    /**
     * `Arr::string()` throws on a type mismatch — wrong for third-party JSON where an absent or
     * malformed field is a silent null, not a fault. `filled()` covers the emptiness half.
     */
    private static function asString(mixed $value): ?string
    {
        return is_string($value) && filled($value) ? $value : null;
    }

    private static function toPhone(?string $jid): ?string
    {
        if ($jid === null || str_contains($jid, '@lid') || str_contains($jid, '@g.us')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', Str::before($jid, '@'));

        return $digits !== '' ? $digits : null;
    }

    /**
     * The identity without its `@s.whatsapp.net` / `@lid` / `@g.us` suffix. Public because every
     * comparison of two WhatsApp identities has to happen on this form — the same person appears
     * as a bare phone, a suffixed JID, or a lid depending on which field carried them.
     */
    public static function toBareId(?string $jid): ?string
    {
        return $jid === null ? null : Str::before($jid, '@');
    }
}
