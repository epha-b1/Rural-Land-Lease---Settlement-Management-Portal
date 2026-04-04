<?php
// Application service providers - bind custom implementations
use app\ExceptionHandle;

return [
    'think\exception\Handle' => ExceptionHandle::class,
];
