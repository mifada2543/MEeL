<?php


class TranscodeException extends \RuntimeException
{
    private string $input;
    private ?string $output;
    private ?string $ffmpegLog;

    public function __construct(
        string $message,
        string $input = '',
        ?string $output = null,
        ?string $ffmpegLog = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->input    = $input;
        $this->output   = $output;
        $this->ffmpegLog = $ffmpegLog;
    }

    public function getInput(): string { return $this->input; }
    public function getOutput(): ?string { return $this->output; }
    public function getFfmpegLog(): ?string { return $this->ffmpegLog; }
}
