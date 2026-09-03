<?php

interface ProgressObserver
{
    public function onProgress(string $stage, array $data = []): void;
}

final class CallableProgressObserver implements ProgressObserver
{
    
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function onProgress(string $stage, array $data = []): void
    {
        ($this->handler)($stage, $data);
    }
}
