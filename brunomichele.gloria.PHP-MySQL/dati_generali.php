<?php

return [
    'dbms' => 'mariadb', // 'mysql' o 'mariadb', ho messo l'opzione ma init.sql richiede uno dei due.
    'host' => 'localhost',
    'port' => 3306,

    'database' => 'brunomichele.gloria.PHP-MySQL',

    'username' => 'Demo', // Utente per dbms, con privilegi limitati al database specificato
    'password' => 'SamplePassword',

    'demo_user' => [        // Utente dell'applicazione
        'username' => 'demo',
        'email' => 'demo@example.com',
        'password' => 'demo',
    ],

    'charset' => 'utf8mb4',
    'collate' => 'utf8mb4_unicode_ci',

    // File SQL di installazione/inizializzazione
    'init_sql' => __DIR__ . '/init.sql',
    'drop_sql' => __DIR__ . '/drop.sql',

    // Se true, install.php inizializza prima di installare
    'run_drop_on_install' => false,
];