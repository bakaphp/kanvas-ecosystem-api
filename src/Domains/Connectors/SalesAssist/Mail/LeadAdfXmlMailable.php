<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Mail;

use Kanvas\Notifications\KanvasMailable;

class LeadAdfXmlMailable extends KanvasMailable
{
    public function __construct(
        array $mailerConfig,
        string $emailContent,
        protected string $attachmentName,
        protected string $xmlContent,
        protected bool $sendAsAttachment = false
    ) {
        parent::__construct($mailerConfig, $emailContent);
    }

    public function build(): self
    {
        parent::build();

        if (! $this->sendAsAttachment) {
            return $this;
        }

        return $this->attachData(
            $this->xmlContent,
            $this->attachmentName,
            [
                'mime' => 'application/xml',
            ]
        );
    }
}
