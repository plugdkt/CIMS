<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // MEDSCI ACC delegated SSO (spec sso_integration_guide.md). Not yet registered as a real
    // client with the faculty's SSO admin — see CLAUDE.md "Known open items".
    'sso' => [
        'client_id' => env('SSO_CLIENT_ID'),
        'client_secret' => env('SSO_CLIENT_SECRET'),
        'login_url' => env('SSO_LOGIN_URL', 'https://www.medsci.up.ac.th/msc_acc/sso/login.php'),
        'verify_url' => env('SSO_VERIFY_URL', 'https://www.medsci.up.ac.th/msc_acc/api/verify.php'),
        'logout_url' => env('SSO_LOGOUT_URL', 'https://www.medsci.up.ac.th/msc_acc/sso/logout.php'),
        'callback_url' => env('SSO_CALLBACK_URL'),
        'ca_bundle' => env('SSO_VERIFY_SSL') === false || env('SSO_VERIFY_SSL') === 'false'
            ? false
            : env('SSO_CA_BUNDLE', file_exists(storage_path('certs/cacert.pem')) ? storage_path('certs/cacert.pem') : null),
    ],

    // PubChem (NIH) public compound lookup — no API key. Used for chemical auto-fill
    // (item create form) and the standalone procurement lookup page.
    'pubchem' => [
        'pug_base_url' => env('PUBCHEM_PUG_BASE_URL', 'https://pubchem.ncbi.nlm.nih.gov/rest/pug'),
        'pug_view_base_url' => env('PUBCHEM_PUG_VIEW_BASE_URL', 'https://pubchem.ncbi.nlm.nih.gov/rest/pug_view'),
        'timeout_seconds' => (int) env('PUBCHEM_TIMEOUT_SECONDS', 10),
        'ca_bundle' => env('PUBCHEM_VERIFY_SSL') === false || env('PUBCHEM_VERIFY_SSL') === 'false'
            ? false
            : env('PUBCHEM_CA_BUNDLE', file_exists(storage_path('certs/cacert.pem')) ? storage_path('certs/cacert.pem') : null),
    ],

    // KKU GenAI Gateway (OpenAI-compatible) for chemical specifications
    'ai_gateway' => [
        'base_url' => env('AI_GATEWAY_BASE_URL', 'https://gen.ai.kku.ac.th/upacth/api/v1'),
        'api_key' => env('AI_GATEWAY_API_KEY'),
        'model' => env('AI_GATEWAY_MODEL', 'gemini-2.5-flash-lite'),
        'timeout_seconds' => (int) env('AI_GATEWAY_TIMEOUT_SECONDS', 30),
        'ca_bundle' => env('AI_GATEWAY_VERIFY_SSL') === false || env('AI_GATEWAY_VERIFY_SSL') === 'false'
            ? false
            : env('AI_GATEWAY_CA_BUNDLE', file_exists(storage_path('certs/cacert.pem')) ? storage_path('certs/cacert.pem') : null),
    ],

];
