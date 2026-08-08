<?php
/* @package MEeL\Exceptions */

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

    /* Buat ProcessException dari hasil exec(). */
    public static function fromExec(string $command, int $exitCode, array $output): self
    {
        $lastLines = implode(' | ', array_slice($output, -3));
        return new self(
            "Perintah gagal (exit code $exitCode): $lastLines",
            $command,
            $exitCode,
            implode("\n", $output)
        );
    }

    /* Buat ProcessException dari hasil proc_close(). */
    public static function fromProcClose(string $command, int $exitCode, string $output = ''): self
    {
        $lastLines = implode(' | ', array_slice(explode("\n", $output), -3));
        return new self(
            "Proses gagal (exit code $exitCode): $lastLines",
            $command,
            $exitCode,
            $output
        );
    }
}
