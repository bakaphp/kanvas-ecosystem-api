<?php

return [
    'api_key'=>env('SHOPIFY_API_KEY'),
    'api_secret'=>env('SHOPIFY_API_SECRET'),
    'access_token'=>env('SHOPIFY_ACCESS_TOKEN'),
    'scopes' => explode(',', env('SHOPIFY_SCOPES')),
    'hostname' => env('SHOPIFY_HOSTNAME'),
    'api_version'=>env('SHOPIFY_API_VERSION'),
];
