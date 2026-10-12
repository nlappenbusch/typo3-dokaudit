<?php

use DokAudit\Typo3\Controller\ModuleController;

return [
    'file_dokaudit' => [
        'parent' => 'file',
        'access' => 'admin',
        'path' => '/module/file/dokaudit',
        'iconIdentifier' => 'dokaudit-module',
        'labels' => 'LLL:EXT:dokaudit/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ModuleController::class . '::handleRequest',
                'methods' => ['GET', 'POST'],
            ],
        ],
    ],
];
