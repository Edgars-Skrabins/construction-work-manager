<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\ApiKeyService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'api-key:create',
    description: 'Creates an API key for an external client and shows it once',
)]
final class ApiKeyCreateCommand extends Command
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client', InputArgument::REQUIRED, 'Name of the external client')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiry date (YYYY-MM-DD, 00:00 UTC)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $input->getArgument('client');
        $expires = $input->getOption('expires');

        try {
            $expiresAt = is_string($expires) ? $this->parseDate($expires) : null;
            $issued = $this->apiKeyService->issue(is_string($client) ? $client : '', $expiresAt);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return ExitCode::DATAERR;
        }

        $output->writeln('Client: ' . $issued->apiKey->clientName);
        $output->writeln('Key ID: ' . $issued->apiKey->keyId);
        $expiresAt = $issued->apiKey->expiresAt;
        $output->writeln('Expires: ' . ($expiresAt === null ? 'never' : $expiresAt->format('Y-m-d H:i') . ' UTC'));
        $output->writeln('Key: ' . $issued->plainKey);
        $output->writeln("Store this key now. It can't be shown again.");

        return ExitCode::OK;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The expiry date must be a valid date in the format YYYY-MM-DD.');
        }

        return $date;
    }
}
