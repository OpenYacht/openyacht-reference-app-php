<?php

return [

    'trust_levels' => [
        'verified' => 'Verified',
        'provisional' => 'Provisional',
        'blocked' => 'Blocked',
    ],

    'domain_invalid' => 'Enter a bare hostname such as openyacht.example.com — no scheme or path.',
    'domain_exists' => 'This partner already exists.',
    'partner_added' => ':domain added as a provisional partner.',
    'partner_approved' => ':domain approved.',
    'partner_blocked' => ':domain blocked.',
    'keys_refreshed' => 'Keys refreshed for :domain.',
    'key_repinned' => 'Keys refreshed for :domain — pin moved to the current signing key :key_id.',
    'sync_failed' => 'Sync failed for :domain — see the logs.',
    'directory_refreshed' => 'Node directory refreshed — :count nodes listed.',
    'directory_not_listed' => 'That domain is not an addable node-directory entry.',
    'sync_completed' => ':domain synced: :created new, :updated updated, :tombstoned removed.',

    'attribution_default' => 'Listing courtesy of :name',

    'notifications' => [
        'review_partner' => 'Review the partner',
        'first_contact' => [
            'subject' => 'New OpenYacht node awaiting approval: :domain',
            'intro' => 'The node :domain contacted this node for the first time and was recorded as a provisional partner (trust on first use).',
            'explanation' => 'Provisional partners can deliver signed content but receive no listings until a human approves the partnership.',
        ],
        'uuid_changed' => [
            'subject' => 'OpenYacht partner identity changed: :domain',
            'intro' => 'The node UUID served by :domain changed, meaning the domain now hosts a different installation. The partner was downgraded to provisional and needs re-approval before it is trusted again.',
            'explanation' => 'If you were not expecting this (a migration or reinstall on their side), treat it as a possible domain takeover and contact the partner out of band before re-approving.',
        ],
    ],

];
