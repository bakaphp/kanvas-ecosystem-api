<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Traits;
use Kanvas\Companies\Models\Companies;
trait TwilioNotificationTrait
{
    protected string $message;
    protected string $from;
    protected string $to;
    protected Companies $company;

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function setFrom(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    public function setTo(string $to): self
    {
        $this->to = $to;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFromNumber(): string
    {
        return $this->from;
    }

    public function getToNumber(): string
    {
        return $this->to;
    }

    public function getCompany(): Companies
    {
        return $this->company;
    }

    public function setCompany(Companies $company): self
    {
        $this->company = $company;

        return $this;
    }
}
