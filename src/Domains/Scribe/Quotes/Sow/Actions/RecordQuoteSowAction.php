<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteSowException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use Kanvas\Scribe\Quotes\Sow\Services\SowConsistencyService;
use Kanvas\Scribe\Quotes\Sow\Services\SowMessageTypeService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Validations\MessageSchemaValidator;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Writes a new SOW revision for a quote: a `sow` message whose parent is the previous revision, and
 * the quote's pointer moved to it. Nothing is edited in place, so every earlier revision stays
 * readable through the parent chain.
 *
 * Refuses an inconsistent SOW (InvalidQuoteSowException, one reason per problem) and a quote that is
 * no longer draft/sent: once accepted, the SOW is committed and a change is a quote revision.
 */
class RecordQuoteSowAction
{
    public function __construct(
        public readonly Quote $quote,
        public readonly StatementOfWork $sow,
        public readonly UserInterface $user,
        protected readonly SowConsistencyService $consistency = new SowConsistencyService(),
    ) {
    }

    public function execute(): Message
    {
        if (! $this->quote->status->canBeRevised()) {
            throw new ValidationException(
                "Quote {$this->quote->getId()} is {$this->quote->status->value}; its SOW is committed. "
                . 'A change needs a quote revision (a change order), which gets its own SOW.'
            );
        }

        $problems = $this->consistency->problems($this->sow, $this->quote);

        if ($problems !== []) {
            throw new InvalidQuoteSowException($problems);
        }

        $app = $this->quote->app;
        $type = SowMessageTypeService::ensure($app);
        $payload = [...$this->sow->toPayload($this->quote->getId()), 'template' => $this->template()];

        $draft = new Message();
        $draft->message = $payload;
        new MessageSchemaValidator($draft, $type)->validate();

        $action = new CreateMessageAction(
            messageInput: new MessageInput(
                app: $app,
                company: $this->quote->company,
                user: $this->user,
                type: $type,
                message: $payload,
                parent_id: $this->quote->sow_message_id,
                is_public: 0,
            ),
            systemModule: SystemModulesRepository::getByModelName(Quote::class, $app),
            entityId: $this->quote->getId(),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $this->quote->sow_message_id = $message->getId();
        $this->quote->saveOrFail();

        return $message;
    }

    /**
     * The layout is fixed when the SOW is written, so switching the company default later never changes a SOW
     * already sent or signed; a SOW written through GraphQL takes the default the same way an agent's does.
     */
    private function template(): ?string
    {
        $templates = new CompanySowTemplateService($this->quote->app, $this->quote->company);

        if ($this->sow->template === null) {
            return $templates->defaultVersion();
        }

        try {
            $version = CompanySowTemplateService::normalizeVersion($this->sow->template);
            $templates->requireVersion($version);
        } catch (ValidationException $e) {
            throw new InvalidQuoteSowException([$e->getMessage()]);
        }

        return $version;
    }
}
