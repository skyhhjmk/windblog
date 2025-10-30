<?php

return [
    'enable' => (bool) getenv('PUSH_ENABLE'),
    'websocket' => getenv('PUSH_WEBSOCKET') ?: 'websocket://0.0.0.0:3131',
    'api' => (getenv('PUSH_API') ?: 'http://0.0.0.0:3232') . '/pushapi',
    'app_key' => getenv('PUSH_APP_KEY') ?: 'e9d56e94590f9f922259c05c1753e5f8',
    'app_secret' => getenv('PUSH_APP_SECRET') ?: '769a66dccaf5404b196a72bcefc31d48',
    'channel_hook' => getenv('PUSH_CHANNEL_HOOK') ?: 'http://127.0.0.1:8787/plugin/webman/push/hook',
    'auth' => getenv('PUSH_AUTH') ?: '/plugin/webman/push/auth',
];
