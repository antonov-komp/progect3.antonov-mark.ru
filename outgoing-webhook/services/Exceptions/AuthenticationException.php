<?php
declare(strict_types=1);

/**
 * Исключение для ошибок аутентификации
 * 
 * Используется при невалидном токене, неразрешенном IP-адресе
 */
class AuthenticationException extends Exception
{
    public function __construct(string $message = '', int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
