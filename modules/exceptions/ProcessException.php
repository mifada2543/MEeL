<?php


class ProcessException extends \RuntimeException
{
    private string $command;
    private int $exitCode;
    private ?string $output;

    public function __construct(
        string $message,
        string $command = '',
        int $exitCode = -1,
        ?string $output = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->command  = $command;
        $this->exitCode = $exitCode;
        $this->output   = $output;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }
}
