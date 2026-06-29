<?php

return [
    'response' => [
        'enabled' => true,
        'trait' => 'Mitoop\\Http\\RespondsWithJson',
        'model_namespace' => 'App\\Models',
        'column_comments' => true,
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
