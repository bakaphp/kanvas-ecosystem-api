<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\DraftsEmailSignatures;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Notifications\Support\SignatureHtml;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Self-service: saves the signature of the person talking, never anyone else's — it takes no user
 * identifier, and a turn acted by the agent itself is refused rather than writing a signature onto the
 * agent's own user. Stored in the user's per-company setting the email senders read.
 */
#[AgentTool(name: 'Set My Email Signature', category: 'system')]
class SetMyEmailSignatureTool extends Tool implements RequiresSystemAgent
{
    use DraftsEmailSignatures;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RequiresHumanCaller;

    private const int MAX_TEXT_LENGTH = 1000;

    protected string $name = 'set_my_email_signature';

    protected ?string $description = 'Save, replace or turn off YOUR OWN email signature — always for the person you are talking to, '
        . 'never someone else. It is added at the end of the emails sent on their behalf (lead emails, emails to contacts, '
        . 'campaigns). To save a design they approved from preview_email_signature, pass its draft_id. For a simple '
        . 'text signature pass text instead (plain, one item per line, name first). enabled=false turns it off and '
        . 'keeps it. It is saved for THIS company only unless they ask for one signature everywhere (all_companies=true).';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'draft_id',
                type: PropertyType::STRING,
                description: 'The draft_id of the previewed design they approved.',
                required: false,
            ),
            new ToolProperty(
                name: 'text',
                type: PropertyType::STRING,
                description: 'A plain-text signature, one item per line, name first. Ignored when draft_id is given.',
                required: false,
            ),
            new ToolProperty(
                name: 'enabled',
                type: PropertyType::BOOLEAN,
                description: 'false turns the signature off without deleting it. Defaults to true.',
                required: false,
            ),
            new ToolProperty(
                name: 'all_companies',
                type: PropertyType::BOOLEAN,
                description: 'true saves it as the signature for every company they belong to instead of only this one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $draft_id = null,
        ?string $text = null,
        ?bool $enabled = null,
        ?bool $all_companies = null,
    ): array {
        $caller = $this->humanCallerOrDenial(
            'save an email signature',
            'Say plainly that NO signature was saved.',
            ['saved' => false],
        );

        if (is_array($caller)) {
            return $caller;
        }

        $allCompanies = $all_companies ?? false;
        $key = $allCompanies ? UserEmailSignature::SETTING : UserEmailSignature::companySettingKey($this->company);
        // "Use it everywhere" with no global signature yet means the one in effect here.
        $saved = $caller->get($key)
            ?? ($allCompanies ? $caller->get(UserEmailSignature::companySettingKey($this->company)) : null);

        $signature = $this->signatureToSave(
            $caller,
            $saved,
            Str::trimToNull($draft_id),
            Str::trimToNull($text)
        );

        if (is_array($signature)) {
            return $signature;
        }

        $enabled ??= true;
        $caller->set($key, $signature->toSetting($enabled));

        return $this->ok([
            'saved' => true,
            'enabled' => $enabled,
            'scope' => $allCompanies ? 'all_companies' : 'this_company',
            'company' => $this->company->name,
            'design' => $signature->html !== null ? 'html' : 'text',
            'text' => $signature->text,
        ]);
    }

    /**
     * An approved draft, else new text, else what is already saved — so turning it off keeps the design.
     *
     * @return UserEmailSignature|array<string, mixed>
     */
    private function signatureToSave(
        Users $caller,
        mixed $saved,
        ?string $draftId,
        ?string $text
    ): UserEmailSignature|array {
        if ($draftId !== null) {
            $html = $this->signatureDraft($caller, $draftId);

            return $html !== null
                ? new UserEmailSignature(SignatureHtml::toText($html), html: $html)
                : $this->notFound(
                    ['saved' => false, 'draft_id' => $draftId],
                    guidance: 'That preview expired or belongs to another conversation. Preview the design again.',
                );
        }

        if ($text !== null) {
            return mb_strlen($text) > self::MAX_TEXT_LENGTH
                ? $this->invalidArgs('The signature is longer than ' . self::MAX_TEXT_LENGTH . ' characters.', ['saved' => false])
                : new UserEmailSignature($text);
        }

        $saved = UserEmailSignature::decodeSetting($saved) ?? [];
        $savedText = Str::trimToNull(is_string($saved['text'] ?? null) ? $saved['text'] : null);

        if ($savedText === null) {
            return $this->invalidArgs(
                'There is no signature to save.',
                ['saved' => false],
                guidance: 'Ask them what it should say, or preview designs first.',
            );
        }

        return new UserEmailSignature($savedText, html: is_string($saved['html'] ?? null) ? $saved['html'] : null);
    }
}
