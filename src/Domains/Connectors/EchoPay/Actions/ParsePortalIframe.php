<?php

namespace Kanvas\Connectors\EchoPay\Actions;

class ParsePortalIframe
{
    public function __construct(
        protected string $iframeUrl,
    ) {
    }

    public function execute(array $consumerAuthenticationInformation): string
    {
        return '<iframe id="cardinal_collection_iframe" name="collectionIframe"
        height="10" width="10" style="display:
         none;"></iframe>
        <form id="' . $consumerAuthenticationInformation['deviceDataCollectionUrl'] . '" method="POST"
        target="collectionIframe" action="">
        <input id="cardinal_collection_form_input" type="hidden" name="JWT"
        value="' . $consumerAuthenticationInformation['accessToken'] . '">
        </form>';
    }
}
