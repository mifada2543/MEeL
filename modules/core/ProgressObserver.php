<?php
/* @package MEeL\Core */

interface ProgressObserver
{
    /* @param string $stage Nama stage/event (lihat daftar di docblock class); @param array<string, mixed> $data Payload event */
    public function onProgress(string $stage, array $data = []): void;
}

/* @package MEeL\Core */
final class CallableProgressObserver implements ProgressObserver
{
    /** @var callable(string $stage, array $data): void */
    private $handler;

    /* @param callable(string $stage, array $data): void $handler */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /** {@inheritDoc} */
    public function onProgress(string $stage, array $data = []): void
    {
        ($this->handler)($stage, $data);
    }
}
