<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\ApiKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'api-key:revoke',
    description: 'Revokes an API key by its key ID',
)]
final class ApiKeyRevokeCommand extends Command
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key-id', InputArgument::REQUIRED, 'Key ID shown by api-key:list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyId = $input->getArgument('key-id');
        if (!is_string($keyId) || !$this->apiKeyService->revoke($keyId)) {
            $output->writeln('<error>No active API key with this key ID.</error>');
            return ExitCode::DATAERR;
        }

        $output->writeln('Revoked.');

        return ExitCode::OK;
    }
}
