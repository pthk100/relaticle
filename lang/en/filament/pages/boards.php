<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'label' => 'Switch view',
        'list' => 'List',
        'board' => 'Board',
        'cards' => 'Cards',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'koc_caption' => 'KOC collaborations. This board mirrors the platform ladder, and a pitch stays in no response until the creator says yes, so pitches are hidden unless you show them.',
        'card' => [
            'follow_up' => 'follow-up · :date',
            'follow_up_late' => 'follow-up · :daysd late',
        ],
        'column' => [
            'pitch_hidden' => '{1} +:count pitch hidden|[2,*] +:count pitches hidden',
        ],
        'empty' => [
            'title' => 'No collaborations yet.',
            'body' => 'A collaboration appears here when a creator says yes to a pitch. Pitches stay in no response, and switching off pitches hidden shows them.',
            'hidden' => '{1} :count collaboration hidden by the filter|[2,*] :count collaborations hidden by the filter',
        ],
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'filters' => [
            'company' => 'Company',
            'contact' => 'Point of Contact',
        ],
        'form' => [
            'name_placeholder' => 'Enter opportunity title',
        ],
    ],

    'tasks' => [
        'title' => 'Tasks',
        'actions' => [
            'add' => 'Add Task',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'filters' => [
            'assignee' => 'Assignee',
        ],
    ],
];
