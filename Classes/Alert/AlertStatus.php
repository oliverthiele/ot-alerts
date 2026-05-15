<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Alert;

enum AlertStatus: string
{
    case NEW = 'new';
    case NOTIFIED = 'notified';
    case RESOLVED = 'resolved';
}
