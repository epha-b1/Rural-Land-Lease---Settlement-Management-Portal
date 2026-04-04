<?php
// Global middleware - executed on every request in order
return [
    \app\middleware\TraceId::class,
    \app\middleware\JsonResponse::class,
];
