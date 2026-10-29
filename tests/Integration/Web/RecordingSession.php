<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use Yiisoft\Session\NullSession;
use Yiisoft\Session\SessionInterface;

final class RecordingSession implements SessionInterface
{
    public bool $closed = false;
    public bool $discarded = false;
    private NullSession $null;

    public function __construct()
    {
        $this->null = new NullSession();
    }

    public function get(string $key, $default = null)
    {
        return $this->null->get($key, $default);
    }

    public function set(string $key, $value): void {}

    public function close(): void
    {
        $this->closed = true;
    }

    public function open(): void {}

    public function isActive(): bool
    {
        return !$this->closed && !$this->discarded;
    }

    public function getId(): ?string
    {
        return null;
    }

    public function setId(string $sessionId): void {}

    public function regenerateId(): void {}

    public function discard(): void
    {
        $this->discarded = true;
    }

    public function getName(): string
    {
        return $this->null->getName();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [];
    }

    public function remove(string $key): void {}

    public function has(string $key): bool
    {
        return false;
    }

    public function pull(string $key, $default = null)
    {
        return $default;
    }

    public function clear(): void {}

    public function destroy(): void {}

    public function getCookieParameters(): array
    {
        return $this->null->getCookieParameters();
    }
}
