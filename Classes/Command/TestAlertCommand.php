<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Command;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use OliverThiele\OtAlerts\Service\AlertManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ot_alerts:test',
    description: 'Send a test alert to verify channel configuration (e.g. Pushover credentials)',
)]
class TestAlertCommand extends Command
{
    public function __construct(private readonly AlertManager $alertManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_OPTIONAL,
                'Alert severity: info, warning, error, critical',
                'info'
            )
            ->addOption(
                'resolve',
                null,
                InputOption::VALUE_NONE,
                'Immediately resolve the test alert after sending (resets rate limit)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $severityValue = is_string($input->getOption('severity')) ? $input->getOption('severity') : 'info';
        $severity = AlertSeverity::tryFrom($severityValue);

        if ($severity === null) {
            $style->error(sprintf(
                'Unknown severity "%s". Valid values: %s',
                $severityValue,
                implode(', ', array_column(AlertSeverity::cases(), 'value'))
            ));
            return Command::FAILURE;
        }

        $pushoverAppToken = $_ENV['PUSHOVER_APP_TOKEN'] ?? '';
        $pushoverUserKey = $_ENV['PUSHOVER_USER_KEY'] ?? '';

        $style->section('ot_alerts — Channel Configuration');
        $style->definitionList(
            ['PUSHOVER_APP_TOKEN' => $pushoverAppToken !== '' ? '✓ set' : '✗ missing'],
            ['PUSHOVER_USER_KEY'  => $pushoverUserKey !== '' ? '✓ set' : '✗ missing'],
        );

        if ($pushoverAppToken === '' || $pushoverUserKey === '') {
            $style->warning('Pushover is not configured — set PUSHOVER_APP_TOKEN and PUSHOVER_USER_KEY in .env');
        }

        $style->section('Sending test alert');

        $alert = new Alert(
            source: 'ot_alerts',
            eventKey: 'test.alert',
            message: sprintf('Test notification sent via CLI (severity: %s)', $severity->value),
            severity: $severity,
        );

        $this->alertManager->notify($alert);

        $style->success(sprintf('Test alert dispatched (severity: %s)', $severity->value));

        if ($input->getOption('resolve')) {
            $this->alertManager->resolve('ot_alerts', 'test.alert');
            $style->note('Test alert resolved — rate limit reset, next test will send again immediately.');
        } else {
            $style->note('Run with --resolve to reset the rate limit so the next test sends immediately.');
        }

        return Command::SUCCESS;
    }
}
