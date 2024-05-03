<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Notifications\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class SendEmailActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 10;

    /**
     * @param Lead $lead
     */
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! isset($params['template_name']) || ! isset($params['data'])) {
            return [
                'message' => 'Missing required params template_name or data',
                'data' => $params,
                'user' => $user->getId(),
            ];
        }

        //kanvas-notifications-templates-resetpassword
        $notification = new Blank(
            $params['template_name'],
            Str::isJson($params['data']) ? json_decode($params['data'], true) : (array) $params['data'], // This can have more validation like validate if is array o json
            ['mail'],
            $user
        );

        //$notification->setFromUser($user); @todo set system default user
        if (! isset($params['toEmail'])) {
            $user->notify($notification);
        }
        Notification::route('mail', $params['toEmail'])->notify($notification);

        return [
            'message' => 'Email sent successfully',
            'data' => $params,
            'user' => $user->getId(),
        ];
    }
}
