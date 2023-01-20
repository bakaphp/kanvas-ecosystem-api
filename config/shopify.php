<?php

return [
    'api_key'=>getenv('SHOPIFY_API_KEY'),
    'api_secret'=>getenv('SHOPIFY_API_SECRET'),
    'access_token'=>getenv('SHOPIFY_ACCESS_TOKEN'),
    'scopes' => explode(',',getenv('SHOPIFY_SCOPES')),
    'hostname' => getenv('SHOPIFY_HOSTNAME'),
    'api_version'=>getenv('SHOPIFY_API_VERSION'),
];