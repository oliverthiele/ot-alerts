<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events',
        'label' => 'source',
        'label_alt' => 'event_key',
        'label_alt_force' => true,
        'hideTable' => true,
        'rootLevel' => -1,
        'adminOnly' => true,
        'iconfile' => 'EXT:ot_alerts/Resources/Public/Icons/Extension.svg',
    ],
    'columns' => [
        'source' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.source',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'event_key' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.event_key',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'severity' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.severity',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'status' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.status',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'first_occurrence' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.first_occurrence',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'last_occurrence' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.last_occurrence',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'last_notified' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.last_notified',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'occurrence_count' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.occurrence_count',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'last_message' => [
            'label' => 'LLL:EXT:ot_alerts/Resources/Private/Language/locallang.xlf:tx_otalerts_events.last_message',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'source, event_key, severity, status, first_occurrence, last_occurrence, last_notified, occurrence_count, last_message',
        ],
    ],
];
