<?php

return [
    'weights' => [
        'agreement' => 0.40,
        'authority' => 0.20,
        'completeness' => 0.25,
        'recency' => 0.15,
    ],

    'threshold' => 70,

    'mock_data_path' => base_path('challenge/mocks/enrichment_responses.json'),

    'generic_email_prefixes' => ['info', 'contact', 'office', 'sales', 'hello', 'support'],

    'provider_authority_weights' => [
        'registry' => 0.90,
        'listing' => 0.50,
        'enrichment' => 0.70,
    ],

    'role_priority' => [
        'AP Manager' => 1,
        'Accounts Payable' => 1,
        'Owner' => 2,
        'Founder' => 2,
        'President' => 2,
        'CFO' => 3,
        'Finance Lead' => 3,
        'Office Manager' => 4,
        'Manager' => 4,
        'Registered Agent' => 5,
    ],
];
