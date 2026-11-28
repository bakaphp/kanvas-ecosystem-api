<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email;

use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\InboundTurn;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Users\Models\Users;

/**
 * Turns a delivery's content into the email itself, the same for every email transport: the subject,
 * the company template the text sits in, the signature and the copies. A transport only decides how
 * the result leaves.
 */
final class EmailComposer
{
    /**
     * The template customer email has always gone out in, and what a named template a company never
     * got falls back to: a missing template must not lose the email.
     */
    public const string DEFAULT_TEMPLATE = 'first-time-agent-engagement';

    /**
     * What an agent's answer goes out in when it speaks from the company's address.
     */
    public const string REPLY_TEMPLATE = 'agent-email-response';

    public readonly EmailOptions $options;

    public function __construct(
        private readonly Delivery $delivery,
        private readonly Content $content
    ) {
        $this->options = $content->email ?? new EmailOptions();
    }

    /**
     * The caller's subject, else the thread the reply continues, else the company's name: a mail
     * without one is what spam filters are built for.
     */
    public function subject(): string
    {
        if ($this->content->subject !== null && $this->content->subject !== '') {
            return $this->content->subject;
        }

        $replied = $this->repliedMessage();
        $thread = $replied !== null ? InboundTurn::subjectOf($replied) : null;

        if ($thread !== null) {
            return preg_match('/^\s*re:/i', $thread) === 1 ? $thread : 'Re: ' . $thread;
        }

        return (string) $this->delivery->company->name;
    }

    public function repliedMessage(): ?Message
    {
        $messageId = $this->delivery->request?->reply_to_message_id;

        return $messageId !== null ? Message::query()->find($messageId) : null;
    }

    public function signature(): ?UserEmailSignature
    {
        if ($this->options->signatureUserId === null) {
            return null;
        }

        return UserEmailSignature::fromUser(
            Users::query()->find($this->options->signatureUserId),
            $this->delivery->company
        );
    }

    public function template(): string
    {
        $template = $this->options->template ?? self::DEFAULT_TEMPLATE;

        if ($template === self::DEFAULT_TEMPLATE) {
            return $template;
        }

        try {
            TemplatesRepository::getByName($template, $this->delivery->app, $this->delivery->company);
        } catch (ModelNotFoundException) {
            return self::DEFAULT_TEMPLATE;
        }

        return $template;
    }

    /**
     * The email as the notification the company's mail has always been: template, sender, copies,
     * signature and attachments set, ready for a mailer.
     */
    public function notification(): Blank
    {
        $lead = $this->lead();
        $people = $this->delivery->request?->people;
        $attachments = array_map(fn (Attachment $attachment): string => $attachment->url, $this->content->attachments);

        $notification = new Blank(
            $this->template(),
            [
                'content' => MarkdownEmailRenderer::toEmailHtml($this->content->text),
                'lead' => $lead,
                'people' => $people,
                // Over the text and the person, under the sender's own keys: a campaign's
                // per-recipient variables may restate the first, never the second.
                ...$this->options->templateData,
                'app' => $this->delivery->app,
                'company' => $this->delivery->company,
                'signature' => $this->options->companySignature,
            ],
            ['mail'],
            $lead ?? $people ?? $this->delivery->company,
            $attachments !== [] ? $attachments : null
        );

        if ($lead?->user !== null) {
            $notification->setFromUser($lead->user);
        }

        $notification->setSubject($this->subject());
        $notification->setUserSignature($this->signature());
        $notification->setCc($this->options->cc);

        if ($this->options->important) {
            $notification->setImportant();
        }

        return $notification;
    }

    /**
     * The finished HTML, for a transport that posts the body to a send API instead of handing a
     * notification to a mailer. With no template named the mail reads as a person wrote it: the
     * text and the signature, nothing around them.
     */
    public function html(): string
    {
        if ($this->options->template === null) {
            return self::writtenHtml($this->content->text, $this->signature());
        }

        return view('emails.layout', ['html' => $this->notification()->getEmailContent()])->render();
    }

    public function plainText(): string
    {
        return self::writtenText($this->content->text, $this->signature());
    }

    public static function writtenHtml(string $markdown, ?UserEmailSignature $signature = null): string
    {
        return MarkdownEmailRenderer::toEmailHtml($markdown) . ($signature?->toHtml() ?? '');
    }

    public static function writtenText(string $text, ?UserEmailSignature $signature = null): string
    {
        return $text . ($signature !== null ? "\n\nBest Regards,\n" . $signature->text : '');
    }

    private function lead(): ?Lead
    {
        if ($this->options->leadId === null) {
            return null;
        }

        return Lead::query()
            ->fromApp($this->delivery->app)
            ->fromCompany($this->delivery->company->getId())
            ->find($this->options->leadId);
    }
}
