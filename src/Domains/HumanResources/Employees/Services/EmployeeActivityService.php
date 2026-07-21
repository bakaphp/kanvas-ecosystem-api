<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;

/**
 * Posts human-readable notes to an employee's activity channel so the UI has a
 * timeline of everything that happened to them (onboarding, leave, comp changes,
 * notes). Every note is one Message of the shared `hr_activity` type; the specific
 * event lives in custom_fields['event'] so the UI can pick an icon/label per row.
 */
class EmployeeActivityService
{
    public const string MESSAGE_VERB = 'hr_activity';

    public function record(
        Employee $employee,
        string $event,
        string $message,
        ?UserInterface $actor = null,
        array $context = [],
    ): Message {
        $app = $employee->app;
        $company = $employee->company;

        // ChannelDto needs a concrete Users; only forward one when the actor is that.
        $channelOwner = $actor instanceof Users ? $actor : null;
        $channel = new EmployeeChannelService()->findOrCreateForEmployee($employee, $app, $company, $channelOwner);
        $type = MessageTypeService::getOrCreate($app, self::MESSAGE_VERB);

        $input = new MessageInput(
            app: $app,
            company: $company,
            user: $actor ?? $this->fallbackActor($employee, $company),
            type: $type,
            message: $message,
            channel_slug: $channel->slug,
            custom_fields: ['event' => $event] + $context,
        );

        $action = new CreateMessageAction($input);
        $action->runWorkflow = false;

        return $action->execute();
    }

    private function fallbackActor(Employee $employee, Companies $company): UserInterface
    {
        return $company->getAiAgentUser() ?? Users::getById($employee->users_id);
    }
}
