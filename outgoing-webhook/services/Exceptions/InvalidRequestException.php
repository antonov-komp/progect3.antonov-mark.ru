<?php
declare(strict_types=1);

/**
 * Исключение для невалидных HTTP-запросов
 * 
 * Используется при валидации метода, размера payload, структуры данных
 */
class InvalidRequestException extends Exception
{
    public function __construct(string $message = '', int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
