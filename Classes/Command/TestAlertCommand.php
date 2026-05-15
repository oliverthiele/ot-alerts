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
                'Pre-resolve before sending (bypasses rate limit) and post-resolve after (resets for next test)'
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
        $pushoverUserKey  = $_ENV['PUSHOVER_USER_KEY'] ?? '';

        $style->section('ot_alerts — Channel Configuration');
        $style->definitionList(
            ['PUSHOVER_APP_TOKEN' => $pushoverAppToken !== '' ? '✓ set' : '✗ missing'],
            ['PUSHOVER_USER_KEY'  => $pushoverUserKey !== '' ? '✓ set' : '✗ missing'],
        );

        if ($pushoverAppToken === '' || $pushoverUserKey === '') {
            $style->warning('Pushover is not configured — set PUSHOVER_APP_TOKEN and PUSHOVER_USER_KEY in .env');
        }

        $style->section('Sending test alert');

        // Pre-resolve so the rate limit is bypassed for this run
        if ($input->getOption('resolve')) {
            $this->alertManager->resolve('ot_alerts', 'test.alert');
        }

        $alert = new Alert(
            source: 'ot_alerts',
            eventKey: 'test.alert',
            message: sprintf('Test notification sent via CLI (severity: %s)', $severity->value),
            severity: $severity,
        );

        $result = $this->alertManager->notify($alert);

        if ($result['reason'] === 'rate_limited') {
            $style->warning(
                'Rate limit active — alert was NOT dispatched.' . PHP_EOL .
                'The event is already in the DB with status "notified" and the reminder interval has not elapsed.' . PHP_EOL .
                'Run with --resolve to bypass the rate limit and send immediately.'
            );
            return Command::SUCCESS;
        }

        if ($result['reason'] === 'no_channels') {
            $style->warning('No configured channels found — alert was NOT dispatched.');
            return Command::SUCCESS;
        }

        if ($result['reason'] === 'error') {
            $style->error('An error occurred — check the TYPO3 log for details.');
            return Command::FAILURE;
        }

        // Always show the raw API result — [OK] alone is not enough to confirm delivery
        $this->renderChannelResults($style, $result['channels']);

        if (!$result['sent']) {
            $style->warning('Alert was processed but not sent by any channel.');
        } else {
            $style->success(sprintf('Test alert dispatched (severity: %s)', $severity->value));
        }

        // Post-resolve: reset rate limit so the next test also sends immediately
        if ($input->getOption('resolve') && $result['sent']) {
            $this->alertManager->resolve('ot_alerts', 'test.alert');
            $style->note('Test alert resolved — rate limit reset, next test will send again immediately.');
        } elseif ($result['sent']) {
            $style->note('Run with --resolve to reset the rate limit so the next test sends immediately.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}> $channels
     */
    private function renderChannelResults(SymfonyStyle $style, array $channels): void
    {
        if ($channels === []) {
            return;
        }

        $style->section('API Response');

        foreach ($channels as $channelResult) {
            $header = sprintf('<info>Channel: %s</info>', $channelResult['channel']);

            if (isset($channelResult['httpStatus'])) {
                $header .= sprintf(' — HTTP %d', $channelResult['httpStatus']);
            }

            $style->writeln($header);

            if (isset($channelResult['body'])) {
                $style->writeln(sprintf('  %s', $channelResult['body']));
            }

            if (isset($channelResult['error'])) {
                $style->writeln(sprintf('  <error>Error: %s</error>', $channelResult['error']));
            }

            $style->newLine();
        }
    }
}