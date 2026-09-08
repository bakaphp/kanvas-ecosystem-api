<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Templates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\Actions\DeleteTemplateAction;
use Kanvas\Templates\Actions\UpdateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;
use Throwable;

trait ManagesTemplatesTrait
{
    use ReportsToolOutcome;

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
            return $this->invalidArgs('Both a template name and an HTML body are required.');
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
            return $this->noop(
                [
                    'success' => false,
                    'error' => sprintf(
                        'A template named "%s" already exists (#%d), so nothing was created.',
                        $template->name,
                        $template->getId()
                    ),
                    'template_id' => $template->getId(),
                ],
                guidance: 'Do NOT tell the user you created it. Either call update_template on that id, or '
                    . 'create it under a different name.'
            );
        }

        return $this->ok([
            'template_id' => $template->getId(),
            'name' => $template->name,
            'message' => 'Template created. Use generate_template_pdf with this name to render it to a PDF.',
        ]);
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
            return $this->invalidArgs('Nothing to update. Pass at least one of html, subject, or title.');
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

        return $this->ok([
            'template_id' => $template->getId(),
            'name' => $template->name,
            'message' => 'Template updated.',
        ]);
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

        return $this->ok([
            'template_id' => $templateId,
            'message' => 'Template deleted.',
        ]);
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
            return $this->denied('There is no record in scope to attach the PDF to.');
        }

        if (! method_exists($entity, 'addFile')) {
            return $this->denied('The record in scope does not support file attachments.');
        }

        $templateName = trim($templateName);
        if ($templateName === '') {
            return $this->invalidArgs('Provide the name of the template to render.');
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
            return $this->failed(
                sprintf('Could not render template "%s": %s', $templateName, $e->getMessage()),
                guidance: 'No PDF exists. Do NOT tell the user one was generated or attached.'
            );
        }

        $entity->addFile($pdfFile, $fileName);

        return $this->ok([
            'file_id' => $pdfFile->getId(),
            'file_url' => $pdfFile->url,
            'file_name' => $fileName,
            'entity' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'message' => 'PDF generated and attached to the record.',
        ]);
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

        $rows = $templates->map(fn (Templates $template) => [
            'template_id' => $template->getId(),
            'name' => $template->name,
            'subject' => $template->subject,
            'title' => $template->title,
            'owned' => $this->ownsTemplate($template, $user),
            'is_system' => (bool) $template->is_system,
        ])->all();

        return $this->ok(
            [
                'count' => count($rows),
                'templates' => $rows,
            ],
            guidance: $this->editabilityGuidance(
                in_array(false, array_column($rows, 'owned'), true)
            )
        );
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
            return $this->notFound([
                'success' => false,
                'error' => sprintf('Template #%d not found in this company.', $templateId),
            ]);
        }

        $owned = $this->ownsTemplate($template, $user);

        return $this->ok(
            [
                'template_id' => $template->getId(),
                'name' => $template->name,
                'subject' => $template->subject,
                'title' => $template->title,
                'html' => $template->template,
                'owned' => $owned,
                'is_system' => (bool) $template->is_system,
            ],
            guidance: $this->editabilityGuidance(! $owned)
        );
    }

    /**
     * What `owned: false` actually costs the agent, said in the read that surfaces the flag rather
     * than only in the refusal that follows it. Without this the flag is a word the model can read
     * past, then promise the user an edit that update_template will refuse.
     */
    private function editabilityGuidance(bool $anyUnowned): ?string
    {
        if (! $anyUnowned) {
            return null;
        }

        return 'Anything with "owned": false was created by someone else: update_template and '
            . 'delete_template WILL be refused on it and nothing will change. Never promise the user an '
            . 'edit to one — say its owner has to make the change, or offer to create_template a new '
            . 'template and hand back the new id.';
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
            return $this->notFound([
                'success' => false,
                'error' => sprintf('Template #%d not found in this company.', $templateId),
            ]);
        }

        if ($template->is_system) {
            return $this->denied(
                sprintf('Template #%d is a system template and was NOT changed.', $templateId)
            );
        }

        $ownerId = (int) $template->users_id;
        if ($ownerId === AppEnums::GLOBAL_USER_ID->getValue() || $ownerId !== $user->getId()) {
            return $this->denied(
                sprintf(
                    'Template #%d belongs to someone else, so nothing was changed. You may only edit templates '
                    . 'you created yourself.',
                    $templateId
                ),
                guidance: 'Say explicitly that this template was NOT updated. Offer the alternatives: its owner '
                    . 'can paste your HTML into it, or you can create_template a new one and hand back that id.'
            );
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
