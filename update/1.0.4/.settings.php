<?php
return [
    'ui.entity-selector' => [
        'value' => [
            'entities' => [
                [
                    'entityId' => 'awzallxls-user',
                    'provider' => [
                        'moduleId' => 'awz.allxls',
                        'className' => '\\Awz\\Allxls\\Access\\EntitySelectors\\User'
                    ],
                ],
                [
                    'entityId' => 'awzallxls-group',
                    'provider' => [
                        'moduleId' => 'awz.allxls',
                        'className' => '\\Awz\\Allxls\\Access\\EntitySelectors\\Group'
                    ],
                ],
            ]
        ],
        'readonly' => true,
    ],
    'services' => [
        'value' => [
            'awz.allxls.iblock' => [
                'className' => '\Awz\Allxls\Services\iblock',
            ],
            'awz.allxls.iblockexport' => [
                'className' => '\Awz\Allxls\Services\iblockexport',
            ],
        ],
        'readonly' => true,
    ],
    'controllers' => [
        'value' => [
            'namespaces' => [
                '\\Awz\\Allxls\\Api' => 'api'
            ]
        ],
        'readonly' => true
    ]
];