-- ===== DB + USER ======
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS portfolio_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'portfolio_app'@'localhost' IDENTIFIED BY 'CambiaQuestaPassword!';
    GRANT SELECT, INSERT, UPDATE, DELETE
    ON portfolio_db.* TO 'portfolio_app'@'localhost';
FLUSH PRIVILEGES;

USE portfolio_db;

-- ===== TABELLE =====


CREATE TABLE Utente (
  ID_Utente    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  Username     VARCHAR(50)  NOT NULL UNIQUE,
  Email        VARCHAR(254) UNIQUE,
  PasswordHash VARCHAR(255) NOT NULL,
  CreatedAt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE Cartella (
	ID_Cartella  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	ID_Utente    BIGINT UNSIGNED NOT NULL,
	ID_Padre		 BIGINT UNSIGNED     NULL,
	Nome         VARCHAR(100)     NOT NULL,

	FOREIGN KEY (ID_Utente) REFERENCES Utente(ID_Utente),
	FOREIGN KEY (ID_Padre) REFERENCES Cartella(ID_Cartella)
		ON DELETE CASCADE
		ON UPDATE CASCADE,
	UNIQUE (ID_Utente, ID_Padre, Nome)
);

CREATE TABLE Asset (
  ISIN        CHAR(12)     NOT NULL,
  Nome        VARCHAR(120) NULL,
  Valuta      CHAR(3)      NOT NULL DEFAULT 'EUR',
  Tipo        ENUM('ETF','Azione','Obbligazione') NOT NULL,
  Borsa       VARCHAR(10)  NULL,

  PRIMARY KEY (ISIN)
);

CREATE TABLE ETF (
  ISIN        CHAR(12) NOT NULL,
  Ticker      VARCHAR(15) NOT NULL,
  TER         DECIMAL(6,4) NULL,
  Distribuzione ENUM('Accumulating','Distributing') DEFAULT 'Accumulating',
  Indice      VARCHAR(120) NULL,

  PRIMARY KEY (ISIN),
  FOREIGN KEY (ISIN) REFERENCES Asset(ISIN)
);

CREATE TABLE Azione (
  ISIN        CHAR(12) NOT NULL,
  Ticker      VARCHAR(15) NOT NULL,

  PRIMARY KEY (ISIN),
  FOREIGN KEY (ISIN) REFERENCES Asset(ISIN)
);

CREATE TABLE Obbligazione (
  ISIN        CHAR(12)     NOT NULL,
  Scadenza    DATE         NOT NULL DEFAULT '9999-12-31',
  CedolaPct   DECIMAL(7,4) NULL,
  FrequenzaCedola ENUM('Annuale','Semestrale','Triennale','Mensile') NULL,

  PRIMARY KEY (ISIN),
  FOREIGN KEY (ISIN) REFERENCES Asset(ISIN)
);

CREATE TABLE Portafoglio (
  ID_Portafoglio     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	ID_Cartella        BIGINT UNSIGNED  NOT NULL,
  ID_Utente          BIGINT UNSIGNED  NOT NULL,
  Nome               VARCHAR(100)     NULL,
  Valuta             CHAR(3)          NOT NULL DEFAULT 'EUR',
  Liquidita          DECIMAL(14,2)    NOT NULL DEFAULT 0,
  TargetLiquiditaPct DECIMAL(5,3)     NOT NULL DEFAULT 0.000,
  Tolleranza         DECIMAL(5,3)     NOT NULL DEFAULT 5.000,
  Commissione        DECIMAL(8,4)     NULL,
  TipoCommissione ENUM('Fissa','Percentuale') NOT NULL DEFAULT 'Fissa',
  ID_Radice          BIGINT UNSIGNED  NOT NULL UNIQUE,

  FOREIGN KEY (ID_Utente) REFERENCES Utente(ID_Utente),
	FOREIGN KEY (ID_Cartella) REFERENCES Cartella(ID_Cartella)
		ON DELETE RESTRICT
		ON UPDATE CASCADE,

	INDEX idx_portafoglio (ID_Utente, ID_Cartella),
	CHECK (Tolleranza >= 0),
  CHECK (TargetLiquiditaPct BETWEEN 0 AND 100)
);

CREATE TABLE Bucket (
  ID_Bucket     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ID_Padre      BIGINT UNSIGNED NULL,
  Nome          VARCHAR(100)     NOT NULL,
  TargetPctSuPadre DECIMAL(7,4) NULL,

  FOREIGN KEY (ID_Padre) REFERENCES Bucket(ID_Bucket)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  UNIQUE (ID_Padre, Nome),

	INDEX idx_bucket (ID_Padre),

	CHECK (TargetPctSuPadre BETWEEN 0 AND 100)
);

ALTER TABLE Portafoglio
	ADD FOREIGN KEY (ID_Radice) REFERENCES Bucket(ID_Bucket);

CREATE TABLE ContenutoAsset (
  ID_Bucket   BIGINT UNSIGNED NOT NULL,
  ISIN        CHAR(12)        NOT NULL,
  TargetPctNelBucket DECIMAL(7,4) NULL,
  TaxRatePct DECIMAL(5,4) NOT NULL DEFAULT 0.260,

  PRIMARY KEY (ID_Bucket, ISIN),

  FOREIGN KEY (ID_Bucket) REFERENCES Bucket(ID_Bucket)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  FOREIGN KEY (ISIN) REFERENCES Asset(ISIN)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

	INDEX idx_contenuto (ID_Bucket)
);

CREATE TABLE Operazione (
  ID_Operazione  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ID_Bucket      BIGINT UNSIGNED NOT NULL,
  ISIN           CHAR(12)        NOT NULL,
  DataOra        DATETIME        NOT NULL,
  Tipo           ENUM('BUY','SELL') NOT NULL,
  Quantita       DECIMAL(14,6)   NOT NULL,
  PrezzoEseguito DECIMAL(14,6)   NOT NULL,

  FOREIGN KEY (ID_Bucket, ISIN) REFERENCES ContenutoAsset(ID_Bucket, ISIN)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  INDEX idx_operazione (ID_Bucket, ISIN),
	CHECK (Quantita > 0 AND PrezzoEseguito >= 0)
);