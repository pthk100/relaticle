<?php

declare(strict_types=1);

return [
    // Lowercase singular/plural so Filament's "New :label" button reads naturally.
    'label' => 'opportunity',
    'plural_label' => 'opportunities',
    'navigation_label' => 'Opportunities',

    'fields' => [
        'name' => [
            'label' => 'Opportunity',
            'placeholder' => 'Enter opportunity title',
        ],
        'company_id' => [
            'label' => 'Company',
        ],
        'contact_id' => [
            'label' => 'Point of Contact',
        ],
        'creator' => [
            'label' => 'Created By',
        ],
        'creation_source' => [
            'label' => 'Creation Source',
        ],
        'created_at' => [
            'label' => 'Created At',
        ],
        'updated_at' => [
            'label' => 'Updated At',
        ],
        'deleted_at' => [
            'label' => 'Deleted At',
        ],
        'koc_stage' => [
            'provenance' => 'Stage is mirrored from the KOC platform and is not editable here.',
            // The option row's name is the platform's machine vocabulary (the
            // projector writes it and the API resolves it back to an option
            // id), so the label a person reads is mapped here, at render time.
            'options' => [
                'no_response' => 'Pitch sent',
                'sample_pending' => 'Sample pending',
                'shipped' => 'Sample shipped',
                'delivered' => 'Sample delivered',
                'fulfilled' => 'Collaboration fulfilled',
            ],
        ],
    ],

    'filters' => [
        'koc_stage' => 'KOC stage',
        'accepted_only' => 'Accepted collaborations only. Pitches hidden',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Import opportunities',
                ],
                'import_export' => [
                    'label' => 'Import / Export',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Company',
                    ],
                    'contact' => [
                        'label' => 'Point of Contact',
                    ],
                ],
            ],
        ],
    ],
];
