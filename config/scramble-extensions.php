<?php

return [
    'response' => [
        'enabled' => true,
        'trait' => 'Mitoop\\Http\\RespondsWithJson',
        'model_namespace' => 'App\\Models',
        'column_comments' => true,
        'schema_overrides' => [
            'permission_names' => 'string_list',
        ],
    ],

    'scene_form_request' => [
        'enabled' => true,
    ],

    'filter' => [
        'enabled' => true,
        'pagination' => [
            'page_size_default' => 15,
            'page_size_max' => 100,
        ],
    ],
];
