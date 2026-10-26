<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

/**
 * What is being said, independent of the channel that carries it. Stored on the request as written,
 * so a retry sends the same thing the caller asked for rather than whatever it would compose today.
 */
final readonly class Content
{
    /**
     * @param list<Attachment> $attachments
     * @param bool $attachmentsRequired a channel that cannot carry them fails the delivery instead
     *                                  of sending the text alone with a warning
     */
    private function __construct(
        public string $text,
        public ?string $subject = null,
        public array $attachments = [],
        public bool $attachmentsRequired = false
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * @param iterable<Attachment|string> $attachments attachments, or bare URLs
     */
    public function withAttachments(iterable $attachments, bool $required = false): self
    {
        $normalized = [];

        foreach ($attachments as $attachment) {
            $normalized[] = $attachment instanceof Attachment ? $attachment : new Attachment($attachment);
        }

        return new self(
            $this->text,
            $this->subject,
            $normalized,
            $required
        );
    }

    public function withSubject(string $subject): self
    {
        return new self(
            $this->text,
            $subject,
            $this->attachments,
            $this->attachmentsRequired
        );
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'subject' => $this->subject,
            'attachments' => array_map(fn (Attachment $attachment): array => $attachment->toArray(), $this->attachments),
            'attachments_required' => $this->attachmentsRequired,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['text'] ?? ''),
            $data['subject'] ?? null,
            array_map(Attachment::fromArray(...), $data['attachments'] ?? []),
            (bool) ($data['attachments_required'] ?? false)
        );
    }
}
