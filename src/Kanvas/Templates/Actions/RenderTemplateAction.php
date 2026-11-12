<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Templates\Models\Templates;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Templates\Support\TemplateSandbox;

class RenderTemplateAction
{
    private const string BODY_SLOT = '[body]';

    public function __construct(
        protected ?AppInterface $app = null,
        protected ?CompanyInterface $company = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * Raw `$templateContent` comes from the caller rather than a template row, so it is never
     * treated as a hand-marked Blade template.
     */
    public function execute(string $templateName, array $templateParams, ?string $templateContent = null): string
    {
        if ($templateContent !== null) {
            return TemplateSandbox::renderHtml($templateContent, $templateParams);
        }

        /**
         * @psalm-suppress PossiblyNullArgument
         */
        $template = TemplatesRepository::getByName($templateName, $this->app, $this->company);

        return $this->renderTemplate($template, $templateParams);
    }

    /**
     * The stored row named `$templateName` (company, then app, then platform), else the packaged file under
     * resources/ through the sandbox: a tenant restyles without code and never needs seeding first.
     */
    public function renderNamedOrPackaged(string $templateName, array $templateParams, string $resourcePath): string
    {
        try {
            $template = TemplatesRepository::getByName($templateName, $this->app, $this->company);
        } catch (ModelNotFoundException) {
            return $this->execute($templateName, $templateParams, File::get(resource_path($resourcePath)));
        }

        return $this->renderTemplate($template, $templateParams);
    }

    /**
     * A Blade parent and a Blade child are both hand-made, so they keep being compiled as one text.
     * Any other pair renders separately and the child's output fills the parent's slot after the
     * parent has rendered: spliced in before, a twig body would be compiled by Blade.
     */
    public function renderTemplate(Templates $template, array $templateParams): string
    {
        if (! $template->hasParentTemplate()) {
            return $this->renderBody($template, $templateParams);
        }

        /** @var Templates $parent */
        $parent = $template->parentTemplate()->firstOrFail();

        if ($template->isBlade() && $parent->isBlade()) {
            return Blade::render(
                str_replace(self::BODY_SLOT, $template->template, $parent->template),
                $templateParams
            );
        }

        return str_replace(
            self::BODY_SLOT,
            $this->renderBody($template, $templateParams),
            $this->renderBody($parent, $templateParams)
        );
    }

    public function renderBody(Templates $template, array $templateParams): string
    {
        return $template->isBlade()
            ? Blade::render($template->template, $templateParams)
            : TemplateSandbox::renderHtml($template->template, $templateParams);
    }

    public function renderSubject(Templates $template, array $templateParams): string
    {
        $subject = (string) $template->subject;

        return $template->isBlade()
            ? Blade::render($subject, $templateParams)
            : TemplateSandbox::render($subject, $templateParams);
    }
}
