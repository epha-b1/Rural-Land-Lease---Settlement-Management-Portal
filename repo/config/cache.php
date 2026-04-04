<?php
// Cache configuration
return [
    'default' => 'file',
    'stores' => [
        'file' => [
            'type'       => 'File',
            'path'       => runtime_path() . 'cache/',
            'expire'     => 0,
            'serialize'  => [],
        ],
    ],
];
