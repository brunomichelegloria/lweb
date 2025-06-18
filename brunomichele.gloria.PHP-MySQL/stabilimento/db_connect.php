<?php
function getDbConnection($ruolo = 'pubblico') {
    $dbHost = 'localhost';
    $dbName = 'stabilimento';

    // Credenziali per ciascun ruolo
    $utenti = [
        'pubblico' => [
            'user' => 'public_web',
            'pass' => 'pubPassword'
        ],
        'cliente' => [
            'user' => 'cliente_web',
            'pass' => 'clientePassword'
        ],
        'admin' => [
            'user' => 'admin_web',
            'pass' => 'adminPassword'
        ]
    ];

    if (!isset($utenti[$ruolo])) {
        die("Ruolo '$ruolo' non valido.");
    }

    $conn = new mysqli($dbHost, $utenti[$ruolo]['user'], $utenti[$ruolo]['pass'], $dbName);

    if ($conn->connect_error) {
        die("Errore connessione DB: " . $conn->connect_error);
    }
    
    return $conn;
}
?>