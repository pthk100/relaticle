<?php

declare(strict_types=1);

return [
    // A board names itself under the topbar title, which names the resource and
    // reads the same on the list and on the board: without this line the only
    // signal that a reader is on the board view is the view switcher.
    'board_name_default' => ':resource board',

    'view_switcher' => [
        'label' => 'Switch view',
        'list' => 'List',
        'board' => 'Board',
        'cards' => 'Cards',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'board_name' => [
            'ladder' => 'Ladder board',
            'pipeline' => 'Pipeline board',
        ],
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
            // An empty lane says what fills it. Five lanes repeating one sentence
            // tell a reader nothing they can act on, and each rung of the ladder
            // is filled by a known move.
            'lane' => [
                'no_response' => 'A pitch arrives here when it is sent, and stays until the creator answers.',
                'sample_pending' => 'A collaboration arrives here when the creator says yes, and stays until the sample ships.',
                'shipped' => 'A sample on its way to the creator sits here.',
                'delivered' => 'A creator holding the sample sits here until the post goes live.',
                'fulfilled' => 'A collaboration arrives here when its post goes live.',
                'other' => 'A card appears in :lane when it reaches this stage.',
            ],
        ],
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'ladder_board' => 'Ladder board',
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
