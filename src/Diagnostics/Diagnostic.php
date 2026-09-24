<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Diagnostics;

final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
        public ?int $line = null,
        public ?int $column = null,
    ) {}

    /**
     * @return array{code: string, severity: string, message: string, line: int|null, column: int|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    /**
     * @param  array{code: string, severity: string, message: string, line?: int|null, column?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['code'],
            Severity::from($data['severity']),
            $data['message'],
            $data['line'] ?? null,
            $data['column'] ?? null,
        );
    }
}
