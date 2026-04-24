<?php

function getDbConfig(): array {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = require __DIR__ . '/../dati_generali.php';
    return $config;
}

function buildDsn(array $config, bool $withDatabase = true): string {
    $dbms = strtolower((string)($config['dbms'] ?? 'mysql'));
    $host = (string)($config['host'] ?? 'localhost');
    $port = (int)($config['port'] ?? 3306);
    $charset = (string)($config['charset'] ?? 'utf8mb4');
    $database = (string)($config['database'] ?? '');

    if ($dbms === 'mariadb') {
        $dbms = 'mysql';
    }

    if ($dbms === 'mysql') {
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if ($withDatabase && $database !== '') {
            $dsn .= ";dbname={$database}";
        }
        return $dsn;
    }

    throw new RuntimeException("DBMS non supportato: {$dbms}");
}

function getPDO(bool $withDatabase = true, bool $admin = false, ?string $overrideUser = null, ?string $overridePass = null): PDO {
    static $pdoApp = null;

    $config = getDbConfig();

    if ($admin) {
        return new PDO(
            buildDsn($config, $withDatabase),
            $overrideUser ?? (string)$config['admin_username'],
            $overridePass ?? (string)$config['admin_password'],
            getPdoOptions()
        );
    }

    if ($withDatabase && $pdoApp instanceof PDO) {
        return $pdoApp;
    }

    $pdo = new PDO(
        buildDsn($config, $withDatabase),
        (string)$config['username'],
        (string)$config['password'],
        getPdoOptions()
    );

    if ($withDatabase) {
        $pdoApp = $pdo;
    }

    return $pdo;
}

function getPdoOptions(): array {
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}