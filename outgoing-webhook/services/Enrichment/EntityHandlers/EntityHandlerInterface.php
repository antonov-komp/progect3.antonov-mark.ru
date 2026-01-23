<?php
declare(strict_types=1);

interface EntityHandlerInterface
{
    public function getEntityType(): string;

    public function buildParams(string $method, ?string $entityId, array $raw): array;

    public function getDicts(array $raw): array;
}
