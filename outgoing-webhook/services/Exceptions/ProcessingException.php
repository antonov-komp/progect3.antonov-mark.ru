<?php
declare(strict_types=1);

/**
 * Исключение для ошибок обработки событий
 * 
 * Используется при ошибках обработки событий, записи данных
 */
class ProcessingException extends Exception
{
    public function __construct(string $message = '', int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
