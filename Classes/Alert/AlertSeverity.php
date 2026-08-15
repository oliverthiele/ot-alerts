<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Alert;

enum AlertSeverity: string
{
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
}
