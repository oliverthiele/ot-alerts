<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'OT Alerts',
    'description' => 'Alert management for TYPO3 extensions — Pushover notifications with rate limiting',
    'category' => 'misc',
    'state' => 'beta',
    'author' => 'Oliver Thiele',
    'author_email' => 'mail@oliver-thiele.de',
    'author_company' => 'Web Development Oliver Thiele',
    'version' => '0.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'OliverThiele\\OtAlerts\\' => 'Classes',
        ],
    ],
];
