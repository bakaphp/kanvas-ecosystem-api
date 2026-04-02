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
        protected string $xmlContent
    ) {
        parent::__construct($mailerConfig, $emailContent);
    }

    public function build(): self
    {
        parent::build();

        return $this->attachData(
            $this->xmlContent,
            $this->attachmentName,
            [
                'mime' => 'application/xml',
            ]
        );
    }
}
