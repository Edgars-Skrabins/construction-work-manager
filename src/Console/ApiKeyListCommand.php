<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\ApiKey;
use App\Service\ApiKeyService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'api-key:list',
    description: 'Lists the API keys without their secrets',
)]
final class ApiKeyListCommand extends Command
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiKeys = $this->apiKeyService->list();
        if ($apiKeys === []) {
            $output->writeln('No API keys.');
            return ExitCode::OK;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $table = new Table($output);
        $table->setHeaders(['Key ID', 'Client', 'Created (UTC)', 'Expires (UTC)', 'Last used (UTC)', 'Revoked (UTC)', 'Status']);
        foreach ($apiKeys as $apiKey) {
            $table->addRow([
                $apiKey->keyId,
                $apiKey->clientName,
                $this->format($apiKey->createdAt),
                $this->format($apiKey->expiresAt),
                $this->format($apiKey->lastUsedAt),
                $this->format($apiKey->revokedAt),
                $this->status($apiKey, $now),
            ]);
        }
        $table->render();

        return ExitCode::OK;
    }

    private function format(?DateTimeImmutable $time): string
    {
        return $time === null ? '-' : $time->format('Y-m-d H:i');
    }

    private function status(ApiKey $apiKey, DateTimeImmutable $now): string
    {
        return match (true) {
            $apiKey->revokedAt !== null => 'revoked',
            !$apiKey->isValidAt($now) => 'expired',
            default => 'active',
        };
    }
}
