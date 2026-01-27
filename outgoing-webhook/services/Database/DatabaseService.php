<?php
declare(strict_types=1);

/**
 * Сервис для работы с SQLite базой данных
 * 
 * Ответственность:
 * - Подключение к SQLite через PDO
 * - Настройка WAL режима для лучшей производительности
 * - Управление транзакциями
 * - Обработка ошибок подключения
 * 
 * Документация SQLite: https://www.sqlite.org/docs.html
 */
class DatabaseService
{
    private ?PDO $pdo = null;
    private string $dbPath;
    private ErrorService $errors;
    private bool $walEnabled;

    public function __construct(string $dbPath, ErrorService $errors, bool $walEnabled = true)
    {
        $this->dbPath = $dbPath;
        $this->errors = $errors;
        $this->walEnabled = $walEnabled;
    }

    /**
     * Получить PDO соединение
     * 
     * @return PDO
     * @throws RuntimeException При ошибке подключения
     */
    public function getConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            // Создание директории для БД, если не существует
            $dbDir = dirname($this->dbPath);
            if (!is_dir($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new RuntimeException("Failed to create database directory: {$dbDir}");
                }
            }

            // Подключение к SQLite
            $this->pdo = new PDO(
                'sqlite:' . $this->dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Настройка WAL режима для лучшей производительности
            if ($this->walEnabled) {
                $this->pdo->exec('PRAGMA journal_mode = WAL');
            }

            // Включение внешних ключей
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            // Оптимизация производительности
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = -64000'); // 64MB кеш

            return $this->pdo;
        } catch (PDOException $e) {
            $this->errors->log('Database connection error', [
                'path' => $this->dbPath,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to connect to database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Инициализация схемы БД из SQL файла
     * 
     * @param string $schemaPath Путь к SQL файлу со схемой
     * @return bool Успешность выполнения
     */
    public function initializeSchema(string $schemaPath): bool
    {
        if (!file_exists($schemaPath)) {
            $this->errors->log('Schema file not found', ['path' => $schemaPath]);
            return false;
        }

        try {
            $pdo = $this->getConnection();
            $sql = file_get_contents($schemaPath);
            
            if ($sql === false) {
                $this->errors->log('Failed to read schema file', ['path' => $schemaPath]);
                return false;
            }

            // Выполнение SQL схемы
            $pdo->exec($sql);
            
            return true;
        } catch (PDOException $e) {
            $this->errors->log('Schema initialization error', [
                'path' => $schemaPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Начать транзакцию
     * 
     * @return bool Успешность начала транзакции
     */
    public function beginTransaction(): bool
    {
        try {
            return $this->getConnection()->beginTransaction();
        } catch (PDOException $e) {
            $this->errors->log('Failed to begin transaction', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Подтвердить транзакцию
     * 
     * @return bool Успешность подтверждения
     */
    public function commit(): bool
    {
        try {
            return $this->getConnection()->commit();
        } catch (PDOException $e) {
            $this->errors->log('Failed to commit transaction', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Откатить транзакцию
     * 
     * @return bool Успешность отката
     */
    public function rollback(): bool
    {
        try {
            return $this->getConnection()->rollBack();
        } catch (PDOException $e) {
            $this->errors->log('Failed to rollback transaction', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Выполнить SQL запрос
     * 
     * @param string $sql SQL запрос
     * @param array $params Параметры для prepared statement
     * @return PDOStatement
     * @throws PDOException При ошибке выполнения
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        
        if ($stmt === false) {
            throw new RuntimeException("Failed to prepare SQL statement: {$sql}");
        }

        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Выполнить SQL запрос и получить одну строку
     * 
     * @param string $sql SQL запрос
     * @param array $params Параметры для prepared statement
     * @return array|null Массив данных или null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Выполнить SQL запрос и получить все строки
     * 
     * @param string $sql SQL запрос
     * @param array $params Параметры для prepared statement
     * @return array Массив данных
     */
    public function queryAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetchAll();
        return $result === false ? [] : $result;
    }

    /**
     * Выполнить SQL запрос и получить последний вставленный ID
     * 
     * @param string $sql SQL запрос INSERT
     * @param array $params Параметры для prepared statement
     * @return int|false ID вставленной записи или false при ошибке
     */
    public function insert(string $sql, array $params = [])
    {
        try {
            $this->query($sql, $params);
            return (int) $this->getConnection()->lastInsertId();
        } catch (PDOException $e) {
            $this->errors->log('Failed to insert record', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Получить путь к файлу БД
     * 
     * @return string
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Проверить существование БД
     * 
     * @return bool
     */
    public function exists(): bool
    {
        return file_exists($this->dbPath);
    }
}
