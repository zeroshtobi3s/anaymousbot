<?php

declare(strict_types=1);

namespace PhpBot\Database;

use PDO;
use PDOException;
use PhpBot\Config\AppConfig;
use PhpBot\Utils\Logger;
use RuntimeException;

final class Database
{
    private AppConfig $config;
    private Logger $logger;
    private ?PDO $connection = null;

    public function __construct(AppConfig $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function getConnection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        try {
            $this->connection = new PDO(
                $this->config->dbDsn(),
                $this->config->dbUser(),
                $this->config->dbPass(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            $this->logger->error('Database connection failed.', [
                'message' => $exception->getMessage(),
                'host' => $this->config->dbHost(),
                'database' => $this->config->dbName(),
            ]);

            throw new RuntimeException('Database connection failed.');
        }

        return $this->connection;
    }
}

