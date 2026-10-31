<?php

declare(strict_types=1);

return [
    'admin' => [
        '*',
        'ai.view',
        'ai.manage',
        'ai.test',
        'diagnostic.view',
        'knowledge.bulk_import',
    ],
    'tecnico' => [
        'dashboard.view',
        'profile.edit',
        'profile.view',
        'knowledge.view',
        'solutions.view',
        'solutions.manage',
    ],
    'usuario' => [
        'dashboard.view',
        'profile.edit',
        'profile.view',
    ],
];