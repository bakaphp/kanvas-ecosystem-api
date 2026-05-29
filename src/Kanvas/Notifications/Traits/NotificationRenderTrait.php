<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Support\PushTemplateParser;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\Repositories\TemplatesRepository;

trait NotificationRenderTrait
{
    protected ?string $templateName = null;
    protected ?string $pushTemplateName = null;
    protected ?string $pushTitleTemplateName = null;
    protected ?string $pushMessageTemplateName = null;
    protected ?string $pushSubtitleTemplateName = null;
    protected ?string $smsTemplateName = null;
    public array $data = [];

    abstract public function getType(): NotificationTypes;

    public function message(): string
    {
        // data['message'] is also used as a template variable, so callers may set it
        // to a model object (e.g. a Social Message). Only treat it as ready-made content
        // when it is actually a string; otherwise fall back to the rendered email body.
        $message = $this->data['message'] ?? null;

        return is_string($message) ? $message : $this->getEmailContentIfAvailable();
    }

    public function getEmailContent(): string
    {
        return $this->getType()->hasEmailTemplate() ? $this->getEmailTemplate() : '';
    }

    public function getNotificationTitle(): ?string
    {
        $template = $this->findTemplateByName();
        $title = $template?->subject ?? $this->getType()->title;

        return $title ? Blade::render($title, $this->getData()) : null;
    }

    public function setTemplateName(string $name): self
    {
        $this->templateName = $name;

        return $this;
    }

    public function setPushTemplateName(string $name): self
    {
        $this->pushTemplateName = $name;

        return $this;
    }

    public function setPushTitleTemplateName(string $name): self
    {
        $this->pushTitleTemplateName = $name;

        return $this;
    }

    public function setPushMessageTemplateName(string $name): self
    {
        $this->pushMessageTemplateName = $name;

        return $this;
    }

    public function setPushSubtitleTemplateName(string $name): self
    {
        $this->pushSubtitleTemplateName = $name;

        return $this;
    }

    public function setSmsTemplateName(string $name): self
    {
        $this->smsTemplateName = $name;

        return $this;
    }

    public function setData(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTemplateName(): string
    {
        return $this->templateName ?? $this->getType()->getTemplateName();
    }

    protected function getPushTemplate(): string
    {
        $templateName = $this->pushTemplateName
            ?? $this->templateName
            ?? $this->getType()->getPushTemplateName();

        return $this->renderTemplate($templateName);
    }

    /**
     * Resolve push title/message/subtitle as separate rendered strings.
     *
     * @return array{title: string, message: string, subtitle: string|null}
     */
    protected function getPushContent(): array
    {
        if ($this->pushTitleTemplateName !== null
            || $this->pushMessageTemplateName !== null
            || $this->pushSubtitleTemplateName !== null) {
            return [
                'title' => $this->tryRenderTemplate($this->pushTitleTemplateName) ?? '',
                'message' => $this->tryRenderTemplate($this->pushMessageTemplateName) ?? '',
                'subtitle' => $this->tryRenderTemplate($this->pushSubtitleTemplateName),
            ];
        }

        $rendered = Str::cleanJsonString($this->getPushTemplate());

        $strict = PushTemplateParser::parseStrict($rendered);

        if ($strict !== null) {
            return $strict;
        }

        report('Push template rendered to invalid JSON, recovered via lenient parser: ' . json_encode($rendered));

        return PushTemplateParser::parseLenient($rendered);
    }

    private function tryRenderTemplate(?string $templateName): ?string
    {
        if ($templateName === null || $templateName === '') {
            return null;
        }

        try {
            return $this->renderTemplate($templateName);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    protected function getSmsTemplate(): string
    {
        $templateName = $this->smsTemplateName
            ?? $this->templateName
            ?? $this->getType()->getSmsTemplateName();

        return $this->renderTemplate($templateName);
    }

    protected function getEmailTemplate(): string
    {
        if (! $this->getType()->hasEmailTemplate()) {
            throw new Exception('This notification type does not have an email template');
        }

        return $this->renderTemplate($this->getTemplateName());
    }

    protected function getEmailContentIfAvailable(): string
    {
        return $this->getType()->hasEmailTemplate() ? $this->getEmailTemplate() : '';
    }

    protected function findTemplateByName(): ?object
    {
        try {
            return TemplatesRepository::getByName(
                $this->getTemplateName(),
                $this->app,
                $this->company
            );
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    protected function renderTemplate(string $templateName): string
    {
        $renderTemplate = new RenderTemplateAction($this->app, $this->company);

        return $renderTemplate->execute($templateName, $this->getData());
    }
}
