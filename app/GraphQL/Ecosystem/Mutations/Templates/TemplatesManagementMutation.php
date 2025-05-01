<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Templates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;
use Kanvas\TemplatesVariables\Actions\CreateTemplateVariableAction;
use Kanvas\TemplatesVariables\DataTransferObject\TemplatesVariablesDto;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class TemplatesManagementMutation
{
    public function create(mixed $root, array $request): Templates
    {
        $request = $request['input'];
        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can create or update templates, please contact your admin');
        }

        $user = auth()->user();

        //We need to save the subject and content into email_template_variables
        //The template itself should have the content as {$content} inside the body
        //The subject, content and template should be then used for notifications

        $templateData = TemplateInput::from([
            'app' => app(Apps::class),
            'name' => $request['name'],
            'template' => $request['template'],
            'subject' => $request['subject'] ?? null,
            'title' => $request['title'] ?? null,
            'isSystem' => $request['is_system'] ?? false,
            'company' => $user->getCurrentCompany(),
            'user' => $user,
        ]);

        $template = (new CreateTemplateAction(
            $templateData
        ))->execute();

        foreach ($request['template_variables'] as $templateVariable) {
            $templateVariablesDto = new TemplatesVariablesDto(
                $templateVariable['key'],
                $templateVariable['value'],
                $template->id,
                app(Apps::class),
                $user->getCurrentCompany(),
                $user
            );

            //Create the template variable here
            $createTemplateVariableAction = (new CreateTemplateVariableAction(
                $templateVariablesDto
            ))->execute();
        }

        return $template;
    }

    public function update(mixed $root, array $request): Templates
    {
        $request = $request['input'];
        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can create or update templates, please contact your admin');
        }

        $app = app(Apps::class);
        $user = auth()->user();

        $template = Templates::getById(
            $request['id'],
            $app,
        );

        $templateData = TemplateInput::from([
            'app' => app(Apps::class),
            'name' => $request['name'],
            'template' => $request['template'],
            'subject' => $request['subject'] ?? null,
            'title' => $request['title'] ?? null,
            'isSystem' => $request['is_system'] ?? false,
            'company' => $user->getCurrentCompany(),
            'user' => $user,
        ]);

        $template->update([
            'name' => $templateData->name ?? $template->name,
            'template' => $templateData->template ?? $template->template,
            'subject' => $templateData->subject ?? $template->subject,
            'title' => $templateData->title ?? $template->title,
            'is_system' => $templateData->isSystem ?? $template->is_system,
            'parent_template_id' => $templateData->parentTemplateId ?? $template->parent_template_id,
        ]);

        foreach ($request['template_variables'] as $templateVariable) {
            $templateVariablesDto = new TemplatesVariablesDto(
                $templateVariable['key'],
                $templateVariable['value'],
                $template->id,
                app(Apps::class),
                $user->getCurrentCompany(),
                $user
            );

            //Create the template variable here
            $createTemplateVariableAction = (new CreateTemplateVariableAction(
                $templateVariablesDto
            ))->execute();
        }

        return $template;
    }

    public function deleteTemplate(mixed $root, array $request): bool
    {
        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can create or update templates, please contact your admin');
        }
        $app = app((Apps::class));
        $template = Templates::fromApp($app)
            //->fromCompany(auth()->user()->getCurrentCompany())
            ->where('is_system', false)
            ->findOrFail($request['id']);

        return $template->delete();
    }
}
