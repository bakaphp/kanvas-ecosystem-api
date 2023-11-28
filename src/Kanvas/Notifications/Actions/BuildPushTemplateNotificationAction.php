<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Illuminate\Support\Facades\Blade;
use Kanvas\Users\Models\Users;

class BuildPushTemplateNotificationAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private string $template,
        private Users $fromUser,
        private Users $toUser,
        private array $message
    ) {
    }

    /**
     * execute.
     */
    public function execute(): array
    {
        $templateJson = json_decode($this->template);
        $data = [
            'toUser' => $this->toUser,
            'fromUser' => $this->fromUser,
            'entity' => $this->message,
        ];

        $bladeTitle = Blade::compileString($templateJson->title);
        $bladeSubtitle = Blade::compileString($templateJson->subtitle);
        $bladeMessage = Blade::compileString($templateJson->message);

        extract($data);

        ob_start();
        eval(' ?>' . $bladeTitle . '<?php ');
        $compiledTitle = ob_get_clean();

        ob_start();
        eval(' ?>' . $bladeSubtitle . '<?php ');
        $compiledSubtitle = ob_get_clean();

        ob_start();
        eval(' ?>' . $bladeMessage . '<?php ');
        $compiledMessage = ob_get_clean();


        return [
            'title' => $compiledTitle,
            'subtitle' => $compiledSubtitle,
            'message' => $compiledMessage,
        ];
    }
}
