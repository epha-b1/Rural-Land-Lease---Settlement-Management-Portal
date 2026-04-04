<?php
// Logging configuration - structured JSON logs
return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type'        => 'file',
            'path'        => runtime_path() . 'log/',
            'single'      => false,
            'file_size'   => 2097152, // 2MB
            'time_format' => 'c',     // ISO 8601
            'format'      => '[%s][%s] %s',
            'json'        => true,
        ],
    ],
];
