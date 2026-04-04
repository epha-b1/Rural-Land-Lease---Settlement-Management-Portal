<?php
// Application configuration
return [
    // Application name
    'app_name' => 'Rural Lease Portal',

    // Debug mode - read from env, default off
    'app_debug' => env('APP_DEBUG', false),

    // Default timezone
    'default_timezone' => 'UTC',

    // Default language
    'default_lang' => 'en',

    // Exception handler class
    'exception_handle' => app\ExceptionHandle::class,

    // Show error details
    'show_error_msg' => false,
];
