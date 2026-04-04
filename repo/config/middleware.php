<?php
// Middleware configuration (aliases, priorities)
return [
    'alias' => [
        'traceId'   => \app\middleware\TraceId::class,
        'jsonResp'  => \app\middleware\JsonResponse::class,
        'authCheck' => \app\middleware\AuthCheck::class,
    ],
    'priority' => [],
];
