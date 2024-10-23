<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Templates;

use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Jobs\DeleteCompanyJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Users\Actions\AssignRoleAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\TemplatesVariables\DataTransferObject\TemplatesVariablesDto;
use Kanvas\Templates\Models\Templates;
use Kanvas\TemplatesVariables\Actions\CreateTemplateVariableAction;

class TemplatesManagementMutation
{
    /**
     * createCompany
     */
    public function createOrUpdate(mixed $root, array $request): Templates
    {
        $request = $request['input'];
        
        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can create or update templates, please contact your admin');
        }

        $user = auth()->user();

         //We need to save the subject and content into email_template_variables
        //The template itself should have the content as {$content} inside the body
        //The subject, content and template should be then used for notifications


        $templatedto =  new TemplateInput(
            app(Apps::class),
            $request['name'],
            $request['template'],
            $user->getCurrentCompany(),
            $user
        );

        $template = (new CreateTemplateAction($templatedto))->execute();

        foreach ($request['template_variables'] as $templateVariable) {
            $templateVariablesDto =  new TemplatesVariablesDto(
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
}