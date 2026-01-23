<?php
declare(strict_types=1);

class QueueJob
{
    private string $path;
    private array $data;
    private bool $valid;

    public function __construct(string $path, array $data, bool $valid = true)
    {
        $this->path = $path;
        $this->data = $data;
        $this->valid = $valid;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return basename($this->path);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }
}
