CREATE TABLE accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  iban VARCHAR(34) UNIQUE,
  hesap_no VARCHAR(32),
  sube VARCHAR(16),
  para_birimi CHAR(3) NOT NULL,
  ad VARCHAR(128),
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  banka_ref VARCHAR(128) NOT NULL UNIQUE,
  tarih DATE NOT NULL,
  valor_tarihi DATE NULL,
  tutar DECIMAL(18,2) NOT NULL,
  borc_alacak CHAR(1) NOT NULL,           -- 'D' (borc) / 'C' (alacak)
  para_birimi CHAR(3) NOT NULL,
  aciklama VARCHAR(512),
  karsi_taraf VARCHAR(256),
  bakiye_sonrasi DECIMAL(18,2) NULL,
  ham_json TEXT,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_acc_tarih (account_id, tarih),
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE balances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  tarih DATE NOT NULL,
  acilis_bakiye DECIMAL(18,2) NULL,
  kapanis_bakiye DECIMAL(18,2) NULL,
  para_birimi CHAR(3) NOT NULL,
  guncelleme DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_tarih (account_id, tarih),
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baslangic DATETIME NOT NULL,
  bitis DATETIME NULL,
  durum VARCHAR(16) NOT NULL,             -- 'ok' / 'error'
  cekilen_kayit INT NOT NULL DEFAULT 0,
  mesaj VARCHAR(512)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
