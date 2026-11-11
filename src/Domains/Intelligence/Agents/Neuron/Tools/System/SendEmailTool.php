<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Connectors\Mailgun\Actions\SendAsAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Email', category: 'communication')]
class SendEmailTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;

    protected string $name = 'send_email';

    protected ?string $description = 'Send an email to any recipient (client, customer, vendor, partner, or teammate) at their email address. Supports CC (comma-separated email addresses). The email is dispatched from the agent\'s mailbox or company email configuration, with Markdown rendered as HTML. Use this when asked to email external contacts, send reminders, or communicate directly via email.';

    public const string LEDGER_EVENT = 'agent.email.sent';

    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?Session $session = null,
    ) {
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Recipient email address (client, external contact, vendor, partner, or teammate).',
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line of the email. Short and specific.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The email body, written in first person on behalf of the business. Markdown is supported (headings, bold, lists, links) and rendered to HTML.',
                required: true,
            ),
            new ToolProperty(
                name: 'cc',
                type: PropertyType::STRING,
                description: 'Optional comma-separated email addresses to CC.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $to,
        string $subject,
        string $body,
        ?string $cc = null,
    ): array {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot send an email.'];
        }

        $to = trim($to);
        $subject = trim($subject);
        $body = trim($body);

        if ($to === '' || $subject === '' || $body === '') {
            return ['status' => 'error', 'message' => 'A recipient email (to), a subject, and a body are all required.'];
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => 'error', 'message' => "The recipient email address '{$to}' is invalid."];
        }

        $ccList = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $cc ?? '')),
            static fn (string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        )));

        $mailbox = new AgentMailboxService()->addressFor($this->agent);
        if ($mailbox !== null) {
            new SendAsAgentMailboxAction(
                agent: $this->agent,
                to: $to,
                subject: $subject,
                markdownBody: $body,
                cc: $ccList,
            )->execute();

            $from = $mailbox;
        } else {
            $smtp = new SmtpRuntimeConfiguration($this->agent->app, $this->agent->company);
            $fromMail = $smtp->getFromEmail();
            $mailable = new KanvasMailable($smtp->loadSmtpSettings(), MarkdownEmailRenderer::toEmailHtml($body))
                ->from($fromMail['address'], $fromMail['name'])
                ->to($to)
                ->subject($subject);

            if ($ccList !== []) {
                $mailable->cc($ccList);
            }

            Mail::send($mailable);
            $from = (string) $fromMail['address'];
        }

        try {
            new AppendEventAction(new EventData(
                app: $this->agent->app,
                company: $this->agent->company,
                sourceDomain: 'Intelligence',
                eventType: self::LEDGER_EVENT,
                status: EventStatusEnum::SUCCESS,
                actorType: 'Agent',
                actorId: $this->agent->getId(),
                payload: [
                    'to' => $to,
                    'cc' => $ccList,
                    'subject' => $subject,
                    'body' => $body,
                ],
            ))->execute();
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'status' => 'success',
            'to' => $to,
            'cc' => $ccList,
            'from' => $from,
            'subject' => $subject,
            'message' => 'Email sent to ' . $to . ($ccList !== [] ? ' (cc: ' . implode(', ', $ccList) . ')' : '') . '.',
        ];
    }
}
