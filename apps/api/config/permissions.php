<?php

/*
| Roles and what each can do. A user may only manage users of a lower rank (the Developer manages everyone).
| The Developer account is hidden from every other user.
*/

$all = [
    'dashboard.view',
    'events.view', 'events.manage',
    'microsites.view', 'microsites.manage',
    'applicants.view', 'applicants.manage', 'applicants.export',
    'reports.view',
    'users.view', 'users.manage',
    'audit.view', 'audit.export',
    'settings.manage',
];

return [
    'permissions' => $all,

    'roles' => [
        'developer' => [
            'label' => 'Developer',
            'rank' => 4,
            'permissions' => $all,
        ],
        'super_admin' => [
            'label' => 'Super Admin',
            'rank' => 3,
            'permissions' => $all,
        ],
        'manager' => [
            'label' => 'Manager',
            'rank' => 2,
            'permissions' => [
                'dashboard.view',
                'events.view', 'events.manage',
                'microsites.view', 'microsites.manage',
                'applicants.view', 'applicants.manage', 'applicants.export',
                'reports.view',
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'rank' => 1,
            'permissions' => [
                'dashboard.view', 'events.view', 'microsites.view', 'applicants.view', 'reports.view',
            ],
        ],
    ],
];
