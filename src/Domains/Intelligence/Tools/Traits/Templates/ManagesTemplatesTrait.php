<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Templates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\Actions\DeleteTemplateAction;
use Kanvas\Templates\Actions\UpdateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;
use Throwable;

trait ManagesTemplatesTrait
{
    /**
     * @return array<string, mixed>
     */
    protected function createTemplateRecord(
        Apps $app,
        Companies $company,
        Users $user,
        string $name,
        string $body,
        ?string $subject = null,
        ?string $title = null,
    ): array {
        $name = trim($name);
        if ($name === '' || trim($body) === '') {
            return ['error' => 'Both a template name and an HTML body are required.'];
        }

        $template = new CreateTemplateAction(
            new TemplateInput(
                app: $app,
                name: $name,
                template: $body,
                subject: $subject,
                title: $title,
                company: $company,
                user: $user,
            )
        )->execute(overwrite: false);

        if (! $template->wasRecentlyCreated) {
            return [
                'error' => sprintf(
                    'A template named "%s" already exists (#%d). Use update_template to change it.',
                    $template->name,
                    $template->getId()
                ),
            ];
        }

        return [
            'success' => true,
            'template_id' => $template->getId(),
            'name' => $template->name,
            'message' => 'Template created. Use generate_template_pdf with this name to render it to a PDF.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateTemplateRecord(
        Apps $app,
        Companies $company,
        Users $user,
        int $templateId,
        ?string $body = null,
        ?string $subject = null,
        ?string $title = null,
    ): array {
        if ($body === null && $subject === null && $title === null) {
            return ['error' => 'Nothing to update. Pass at least one of html, subject, or title.'];
        }

        $owned = $this->resolveOwnedTemplate($app, $company, $user, $templateId);
        if (isset($owned['error'])) {
            return $owned;
        }

        $template = new UpdateTemplateAction($owned['template'])
            ->execute(
                $body,
                $subject,
                $title
            );

        return [
            'success' => true,
            'template_id' => $template->getId(),
            'name' => $template->name,
            'message' => 'Template updated.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteTemplateRecord(
        Apps $app,
        Companies $company,
        Users $user,
        int $templateId,
    ): array {
        $owned = $this->resolveOwnedTemplate($app, $company, $user, $templateId);
        if (isset($owned['error'])) {
            return $owned;
        }

        new DeleteTemplateAction($owned['template'])->execute();

        return [
            'success' => true,
            'template_id' => $templateId,
            'message' => 'Template deleted.',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function generateTemplatePdfForEntity(
        Apps $app,
        Users $user,
        ?Model $entity,
        string $templateName,
        ?string $fileName = null,
        array $data = [],
    ): array {
        if ($entity === null) {
            return ['error' => 'There is no record in scope to attach the PDF to.'];
        }

        if (! method_exists($entity, 'addFile')) {
            return ['error' => 'The record in scope does not support file attachments.'];
        }

        $templateName = trim($templateName);
        if ($templateName === '') {
            return ['error' => 'Provide the name of the template to render.'];
        }

        $fileName = $this->normalizePdfFileName($fileName ?? $templateName);

        try {
            $pdfFile = PdfService::generatePdfFromTemplate(
                $app,
                $user,
                $templateName,
                $entity,
                array_merge(['app' => $app], $data)
            );
        } catch (Throwable $e) {
            return ['error' => sprintf('Could not render template "%s": %s', $templateName, $e->getMessage())];
        }

        $entity->addFile($pdfFile, $fileName);

        return [
            'success' => true,
            'file_id' => $pdfFile->getId(),
            'file_url' => $pdfFile->url,
            'file_name' => $fileName,
            'entity' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'message' => 'PDF generated and attached to the record.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function listTemplates(
        Apps $app,
        Companies $company,
        Users $user,
        ?string $search = null,
    ): array {
        $search = $search !== null ? trim($search) : null;

        $templates = $this->visibleTemplatesQuery($app, $company)
            ->when(
                $search !== null && $search !== '',
                fn (Builder $query) => $query->where('name', 'like', '%' . $search . '%')
            )
            ->orderBy('name')
            ->limit(50)
            ->get();

        return [
            'success' => true,
            'count' => $templates->count(),
            'templates' => $templates->map(fn (Templates $template) => [
                'template_id' => $template->getId(),
                'name' => $template->name,
                'subject' => $template->subject,
                'title' => $template->title,
                'owned' => $this->ownsTemplate($template, $user),
                'is_system' => (bool) $template->is_system,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTemplate(
        Apps $app,
        Companies $company,
        Users $user,
        int $templateId,
    ): array {
        /** @var Templates|null $template */
        $template = $this->visibleTemplatesQuery($app, $company)
            ->where('id', $templateId)
            ->first();

        if ($template === null) {
            return ['error' => sprintf('Template #%d not found in this company.', $templateId)];
        }

        return [
            'success' => true,
            'template_id' => $template->getId(),
            'name' => $template->name,
            'subject' => $template->subject,
            'title' => $template->title,
            'html' => $template->template,
            'owned' => $this->ownsTemplate($template, $user),
            'is_system' => (bool) $template->is_system,
        ];
    }

    /**
     * Templates the agent can see/render: the company's own plus platform-global ones, across the
     * current app and the legacy app id — the same visibility generate_template_pdf resolves by name.
     */
    private function visibleTemplatesQuery(Apps $app, Companies $company): Builder
    {
        return Templates::notDeleted()
            ->whereIn('apps_id', [AppEnums::LEGACY_APP_ID->getValue(), $app->getId()])
            ->whereIn('companies_id', [$company->getId(), AppEnums::GLOBAL_COMPANY_ID->getValue()]);
    }

    /**
     * Whether the agent may update/delete this template: it created it, and it is not a system or
     * platform-global template.
     */
    private function ownsTemplate(Templates $template, Users $user): bool
    {
        $ownerId = (int) $template->users_id;

        return ! $template->is_system
            && $ownerId !== AppEnums::GLOBAL_USER_ID->getValue()
            && $ownerId === $user->getId();
    }

    /**
     * Resolve a template within the current tenant and enforce the owner-only guard: agents may only
     * touch templates they created — never system templates, never platform-global (users_id = 0) ones.
     *
     * @return array<string, mixed> either ['template' => Templates] or ['error' => string]
     */
    private function resolveOwnedTemplate(
        Apps $app,
        Companies $company,
        Users $user,
        int $templateId,
    ): array {
        try {
            /** @var Templates $template */
            $template = Templates::getByIdFromCompanyApp($templateId, $company, $app);
        } catch (Throwable) {
            return ['error' => sprintf('Template #%d not found in this company.', $templateId)];
        }

        if ($template->is_system) {
            return ['error' => 'System templates cannot be modified or deleted.'];
        }

        $ownerId = (int) $template->users_id;
        if ($ownerId === AppEnums::GLOBAL_USER_ID->getValue() || $ownerId !== $user->getId()) {
            return ['error' => 'You can only modify or delete templates you created.'];
        }

        return ['template' => $template];
    }

    private function normalizePdfFileName(string $name): string
    {
        $name = trim($name);
        if (! str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }

        return $name;
    }
}
