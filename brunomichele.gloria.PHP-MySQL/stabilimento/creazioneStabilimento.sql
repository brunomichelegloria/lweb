CREATE DATABASE IF NOT EXISTS stabilimento;
USE stabilimento;

-- Creazione tabelle
CREATE TABLE IF NOT EXISTS Cliente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Necessario id. L'email può essere modificata, il CF è unico ma si possono creare più account (password dimenticata, ecc.)
    email VARCHAR(255) UNIQUE NOT NULL,
    CF CHAR(16) NOT NULL,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    data_registrazione DATE NOT NULL
);

CREATE TABLE IF NOT EXISTS Posizione (
    x INT NOT NULL,
    y INT NOT NULL,
    terreno ENUM('Sabbia', 'Prato', 'Pavimentato') NOT NULL DEFAULT 'Sabbia',
    PRIMARY KEY (x, y)
    -- Ai fini del sito aggiungerei un campo stato:
    -- stato ENUM('Libero', 'Occupato', 'Abbonamento') NOT NULL DEFAULT 'Libero';
    -- ma ai fini dell'esame scelgo la via più complessa tramite JOIN con Acquisto.
);

CREATE TABLE IF NOT EXISTS Ombrellone (
    id INT PRIMARY KEY AUTO_INCREMENT,
    posizione_x INT NOT NULL,
    posizione_y INT NOT NULL,
    FOREIGN KEY (posizione_x, posizione_y) REFERENCES Posizione(x, y)
);

CREATE TABLE IF NOT EXISTS Attrezzatura (
    id INT PRIMARY KEY AUTO_INCREMENT,
    posizione_x INT,
    posizione_y INT,
    tipo ENUM('Sdraio', 'Lettino') NOT NULL DEFAULT 'Lettino',
    FOREIGN KEY (posizione_x, posizione_y) REFERENCES Posizione(x, y)
);

CREATE TABLE IF NOT EXISTS Acquisto (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tipo ENUM('Abbonamento', 'Prenotazione', 'Noleggio') NOT NULL,
    data_inizio DATE NOT NULL,
    data_fine DATE,
    prezzo DECIMAL(10,2) NOT NULL,
    posizione_x INT NOT NULL,
    posizione_y INT NOT NULL,
    id_cliente INT,
    FOREIGN KEY (posizione_x, posizione_y) REFERENCES Posizione(x, y),
    FOREIGN KEY (id_cliente) REFERENCES Cliente(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS Contiene_Attrezzatura (
    id_acquisto INT,
    id_attrezzatura INT,
    PRIMARY KEY (id_acquisto, id_attrezzatura),
    FOREIGN KEY (id_acquisto) REFERENCES Acquisto(id) ON DELETE CASCADE,
    FOREIGN KEY (id_attrezzatura) REFERENCES Attrezzatura(id)
);

CREATE TABLE IF NOT EXISTS Articolo (
    nome VARCHAR(50) PRIMARY KEY,
    prezzo DECIMAL(6,2) NOT NULL
);

CREATE TABLE IF NOT EXISTS Amministratore (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL
);



-- Inserimento dati di esempio
INSERT IGNORE INTO Cliente (email, CF, nome, cognome, password, telefono, data_registrazione) VALUES
-- password: pass1234
('luca.rossi@example.com', 'RSSLCU99A01H501U', 'Luca', 'Rossi', '03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4', '3456789012', '2025-05-01'),
-- password: securePass!
('maria.verdi@example.org', 'VRDMRA88B11F205Z', 'Maria', 'Verdi', 'b5a6885b9ff3f6d33b8e3dd9db6e49b7a2b82c688718139ed5212e5a17bffb6e', '3284567890', '2025-05-03'),
-- password: gianniPass99
('gianni.bianchi@example.net', 'BNCGNN77C22G273T', 'Gianni', 'Bianchi', '31b9286c4c97f00907a3dce8ef8e89deed46ee6c7b0f7a75039a142622735e7b', '3331122334', '2025-06-01'),
-- password: aNnA2025
('anna.neri@example.it', 'NRINNA66D33H501R', 'Anna', 'Neri', '189202e3bd83e961bb3084e80b3852e538f89e6f4cb3f58b8ab0c4dd9f5de769', '3665544332', '2025-06-10'),
-- password: carlo!pass
('carlo.romano@example.com', 'RMNCRL55E44L219K', 'Carlo', 'Romano', '2e7279a36e1210cddf683f13b5b6cc54b374ec1b6e3d38b4a4a8e91a7593d3e9', '3479988776', '2025-06-14');

INSERT IGNORE INTO Posizione (x, y) VALUES
(-10, 0), (-9, 0), (-8, 0), (-7, 0), (-6, 0), (-5, 0), (-4, 0), (-3, 0), (-2, 0), (-1, 0),
(0, 0), (1, 0), (2, 0), (3, 0), (4, 0), (5, 0), (6, 0), (7, 0), (8, 0), (9, 0), (10, 0),
(-10, 1), (-9, 1), (-8, 1), (-7, 1), (-6, 1), (-5, 1), (-4, 1), (-3, 1), (-2, 1), (-1, 1),
(0, 1), (1, 1), (2, 1), (3, 1), (4, 1), (5, 1), (6, 1), (7, 1), (8, 1), (9, 1), (10, 1),
(-8, 2), (-7, 2), (-6, 2), (-5, 2), (-4, 2), (-3, 2), (-2, 2), (-1, 2),
(0, 2), (1, 2), (2, 2), (3, 2), (4, 2), (5, 2), (6, 2), (7, 2), (8, 2);

INSERT IGNORE INTO Posizione (x, y, terreno) VALUES
(-10, 2, 'Prato'), (-9, 2, 'Prato'), (9, 2, 'Prato'), (10, 2, 'Prato');

INSERT IGNORE INTO Ombrellone (posizione_x, posizione_y) VALUES
(-3, 0), (-2, 0), (-1, 0), (1, 0), (2, 0), (3, 0), 
(-10, 1), (-9, 1), (-8, 1), (-7, 1), (-6, 1), (-5, 1), (-4, 1), (-3, 1), (-2, 1), (-1, 1),
(1, 1), (2, 1), (3, 1), (4, 1), (5, 1), (6, 1), (7, 1), (8, 1), (9, 1), (10, 1),
(-10, 2), (-9, 2), (-8, 2), (-7, 2), (-6, 2), (-5, 2), (-4, 2), (-3, 2), (-2, 2), (-1, 2),
(1, 2), (2, 2), (3, 2), (4, 2), (5, 2), (6, 2), (7, 2), (8, 2), (9, 2), (10, 2);

INSERT IGNORE INTO Attrezzatura (posizione_x, posizione_y, tipo) VALUES
(-3, 0, 'Lettino'), (-3, 0, 'Lettino'),
(-2, 0, 'Lettino'), (-2, 0, 'Lettino'),
(-1, 0, 'Lettino'), (-1, 0, 'Lettino'),
(1, 0, 'Lettino'), (1, 0, 'Lettino'),
(2, 0, 'Lettino'), (2, 0, 'Lettino'),
(3, 0, 'Lettino'), (3, 0, 'Lettino'),

(-10, 1, 'Lettino'), (-10, 1, 'Lettino'),
(-9, 1, 'Lettino'), (-9, 1, 'Lettino'),
(-8, 1, 'Lettino'), (-8, 1, 'Lettino'),
(-7, 1, 'Lettino'), (-7, 1, 'Lettino'),
(-6, 1, 'Lettino'), (-6, 1, 'Lettino'),
(-5, 1, 'Lettino'), (-5, 1, 'Lettino'),
(-4, 1, 'Lettino'), (-4, 1, 'Lettino'),
(-3, 1, 'Lettino'), (-3, 1, 'Lettino'),
(-2, 1, 'Lettino'), (-2, 1, 'Lettino'),
(-1, 1, 'Lettino'), (-1, 1, 'Lettino'),
(1, 1, 'Lettino'), (1, 1, 'Lettino'),
(2, 1, 'Lettino'), (2, 1, 'Lettino'),
(3, 1, 'Lettino'), (3, 1, 'Lettino'),
(4, 1, 'Lettino'), (4, 1, 'Lettino'),
(5, 1, 'Lettino'), (5, 1, 'Lettino'),
(6, 1, 'Lettino'), (6, 1, 'Lettino'),
(7, 1, 'Lettino'), (7, 1, 'Lettino'),
(8, 1, 'Lettino'), (8, 1, 'Lettino'),
(9, 1, 'Lettino'), (9, 1, 'Lettino'),
(10, 1, 'Lettino'), (10, 1, 'Lettino'),

(-10, 2, 'Lettino'), (-10, 2, 'Lettino'),
(-9, 2, 'Lettino'), (-9, 2, 'Lettino'),
(-8, 2, 'Lettino'), (-8, 2, 'Lettino'),
(-7, 2, 'Lettino'), (-7, 2, 'Lettino'),
(-6, 2, 'Lettino'), (-6, 2, 'Lettino'),
(-5, 2, 'Lettino'), (-5, 2, 'Lettino'),
(-4, 2, 'Lettino'), (-4, 2, 'Lettino'),
(-3, 2, 'Lettino'), (-3, 2, 'Lettino'),
(-2, 2, 'Lettino'), (-2, 2, 'Lettino'),
(-1, 2, 'Lettino'), (-1, 2, 'Lettino'),
(1, 2, 'Lettino'), (1, 2, 'Lettino'),
(2, 2, 'Lettino'), (2, 2, 'Lettino'),
(3, 2, 'Lettino'), (3, 2, 'Lettino'),
(4, 2, 'Lettino'), (4, 2, 'Lettino'),
(5, 2, 'Lettino'), (5, 2, 'Lettino'),
(6, 2, 'Lettino'), (6, 2, 'Lettino'),
(7, 2, 'Lettino'), (7, 2, 'Lettino'),
(8, 2, 'Lettino'), (8, 2, 'Lettino'),
(9, 2, 'Lettino'), (9, 2, 'Lettino'),
(10, 2, 'Lettino'), (10, 2, 'Lettino');

INSERT IGNORE INTO Articolo (nome, prezzo) VALUES
('Ombrellone', 15),
('Lettino', 7),
('Sdraio', 6),
('Abbonamento1mesi', 200),
('Abbonamento2mesi', 350),
('Abbonamento3mesi', 500);


-- PUBLIC
CREATE USER IF NOT EXISTS 'public_web'@'localhost' IDENTIFIED BY 'pubPassword';
GRANT SELECT ON stabilimento.Posizione TO 'public_web'@'localhost';
GRANT SELECT ON stabilimento.Ombrellone TO 'public_web'@'localhost';
GRANT SELECT ON stabilimento.Acquisto TO 'public_web'@'localhost';
GRANT SELECT ON stabilimento.Attrezzatura TO 'public_web'@'localhost';
GRANT SELECT ON stabilimento.Articolo TO 'public_web'@'localhost';
GRANT INSERT ON stabilimento.Cliente TO 'public_web'@'localhost';

-- CLIENTE
CREATE USER IF NOT EXISTS 'cliente_web'@'localhost' IDENTIFIED BY 'clientePassword';
GRANT SELECT ON stabilimento.* TO 'cliente_web'@'localhost';
GRANT INSERT ON stabilimento.Acquisto TO 'cliente_web'@'localhost';
GRANT INSERT ON stabilimento.Contiene_Attrezzatura TO 'cliente_web'@'localhost';

-- ADMIN
CREATE USER IF NOT EXISTS 'admin_web'@'localhost' IDENTIFIED BY 'adminPassword';
GRANT ALL PRIVILEGES ON stabilimento.* TO 'admin_web'@'localhost';

FLUSH PRIVILEGES;