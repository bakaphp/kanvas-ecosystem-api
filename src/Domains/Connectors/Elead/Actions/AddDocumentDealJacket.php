<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;

class AddDocumentDealJacket
{
    public function __construct(
        protected Apps $app,
        protected Filesystem $filesystem,
        protected Lead $lead
    ) {
    }

    public function execute(): bool
    {
        $participants = $this->lead->participants;
        $signatories = [];
        $eSignBlocks = [];
        foreach ($participants as $participant) {
            $people = $participant->people;
            $email = $people->getEmails()->first()->value;
            $phone = $people->getPhones()->first()->value;
            $signatories[] = [
                'firstName' => $people->firstName,
                'lastName' => $people->lastName,
                'role' => 'Buyer', // @todo: we need create a way to determinate the role of participant,
                'textMsgPhone' => $phone,
                'email' => $email,
            ];
            // @todo: we need create a way for determinate the blocks positions
            $eSignBlocks[] = [
                'role' => 'BUYER',
                'xLocation' => 116,
                'width' => 448,
                'page' => 1,
                'yLocation' => 614,
                'height' => 82,
                'type' => 'SIGNATURE',
            ];
        }
        $body = [
            'signatories' => $signatories,
            'createDealJacket' => true,
            'document' => [
                'contents' => file_get_contents($this->filesystem->url),
                'vaultDocId' => $this->filesystem->uuid,
                'documentName' => $this->filesystem->name,
                'replaceIfDuplicate' => true,
                'category' => 'Lease Agreement',
                'eSignBlocks' => $eSignBlocks,
            ],
            'source' => $this->lead->company->name,
        ];
        $client = new Client($this->app, $this->lead->company);
        $dealId = $this->lead->id; // @todo: what is dealId
        $client->post("/cdk/sales/post-doc/v1/deal-jackets/{{$dealId}}/documents", $body);

        return true;
    }
}
