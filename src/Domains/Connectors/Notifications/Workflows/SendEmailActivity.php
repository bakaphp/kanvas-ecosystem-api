<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Notifications\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SendEmailActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! isset($params['template_name']) || ! isset($params['data']) || ! $params['toEmail']) {
            return [
                'message' => 'Missing required params template_name or data or toEmail',
                'data' => $params,
                'user' => $entity->getId(),
            ];
        }

        $data = Str::isJson($params['data']) ? json_decode($params['data'], true) : (array) $params['data'];
        $data['app'] = $app;
        $data['subject'] = $params['subject'];

        $smtpRuntime = new SmtpRuntimeConfiguration($app);
        $smtpRuntime->loadSmtpSettings();
        //kanvas-notifications-templates-resetpassword
        $notification = new Blank(
            $params['template_name'],
            $data, // This can have more validation like validate if is array o json
            ['mail'],
            $entity
        );

        //$notification->setFromUser($user); @todo set system default user
        Notification::route('mail', $params['toEmail'])->notify($notification);

        return [
            'message' => 'Email sent successfully',
            'data' => $params,
            'entity' => [
                get_class($entity),
                $entity->getId(),
            ],
        ];
    }
}
