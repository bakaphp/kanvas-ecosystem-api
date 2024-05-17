<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Templates\Actions\RenderTemplateAction;

trait NotificationRenderTrait
{
    protected ?string $templateName = null;
    public array $data = [];

    abstract public function getType(): NotificationTypes;

    /**
     * Notification message the user will get.
     */
    public function message(): string
    {
        if ($this->getType()->hasEmailTemplate()) {
            return $this->getEmailTemplate();
        }

        return '';
    }

    public function getEmailContent(): string
    {
        if ($this->getType()->hasEmailTemplate()) {
            return $this->getEmailTemplate();
        }

        return '';
    }

    public function getNotificationTitle(): ?string
    {
        $title = $this->getType()->title;

        if (! $title) {
            return null;
        }

        return Blade::render(
            $title,
            $this->getData()
        );
    }

    protected function getPushTemplate(): string
    {
        $templateName = $this->getType()->getPushTemplateName();

        $renderTemplate = new RenderTemplateAction($this->app, $this->company);

        return $renderTemplate->execute(
            $templateName,
            $this->getData()
        );
    }

    /**
     * Given the HTML for the current email notification
     */
    protected function getEmailTemplate(): string
    {
        if (! $this->getType()->hasEmailTemplate()) {
            throw new Exception('This notification type does not have an email template');
        }

        $renderTemplate = new RenderTemplateAction($this->app, $this->company);

        return $renderTemplate->execute(
            $this->getTemplateName(),
            $this->getData()
        );
    }

    /**
     * setTemplateName
     *
     * @param  mixed $name
     */
    public function setTemplateName(string $name): self
    {
        $this->templateName = $name;

        return $this;
    }

    /**
     * setData
     */
    public function setData(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * getData.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /*
    * Get notification template Name
    */
    public function getTemplateName(): string
    {
        return $this->templateName === null ? $this->getType()->getTemplateName() : $this->templateName;
    }
}
