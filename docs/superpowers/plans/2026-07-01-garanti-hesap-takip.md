# Garanti Hesap Takip Uygulaması Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Garanti BBVA Electronic Bank Statement REST API'lerinden (Account Information + Account Transactions) hesap bakiyelerini ve hareketlerini çekip MySQL'e yazan ve login korumalı bir web dashboard'unda gösteren PHP uygulaması kurmak.

**Architecture:** Cron ile çalışan bir SyncJob, OAuth2 (client_credentials) token'ı ile Garanti API'sini çağırır; ham JSON `GarantiClient` içinde tek noktada normalize edilir; `TransactionRepository` idempotent (çift kayıtsız) yazar; dashboard her zaman DB'den okur. API'nin gerçek yanıt şeması netleşene kadar alan eşlemesi `config/field_map.php`'de izole tutulur.

**Tech Stack:** PHP 7.4, MySQL (üretim) / PDO SQLite in-memory (testler), PHPUnit 9, cURL, Chart.js (dashboard grafikleri), vanilla JS.

## Global Constraints

- PHP sürümü: **7.4** (typed properties var; constructor promotion / union types YOK — kullanma).
- Gizli bilgiler (`client_secret`, `consentId`, DB şifresi) `garanti/config/config.php` içinde; bu dosya **`.gitignore`**'da, repoya asla girmez. Sadece `config.example.php` commit edilir.
- Token endpoint: `https://apis.garantibbva.com.tr/auth/oauth/v2/token`
- OAuth: `grant_type=client_credentials`, `client_id`, `client_secret`, `redirect_uri`.
- Callback URL: `https://partner.trek-turkey.com/garanti/callback.php`
- Deploy hedefi: `partner.trek-turkey.com` → `/garanti/` (FTP, CLAUDE.md kurallarına göre).
- Idempotency anahtarı: `transactions.banka_ref` üzerinde UNIQUE index.
- Token/secret asla loglanmaz.
- Para birimleri: TL/USD/EUR (çoklu döviz).

## File Structure

```
garanti/
  composer.json                 # phpunit bağımlılığı
  phpunit.xml                   # test konfigürasyonu
  .gitignore                    # config.php, vendor/
  config/
    config.example.php          # commit edilir (secret YOK)
    config.php                  # gitignore (gerçek secret)
    field_map.php               # Account Transactions JSON alan eşlemesi (izole)
  sql/
    schema.sql                  # MySQL şeması
  src/
    Http/HttpClient.php         # interface (test için mock'lanabilir)
    Http/CurlHttpClient.php     # gerçek cURL implementasyonu
    Auth/TokenManager.php       # OAuth2 token al/cache/yenile
    Api/GarantiClient.php       # API çağrısı + normalize (field_map kullanır)
    Db/Database.php             # PDO fabrikası
    Db/AccountRepository.php    # hesap upsert/okuma
    Db/TransactionRepository.php# idempotent hareket + bakiye yazımı
    Sync/SyncJob.php            # token+client+repo'yu birleştiren akış
    Auth/Session.php            # dashboard login/oturum
  bin/
    sync.php                    # cron giriş noktası
  public/
    login.php  logout.php  index.php  export.php  callback.php
    assets/app.css  assets/app.js
  tests/
    fixtures/account_transactions.json
    HttpFake.php                # HttpClient sahtesi
    TokenManagerTest.php
    GarantiClientTest.php
    TransactionRepositoryTest.php
    SyncJobTest.php
```

## Dış Önkoşullar (kullanıcı tarafında — kodla paralel yürür)

1. Portal'da Application oluştur → **Client ID + Secret** al.
2. EHÖ ile hesapları onayla → **consentId** al.
3. **Account Transactions örnek JSON yanıtı** paylaş → Task 4'teki `field_map.php` ve fixture gerçek değerlerle güncellenir.

Bu üçü gelmeden Task 1–3, 6b (login), dashboard iskeleti yapılabilir. Task 4 fixture ile yazılır, gerçek örnek gelince fixture + field_map güncellenip testler tekrar koşulur.

---

### Task 1: Proje iskeleti, config ve test altyapısı

**Files:**
- Create: `garanti/composer.json`, `garanti/phpunit.xml`, `garanti/.gitignore`
- Create: `garanti/config/config.example.php`, `garanti/config/config.php`
- Create: `garanti/tests/HttpFake.php`

**Interfaces:**
- Produces: `config()` global fonksiyonu → `array` (config değerleri); `tests\HttpFake` sınıfı (HttpClient sahtesi, Task 3'te kullanılır).

- [ ] **Step 1: composer.json oluştur**

```json
{
    "name": "trek/garanti-hesap-takip",
    "description": "Garanti BBVA hesap hareketleri takip uygulamasi",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.5"
    },
    "autoload": {
        "psr-4": { "Garanti\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Garanti\\Tests\\": "tests/" }
    }
}
```

- [ ] **Step 2: phpunit.xml oluştur**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: .gitignore oluştur**

```
/vendor/
/config/config.php
```

- [ ] **Step 4: config.example.php oluştur (commit edilir, secret YOK)**

```php
<?php
// Gercek degerleri config.php'ye kopyalayin (config.php gitignore'da).
return [
    'oauth' => [
        'token_url'     => 'https://apis.garantibbva.com.tr/auth/oauth/v2/token',
        'client_id'     => 'BURAYA_CLIENT_ID',
        'client_secret' => 'BURAYA_CLIENT_SECRET',
        'redirect_uri'  => 'https://partner.trek-turkey.com/garanti/callback.php',
    ],
    'api' => [
        'base_url'    => 'https://apis.garantibbva.com.tr',
        'consent_id'  => 'BURAYA_CONSENT_ID',
    ],
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=garanti;charset=utf8mb4',
        'user' => 'db_user',
        'pass' => 'db_pass',
    ],
    'dashboard' => [
        // Sifre hash'i: php -r "echo password_hash('sifreniz', PASSWORD_DEFAULT);"
        'username'      => 'admin',
        'password_hash' => 'BURAYA_HASH',
    ],
    'sync' => [
        'lookback_days' => 7,
    ],
];
```

- [ ] **Step 5: config.php oluştur (yerelde çalışmak için; gerçek secret'lar sunucuda)**

`config.example.php`'yi kopyalayıp `config.php` yap; placeholder değerlerle bırak (gerçek secret'lar deploy'da girilecek). Bu dosya commit edilmeyecek.

- [ ] **Step 6: config() yükleyicisini bir bootstrap ile sağla**

`garanti/config/load.php` oluştur:

```php
<?php
function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        $cfg = file_exists($path) ? require $path : require __DIR__ . '/config.example.php';
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}
```

- [ ] **Step 7: tests/HttpFake.php oluştur (Task 3–5 testleri için)**

```php
<?php
namespace Garanti\Tests;

use Garanti\Http\HttpClient;

class HttpFake implements HttpClient
{
    /** @var array<int,array> */
    public array $calls = [];
    /** @var array<int,array{status:int,body:string}> */
    private array $queue = [];

    public function push(int $status, string $body): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->queue) ?? ['status' => 500, 'body' => ''];
    }
}
```

- [ ] **Step 8: Bağımlılıkları kur ve boş test suite'i çalıştır**

Run: `cd garanti && composer install && vendor/bin/phpunit`
Expected: "No tests executed" veya 0 test — hata olmadan çalışır.

- [ ] **Step 9: Commit**

```bash
git add garanti/composer.json garanti/phpunit.xml garanti/.gitignore garanti/config/config.example.php garanti/config/load.php garanti/tests/HttpFake.php
git commit -m "feat(garanti): proje iskeleti, config ve test altyapisi"
```

---

### Task 2: Veritabanı şeması ve Database fabrikası

**Files:**
- Create: `garanti/sql/schema.sql`
- Create: `garanti/src/Db/Database.php`

**Interfaces:**
- Produces: `Garanti\Db\Database::connect(array $cfg): \PDO` — verilen db config'ten PDO döndürür. Testlerde `['dsn' => 'sqlite::memory:']` ile çağrılabilir.

- [ ] **Step 1: schema.sql oluştur (MySQL üretim şeması)**

```sql
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
```

- [ ] **Step 2: Database.php oluştur**

```php
<?php
namespace Garanti\Db;

class Database
{
    public static function connect(array $cfg): \PDO
    {
        $pdo = new \PDO(
            $cfg['dsn'],
            $cfg['user'] ?? null,
            $cfg['pass'] ?? null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    }

    /** Testler icin: SQLite in-memory sema (MySQL semasinin sadelestirilmis hali). */
    public static function migrateSqlite(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT,
            iban TEXT UNIQUE, hesap_no TEXT, sube TEXT, para_birimi TEXT NOT NULL,
            ad TEXT, aktif INTEGER NOT NULL DEFAULT 1, olusturma TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL, banka_ref TEXT NOT NULL UNIQUE, tarih TEXT NOT NULL,
            valor_tarihi TEXT, tutar REAL NOT NULL, borc_alacak TEXT NOT NULL,
            para_birimi TEXT NOT NULL, aciklama TEXT, karsi_taraf TEXT,
            bakiye_sonrasi REAL, ham_json TEXT, olusturma TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE balances (id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL, tarih TEXT NOT NULL, acilis_bakiye REAL,
            kapanis_bakiye REAL, para_birimi TEXT NOT NULL, guncelleme TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(account_id, tarih))");
        $pdo->exec("CREATE TABLE sync_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            baslangic TEXT NOT NULL, bitis TEXT, durum TEXT NOT NULL,
            cekilen_kayit INTEGER NOT NULL DEFAULT 0, mesaj TEXT)");
    }
}
```

- [ ] **Step 3: Sağlık testi yaz — SQLite migrasyonu çalışıyor**

`tests/DatabaseTest.php`:

```php
<?php
namespace Garanti\Tests;

use Garanti\Db\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function test_sqlite_migrate_creates_tables(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        Database::migrateSqlite($pdo);
        $count = $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        $this->assertSame(0, (int)$count);
    }
}
```

- [ ] **Step 4: Testi çalıştır**

Run: `vendor/bin/phpunit tests/DatabaseTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add garanti/sql/schema.sql garanti/src/Db/Database.php garanti/tests/DatabaseTest.php
git commit -m "feat(garanti): veritabani semasi ve PDO fabrikasi"
```

---

### Task 3: HttpClient arayüzü + TokenManager (OAuth2)

**Files:**
- Create: `garanti/src/Http/HttpClient.php`, `garanti/src/Http/CurlHttpClient.php`
- Create: `garanti/src/Auth/TokenManager.php`
- Test: `garanti/tests/TokenManagerTest.php`

**Interfaces:**
- Produces: `Garanti\Http\HttpClient::request(string $method, string $url, array $headers = [], ?string $body = null): array` → `['status' => int, 'body' => string]`.
- Produces: `Garanti\Auth\TokenManager::__construct(HttpClient $http, array $oauthCfg)`; `getToken(): string` (geçerli access token, gerekiyorsa yeniler).

- [ ] **Step 1: HttpClient arayüzünü yaz**

```php
<?php
namespace Garanti\Http;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
```

- [ ] **Step 2: Başarısızlık testini yaz (token alımı)**

`tests/TokenManagerTest.php`:

```php
<?php
namespace Garanti\Tests;

use Garanti\Auth\TokenManager;
use PHPUnit\Framework\TestCase;

class TokenManagerTest extends TestCase
{
    private array $cfg = [
        'token_url' => 'https://apis.garantibbva.com.tr/auth/oauth/v2/token',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'redirect_uri' => 'https://x/callback.php',
    ];

    public function test_fetches_token_and_sends_client_credentials(): void
    {
        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $tm = new TokenManager($http, $this->cfg);

        $this->assertSame('AAA', $tm->getToken());
        $sent = $http->calls[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertStringContainsString('grant_type=client_credentials', $sent['body']);
        $this->assertStringContainsString('client_id=cid', $sent['body']);
    }

    public function test_caches_token_within_expiry(): void
    {
        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $tm = new TokenManager($http, $this->cfg);
        $tm->getToken();
        $tm->getToken();
        $this->assertCount(1, $http->calls); // ikinci cagri cache'ten
    }
}
```

- [ ] **Step 3: Testi çalıştır — başarısız olduğunu gör**

Run: `vendor/bin/phpunit tests/TokenManagerTest.php`
Expected: FAIL ("Class TokenManager not found").

- [ ] **Step 4: TokenManager'ı yaz**

```php
<?php
namespace Garanti\Auth;

use Garanti\Http\HttpClient;

class TokenManager
{
    private HttpClient $http;
    private array $cfg;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(HttpClient $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg = $cfg;
    }

    public function getToken(): string
    {
        if ($this->token !== null && time() < $this->expiresAt - 30) {
            return $this->token;
        }
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'redirect_uri'  => $this->cfg['redirect_uri'],
        ]);
        $res = $this->http->request('POST', $this->cfg['token_url'],
            ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Token alinamadi: HTTP ' . $res['status']);
        }
        $data = json_decode($res['body'], true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Token yaniti gecersiz');
        }
        $this->token = $data['access_token'];
        $this->expiresAt = time() + (int)($data['expires_in'] ?? 3600);
        return $this->token;
    }
}
```

- [ ] **Step 5: Testi çalıştır — geçtiğini gör**

Run: `vendor/bin/phpunit tests/TokenManagerTest.php`
Expected: PASS (2 test).

- [ ] **Step 6: CurlHttpClient'ı yaz (gerçek implementasyon, testte kullanılmaz)**

```php
<?php
namespace Garanti\Http;

class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP hata: ' . $err);
        }
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$resBody];
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add garanti/src/Http/ garanti/src/Auth/TokenManager.php garanti/tests/TokenManagerTest.php
git commit -m "feat(garanti): HttpClient arayuzu ve OAuth2 TokenManager"
```

---

### Task 4: GarantiClient — API çağrısı ve normalize (alan eşlemesi izole)

**Files:**
- Create: `garanti/config/field_map.php`
- Create: `garanti/src/Api/GarantiClient.php`
- Create: `garanti/tests/fixtures/account_transactions.json`
- Test: `garanti/tests/GarantiClientTest.php`

**Interfaces:**
- Consumes: `TokenManager::getToken()`, `HttpClient`.
- Produces: `Garanti\Api\GarantiClient::__construct(HttpClient $http, TokenManager $tm, array $apiCfg, array $fieldMap)`; `getTransactions(string $iban, string $from, string $to): array` → normalize edilmiş `['banka_ref','tarih','valor_tarihi','tutar','borc_alacak','para_birimi','aciklama','karsi_taraf','bakiye_sonrasi','ham_json']` dizileri.

> **NOT:** `field_map.php` ve fixture, gerçek Account Transactions JSON yanıtı gelene kadar **varsayılan/temsili** yapıya göre yazılır. Gerçek örnek geldiğinde SADECE bu iki dosya güncellenir; `GarantiClient` mantığı değişmez.

- [ ] **Step 1: field_map.php oluştur (JSON alan yolları — tek değişim noktası)**

```php
<?php
// Account Transactions JSON yanitindaki alan yollari. Gercek ornek gelince guncelle.
// 'list' = hareket dizisinin yanit icindeki anahtari; digerleri her hareket ogesindeki alanlar.
return [
    'list'           => 'transactions',
    'banka_ref'      => 'transactionId',
    'tarih'          => 'transactionDate',
    'valor_tarihi'   => 'valueDate',
    'tutar'          => 'amount',
    'borc_alacak'    => 'debitCreditIndicator', // 'D'/'C' bekleniyor
    'para_birimi'    => 'currency',
    'aciklama'       => 'description',
    'karsi_taraf'    => 'counterpartyName',
    'bakiye_sonrasi' => 'balanceAfter',
];
```

- [ ] **Step 2: fixture oluştur (temsili yanıt)**

`tests/fixtures/account_transactions.json`:

```json
{
  "transactions": [
    {
      "transactionId": "REF-1001",
      "transactionDate": "2026-06-30",
      "valueDate": "2026-06-30",
      "amount": 1500.50,
      "debitCreditIndicator": "C",
      "currency": "TRY",
      "description": "Gelen havale",
      "counterpartyName": "ACME LTD",
      "balanceAfter": 25000.00
    },
    {
      "transactionId": "REF-1002",
      "transactionDate": "2026-06-30",
      "valueDate": "2026-06-30",
      "amount": 300.00,
      "debitCreditIndicator": "D",
      "currency": "TRY",
      "description": "Fatura odemesi",
      "counterpartyName": "TEDARIKCI AS",
      "balanceAfter": 24700.00
    }
  ]
}
```

- [ ] **Step 3: Başarısızlık testini yaz**

`tests/GarantiClientTest.php`:

```php
<?php
namespace Garanti\Tests;

use Garanti\Api\GarantiClient;
use Garanti\Auth\TokenManager;
use PHPUnit\Framework\TestCase;

class GarantiClientTest extends TestCase
{
    private function tm(HttpFake $http): TokenManager
    {
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        return new TokenManager($http, [
            'token_url' => 'https://x/token', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb',
        ]);
    }

    public function test_normalizes_transactions_from_fixture(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));

        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1'],
            $fieldMap);

        $rows = $client->getTransactions('TR000000000000000000000001', '2026-06-01', '2026-06-30');

        $this->assertCount(2, $rows);
        $this->assertSame('REF-1001', $rows[0]['banka_ref']);
        $this->assertSame('C', $rows[0]['borc_alacak']);
        $this->assertSame(1500.50, $rows[0]['tutar']);
        $this->assertSame('ACME LTD', $rows[0]['karsi_taraf']);
        $this->assertNotEmpty($rows[0]['ham_json']);
    }

    public function test_sends_bearer_and_consent_headers(): void
    {
        $http = new HttpFake();
        $tm = $this->tm($http);
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://apis.garantibbva.com.tr', 'consent_id' => 'CONSENT1'], $fieldMap);

        $client->getTransactions('TR0001', '2026-06-01', '2026-06-30');

        $apiCall = $http->calls[1]; // 0 = token, 1 = transactions
        $flat = implode("\n", $apiCall['headers']);
        $this->assertStringContainsString('Authorization: Bearer AAA', $flat);
        $this->assertStringContainsString('CONSENT1', $flat);
    }
}
```

- [ ] **Step 4: Testi çalıştır — başarısız gör**

Run: `vendor/bin/phpunit tests/GarantiClientTest.php`
Expected: FAIL ("Class GarantiClient not found").

- [ ] **Step 5: GarantiClient'ı yaz**

```php
<?php
namespace Garanti\Api;

use Garanti\Http\HttpClient;
use Garanti\Auth\TokenManager;

class GarantiClient
{
    private HttpClient $http;
    private TokenManager $tm;
    private array $api;
    private array $map;

    public function __construct(HttpClient $http, TokenManager $tm, array $api, array $map)
    {
        $this->http = $http;
        $this->tm = $tm;
        $this->api = $api;
        $this->map = $map;
    }

    /** @return array<int,array> normalize edilmis hareketler */
    public function getTransactions(string $iban, string $from, string $to): array
    {
        $token = $this->tm->getToken();
        $url = $this->api['base_url'] . '/accountTransactions'
             . '?iban=' . urlencode($iban)
             . '&startDate=' . urlencode($from)
             . '&endDate=' . urlencode($to);
        $headers = [
            'Authorization: Bearer ' . $token,
            'consentId: ' . ($this->api['consent_id'] ?? ''),
            'Accept: application/json',
        ];
        $res = $this->http->request('GET', $url, $headers);
        if ($res['status'] === 401) {
            throw new \RuntimeException('Yetki hatasi (401) — token/consent kontrol edin');
        }
        if ($res['status'] !== 200) {
            throw new \RuntimeException('API hata: HTTP ' . $res['status']);
        }
        return $this->normalize(json_decode($res['body'], true) ?? []);
    }

    private function normalize(array $data): array
    {
        $list = $data[$this->map['list']] ?? [];
        $out = [];
        foreach ($list as $item) {
            $out[] = [
                'banka_ref'      => (string)($item[$this->map['banka_ref']] ?? ''),
                'tarih'          => (string)($item[$this->map['tarih']] ?? ''),
                'valor_tarihi'   => $item[$this->map['valor_tarihi']] ?? null,
                'tutar'          => (float)($item[$this->map['tutar']] ?? 0),
                'borc_alacak'    => (string)($item[$this->map['borc_alacak']] ?? ''),
                'para_birimi'    => (string)($item[$this->map['para_birimi']] ?? ''),
                'aciklama'       => $item[$this->map['aciklama']] ?? null,
                'karsi_taraf'    => $item[$this->map['karsi_taraf']] ?? null,
                'bakiye_sonrasi' => isset($item[$this->map['bakiye_sonrasi']])
                                        ? (float)$item[$this->map['bakiye_sonrasi']] : null,
                'ham_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 6: Testi çalıştır — geçtiğini gör**

Run: `vendor/bin/phpunit tests/GarantiClientTest.php`
Expected: PASS (2 test).

- [ ] **Step 7: Commit**

```bash
git add garanti/config/field_map.php garanti/src/Api/GarantiClient.php garanti/tests/fixtures/ garanti/tests/GarantiClientTest.php
git commit -m "feat(garanti): GarantiClient normalize + izole alan eslemesi"
```

---

### Task 5: TransactionRepository — idempotent yazım

**Files:**
- Create: `garanti/src/Db/AccountRepository.php`, `garanti/src/Db/TransactionRepository.php`
- Test: `garanti/tests/TransactionRepositoryTest.php`

**Interfaces:**
- Consumes: `\PDO`.
- Produces: `AccountRepository::ensure(string $iban, string $para): int` (account id, yoksa oluşturur); `TransactionRepository::save(int $accountId, array $rows): int` (yeni eklenen kayıt sayısı; `banka_ref` çakışanları atlar).

- [ ] **Step 1: Başarısızlık testini yaz (idempotency)**

`tests/TransactionRepositoryTest.php`:

```php
<?php
namespace Garanti\Tests;

use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use PHPUnit\Framework\TestCase;

class TransactionRepositoryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($this->pdo);
    }

    private function sampleRows(): array
    {
        return [[
            'banka_ref' => 'REF-1', 'tarih' => '2026-06-30', 'valor_tarihi' => '2026-06-30',
            'tutar' => 100.0, 'borc_alacak' => 'C', 'para_birimi' => 'TRY',
            'aciklama' => 'x', 'karsi_taraf' => 'y', 'bakiye_sonrasi' => 100.0, 'ham_json' => '{}',
        ]];
    }

    public function test_saves_new_transactions(): void
    {
        $accId = (new AccountRepository($this->pdo))->ensure('TR0001', 'TRY');
        $added = (new TransactionRepository($this->pdo))->save($accId, $this->sampleRows());
        $this->assertSame(1, $added);
    }

    public function test_duplicate_ref_is_skipped(): void
    {
        $accId = (new AccountRepository($this->pdo))->ensure('TR0001', 'TRY');
        $repo = new TransactionRepository($this->pdo);
        $repo->save($accId, $this->sampleRows());
        $addedSecond = $repo->save($accId, $this->sampleRows()); // ayni ref tekrar
        $this->assertSame(0, $addedSecond);
        $count = $this->pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function test_ensure_account_is_idempotent(): void
    {
        $repo = new AccountRepository($this->pdo);
        $a = $repo->ensure('TR0001', 'TRY');
        $b = $repo->ensure('TR0001', 'TRY');
        $this->assertSame($a, $b);
    }
}
```

- [ ] **Step 2: Testi çalıştır — başarısız gör**

Run: `vendor/bin/phpunit tests/TransactionRepositoryTest.php`
Expected: FAIL ("Class AccountRepository not found").

- [ ] **Step 3: AccountRepository'yi yaz**

```php
<?php
namespace Garanti\Db;

class AccountRepository
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function ensure(string $iban, string $para): int
    {
        $sel = $this->pdo->prepare("SELECT id FROM accounts WHERE iban = ?");
        $sel->execute([$iban]);
        $id = $sel->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $ins = $this->pdo->prepare("INSERT INTO accounts (iban, para_birimi) VALUES (?, ?)");
        $ins->execute([$iban, $para]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<int,array> aktif hesaplar */
    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM accounts WHERE aktif = 1 ORDER BY id")
                         ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

- [ ] **Step 4: TransactionRepository'yi yaz**

```php
<?php
namespace Garanti\Db;

class TransactionRepository
{
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    /** @return int yeni eklenen kayit sayisi (banka_ref cakisanlar atlanir) */
    public function save(int $accountId, array $rows): int
    {
        $sql = "INSERT INTO transactions
            (account_id, banka_ref, tarih, valor_tarihi, tutar, borc_alacak,
             para_birimi, aciklama, karsi_taraf, bakiye_sonrasi, ham_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $this->pdo->prepare($sql);
        $added = 0;
        foreach ($rows as $r) {
            $exists = $this->pdo->prepare("SELECT 1 FROM transactions WHERE banka_ref = ?");
            $exists->execute([$r['banka_ref']]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $stmt->execute([
                $accountId, $r['banka_ref'], $r['tarih'], $r['valor_tarihi'] ?? null,
                $r['tutar'], $r['borc_alacak'], $r['para_birimi'],
                $r['aciklama'] ?? null, $r['karsi_taraf'] ?? null,
                $r['bakiye_sonrasi'] ?? null, $r['ham_json'] ?? null,
            ]);
            $added++;
        }
        return $added;
    }
}
```

- [ ] **Step 5: Testi çalıştır — geçtiğini gör**

Run: `vendor/bin/phpunit tests/TransactionRepositoryTest.php`
Expected: PASS (3 test).

- [ ] **Step 6: Commit**

```bash
git add garanti/src/Db/AccountRepository.php garanti/src/Db/TransactionRepository.php garanti/tests/TransactionRepositoryTest.php
git commit -m "feat(garanti): idempotent hesap ve hareket repository'leri"
```

---

### Task 6: SyncJob + cron giriş noktası

**Files:**
- Create: `garanti/src/Sync/SyncJob.php`
- Create: `garanti/bin/sync.php`
- Test: `garanti/tests/SyncJobTest.php`

**Interfaces:**
- Consumes: `GarantiClient::getTransactions()`, `AccountRepository`, `TransactionRepository`, `\PDO`.
- Produces: `SyncJob::__construct(GarantiClient $client, AccountRepository $accounts, TransactionRepository $tx, \PDO $pdo)`; `run(array $ibans, string $para, int $lookbackDays, string $today): int` (toplam eklenen kayıt); her çalışma `sync_log`'a yazılır.

- [ ] **Step 1: Başarısızlık testini yaz (SyncJob DB'ye yazıyor + log)**

`tests/SyncJobTest.php`:

```php
<?php
namespace Garanti\Tests;

use Garanti\Api\GarantiClient;
use Garanti\Auth\TokenManager;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;
use PHPUnit\Framework\TestCase;

class SyncJobTest extends TestCase
{
    public function test_run_persists_transactions_and_logs(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::migrateSqlite($pdo);

        $http = new HttpFake();
        $http->push(200, json_encode(['access_token' => 'AAA', 'expires_in' => 3600]));
        $http->push(200, file_get_contents(__DIR__ . '/fixtures/account_transactions.json'));
        $tm = new TokenManager($http, ['token_url' => 'https://x/t', 'client_id' => 'c',
            'client_secret' => 's', 'redirect_uri' => 'https://x/cb']);
        $fieldMap = require __DIR__ . '/../config/field_map.php';
        $client = new GarantiClient($http, $tm,
            ['base_url' => 'https://x', 'consent_id' => 'C1'], $fieldMap);

        $job = new SyncJob($client, new AccountRepository($pdo), new TransactionRepository($pdo), $pdo);
        $added = $job->run(['TR0001'], 'TRY', 7, '2026-06-30');

        $this->assertSame(2, $added);
        $log = $pdo->query("SELECT durum, cekilen_kayit FROM sync_log")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ok', $log['durum']);
        $this->assertSame(2, (int)$log['cekilen_kayit']);
    }
}
```

- [ ] **Step 2: Testi çalıştır — başarısız gör**

Run: `vendor/bin/phpunit tests/SyncJobTest.php`
Expected: FAIL ("Class SyncJob not found").

- [ ] **Step 3: SyncJob'ı yaz**

```php
<?php
namespace Garanti\Sync;

use Garanti\Api\GarantiClient;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;

class SyncJob
{
    private GarantiClient $client;
    private AccountRepository $accounts;
    private TransactionRepository $tx;
    private \PDO $pdo;

    public function __construct(GarantiClient $client, AccountRepository $accounts,
                                TransactionRepository $tx, \PDO $pdo)
    {
        $this->client = $client;
        $this->accounts = $accounts;
        $this->tx = $tx;
        $this->pdo = $pdo;
    }

    /** @param string[] $ibans @return int toplam eklenen kayit */
    public function run(array $ibans, string $para, int $lookbackDays, string $today): int
    {
        $from = date('Y-m-d', strtotime($today . " -{$lookbackDays} days"));
        $start = date('Y-m-d H:i:s');
        $total = 0;
        try {
            foreach ($ibans as $iban) {
                $accId = $this->accounts->ensure($iban, $para);
                $rows = $this->client->getTransactions($iban, $from, $today);
                $total += $this->tx->save($accId, $rows);
            }
            $this->log($start, 'ok', $total, null);
        } catch (\Throwable $e) {
            $this->log($start, 'error', $total, substr($e->getMessage(), 0, 500));
            throw $e;
        }
        return $total;
    }

    private function log(string $start, string $durum, int $count, ?string $msg): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sync_log (baslangic, bitis, durum, cekilen_kayit, mesaj)
             VALUES (?,?,?,?,?)");
        $stmt->execute([$start, date('Y-m-d H:i:s'), $durum, $count, $msg]);
    }
}
```

- [ ] **Step 4: Testi çalıştır — geçtiğini gör**

Run: `vendor/bin/phpunit tests/SyncJobTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: bin/sync.php cron giriş noktasını yaz**

```php
<?php
// Cron: her 30 dk. Ornek crontab:  */30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';

use Garanti\Http\CurlHttpClient;
use Garanti\Auth\TokenManager;
use Garanti\Api\GarantiClient;
use Garanti\Db\Database;
use Garanti\Db\AccountRepository;
use Garanti\Db\TransactionRepository;
use Garanti\Sync\SyncJob;

$cfg = config();
$http = new CurlHttpClient();
$tm = new TokenManager($http, $cfg['oauth']);
$fieldMap = require __DIR__ . '/../config/field_map.php';
$client = new GarantiClient($http, $tm, $cfg['api'], $fieldMap);
$pdo = Database::connect($cfg['db']);

// Takip edilecek IBAN'lar: consent verilen hesaplar. Simdilik config'ten/DB'den.
$accounts = new AccountRepository($pdo);
$ibans = array_column($accounts->all(), 'iban');
if (empty($ibans)) {
    fwrite(STDERR, "Takip edilecek hesap yok. accounts tablosuna IBAN ekleyin.\n");
    exit(1);
}

$job = new SyncJob($client, $accounts, new TransactionRepository($pdo), $pdo);
$added = $job->run($ibans, 'TRY', (int)$cfg['sync']['lookback_days'], date('Y-m-d'));
echo date('c') . " — {$added} yeni hareket.\n";
```

- [ ] **Step 6: Commit**

```bash
git add garanti/src/Sync/SyncJob.php garanti/bin/sync.php garanti/tests/SyncJobTest.php
git commit -m "feat(garanti): SyncJob ve cron giris noktasi"
```

---

### Task 7: Dashboard login (oturum koruması)

**Files:**
- Create: `garanti/src/Auth/Session.php`, `garanti/public/login.php`, `garanti/public/logout.php`
- Create: `garanti/public/_guard.php`

**Interfaces:**
- Produces: `Garanti\Auth\Session::login(string $user, string $pass, array $cfg): bool`; `Session::check(): bool`; `Session::require(): void` (giriş yoksa login.php'ye yönlendirir).

- [ ] **Step 1: Session sınıfını yaz**

```php
<?php
namespace Garanti\Auth;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(string $user, string $pass, array $cfg): bool
    {
        self::start();
        if ($user === ($cfg['username'] ?? '') && password_verify($pass, $cfg['password_hash'] ?? '')) {
            $_SESSION['garanti_auth'] = true;
            return true;
        }
        return false;
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['garanti_auth']);
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['garanti_auth']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }
}
```

- [ ] **Step 2: _guard.php ortak başlığını yaz**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';
use Garanti\Auth\Session;
Session::requireLogin();
```

- [ ] **Step 3: login.php yaz**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/load.php';
use Garanti\Auth\Session;

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Session::login($_POST['user'] ?? '', $_POST['pass'] ?? '', config('dashboard'))) {
        header('Location: index.php');
        exit;
    }
    $err = 'Hatali kullanici adi veya sifre.';
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Garanti Hesap Takip — Giris</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="login">
<form method="post" class="login-box">
  <h1>Hesap Takip</h1>
  <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <input name="user" placeholder="Kullanici adi" autofocus>
  <input name="pass" type="password" placeholder="Sifre">
  <button type="submit">Giris</button>
</form></body></html>
```

- [ ] **Step 4: logout.php yaz**

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
use Garanti\Auth\Session;
Session::logout();
header('Location: login.php');
```

- [ ] **Step 5: Manuel doğrulama**

`config.php`'de `password_hash` üret: `php -r "echo password_hash('test123', PASSWORD_DEFAULT);"` → config'e koy. `php -S localhost:8000 -t garanti/public` ile başlat, `login.php`'ye git, yanlış/doğru şifreyi dene.
Expected: yanlış → hata mesajı; doğru → index.php'ye yönlenir.

- [ ] **Step 6: Commit**

```bash
git add garanti/src/Auth/Session.php garanti/public/login.php garanti/public/logout.php garanti/public/_guard.php
git commit -m "feat(garanti): dashboard login ve oturum korumasi"
```

---

### Task 8: Dashboard — hesap kartları, hareket tablosu, filtreler

**Files:**
- Create: `garanti/public/index.php`, `garanti/public/assets/app.css`, `garanti/public/assets/app.js`

**Interfaces:**
- Consumes: `_guard.php`, `\PDO` (Database::connect), `accounts`/`transactions`/`balances` tabloları.

- [ ] **Step 1: index.php yaz (özet + filtreli hareket tablosu)**

```php
<?php
require __DIR__ . '/_guard.php';
use Garanti\Db\Database;

$pdo = Database::connect(config('db'));

// Filtreler
$acc   = $_GET['acc']  ?? '';
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');
$q     = trim($_GET['q'] ?? '');

$accounts = $pdo->query("SELECT * FROM accounts WHERE aktif = 1 ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);

$where = ["t.tarih BETWEEN :from AND :to"];
$params = [':from' => $from, ':to' => $to];
if ($acc !== '')  { $where[] = "t.account_id = :acc"; $params[':acc'] = (int)$acc; }
if ($q !== '')    { $where[] = "(t.aciklama LIKE :q OR t.karsi_taraf LIKE :q)"; $params[':q'] = "%$q%"; }
$sql = "SELECT t.*, a.iban, a.ad FROM transactions t
        JOIN accounts a ON a.id = t.account_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.tarih DESC, t.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Para birimine gore toplam bakiye (her hesabin en guncel balances kaydi)
$balSql = "SELECT para_birimi, SUM(kapanis_bakiye) toplam FROM (
             SELECT b.account_id, b.para_birimi, b.kapanis_bakiye,
                    ROW_NUMBER() OVER (PARTITION BY b.account_id ORDER BY b.tarih DESC) rn
             FROM balances b
           ) x WHERE rn = 1 GROUP BY para_birimi";
$balances = [];
try { $balances = $pdo->query($balSql)->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) { /* window fn yoksa bos */ }
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Garanti Hesap Takip</title><link rel="stylesheet" href="assets/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script></head><body>
<header><h1>Hesap Takip</h1><a href="logout.php">Cikis</a></header>

<section class="summary">
  <?php foreach ($balances as $b): ?>
    <div class="card"><span><?= htmlspecialchars($b['para_birimi']) ?></span>
      <strong><?= number_format((float)$b['toplam'], 2, ',', '.') ?></strong></div>
  <?php endforeach; ?>
</section>

<form class="filters" method="get">
  <select name="acc"><option value="">Tum hesaplar</option>
    <?php foreach ($accounts as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $acc == $a['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['iban']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
  <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
  <input type="text" name="q" placeholder="Ara (aciklama/karsi taraf)" value="<?= htmlspecialchars($q) ?>">
  <button type="submit">Filtrele</button>
  <a class="btn" href="export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">Excel/CSV</a>
</form>

<canvas id="trend" height="80"></canvas>

<table class="tx"><thead><tr>
  <th>Tarih</th><th>Hesap</th><th>Aciklama</th><th>Karsi Taraf</th>
  <th class="num">Tutar</th><th>B/A</th><th class="num">Bakiye</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr class="<?= $r['borc_alacak'] === 'D' ? 'debit' : 'credit' ?>">
    <td><?= htmlspecialchars($r['tarih']) ?></td>
    <td><?= htmlspecialchars($r['iban']) ?></td>
    <td><?= htmlspecialchars((string)$r['aciklama']) ?></td>
    <td><?= htmlspecialchars((string)$r['karsi_taraf']) ?></td>
    <td class="num"><?= number_format((float)$r['tutar'], 2, ',', '.') ?></td>
    <td><?= $r['borc_alacak'] === 'D' ? 'Borc' : 'Alacak' ?></td>
    <td class="num"><?= $r['bakiye_sonrasi'] !== null ? number_format((float)$r['bakiye_sonrasi'], 2, ',', '.') : '' ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<script>window.TX = <?= json_encode(array_map(function($r){
  return ['tarih'=>$r['tarih'],'tutar'=>(float)$r['tutar'],'ba'=>$r['borc_alacak']];
}, $rows), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js"></script>
</body></html>
```

- [ ] **Step 2: app.css yaz (temel, temiz stil)**

```css
* { box-sizing: border-box; }
body { font-family: system-ui, Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1a1a1a; }
header { display: flex; justify-content: space-between; align-items: center;
  padding: 14px 22px; background: #0a5c36; color: #fff; }
header h1 { font-size: 18px; margin: 0; } header a { color: #cfe9d9; }
.summary { display: flex; gap: 14px; padding: 18px 22px; flex-wrap: wrap; }
.card { background: #fff; border-radius: 10px; padding: 14px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.card span { display: block; font-size: 12px; color: #6b7280; } .card strong { font-size: 22px; }
.filters { display: flex; gap: 10px; padding: 0 22px 14px; flex-wrap: wrap; }
.filters select, .filters input { padding: 8px; border: 1px solid #d1d5db; border-radius: 8px; }
.filters button, .btn { padding: 8px 14px; background: #0a5c36; color: #fff; border: 0;
  border-radius: 8px; text-decoration: none; cursor: pointer; }
canvas { margin: 0 22px 18px; background: #fff; border-radius: 10px; padding: 10px; }
table.tx { width: calc(100% - 44px); margin: 0 22px 30px; border-collapse: collapse; background: #fff; }
table.tx th, table.tx td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
table.tx .num { text-align: right; font-variant-numeric: tabular-nums; }
tr.debit .num { color: #b91c1c; } tr.credit .num { color: #047857; }
body.login { display: flex; align-items: center; justify-content: center; height: 100vh; }
.login-box { background: #fff; padding: 30px; border-radius: 12px; width: 300px; display: flex; flex-direction: column; gap: 12px; }
.login-box input { padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; }
.login-box .err { color: #b91c1c; margin: 0; font-size: 14px; }
```

- [ ] **Step 3: app.js yaz (bakiye/giriş-çıkış trend grafiği)**

```javascript
(function () {
  if (!window.TX || !window.Chart) return;
  // Gune gore net (alacak - borc) topla
  const byDay = {};
  window.TX.forEach(function (t) {
    const d = t.tarih;
    byDay[d] = (byDay[d] || 0) + (t.ba === 'D' ? -t.tutar : t.tutar);
  });
  const labels = Object.keys(byDay).sort();
  const data = labels.map(function (d) { return byDay[d]; });
  new Chart(document.getElementById('trend'), {
    type: 'bar',
    data: { labels: labels, datasets: [{ label: 'Gunluk net (TL)', data: data,
      backgroundColor: data.map(function (v) { return v < 0 ? '#fca5a5' : '#6ee7b7'; }) }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
})();
```

- [ ] **Step 4: Manuel doğrulama**

`accounts` + `transactions` tablolarına birkaç örnek kayıt gir (veya Task 6 sync'i çalıştır), `php -S localhost:8000 -t garanti/public`, giriş yap, index.php'yi aç.
Expected: hesap kartları, filtreler, hareket tablosu ve trend grafiği görünür; tarih/hesap/arama filtreleri sonucu daraltır.

- [ ] **Step 5: Commit**

```bash
git add garanti/public/index.php garanti/public/assets/
git commit -m "feat(garanti): dashboard hareket tablosu, filtreler ve trend grafigi"
```

---

### Task 9: Excel/CSV dışa aktarma

**Files:**
- Create: `garanti/public/export.php`

**Interfaces:**
- Consumes: `_guard.php`, aynı filtre parametreleri (`acc`, `from`, `to`, `q`).

- [ ] **Step 1: export.php yaz (filtrelenmiş sonucu CSV olarak indir)**

```php
<?php
require __DIR__ . '/_guard.php';
use Garanti\Db\Database;

$pdo = Database::connect(config('db'));
$acc  = $_GET['acc']  ?? '';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$q    = trim($_GET['q'] ?? '');

$where = ["t.tarih BETWEEN :from AND :to"];
$params = [':from' => $from, ':to' => $to];
if ($acc !== '') { $where[] = "t.account_id = :acc"; $params[':acc'] = (int)$acc; }
if ($q !== '')   { $where[] = "(t.aciklama LIKE :q OR t.karsi_taraf LIKE :q)"; $params[':q'] = "%$q%"; }
$sql = "SELECT t.tarih, a.iban, t.aciklama, t.karsi_taraf, t.tutar, t.borc_alacak,
               t.para_birimi, t.bakiye_sonrasi
        FROM transactions t JOIN accounts a ON a.id = t.account_id
        WHERE " . implode(' AND ', $where) . " ORDER BY t.tarih DESC, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hesap_hareketleri_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel Turkce icin)
fputcsv($out, ['Tarih','IBAN','Aciklama','Karsi Taraf','Tutar','Borc/Alacak','Para Birimi','Bakiye'], ';');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $r['tarih'], $r['iban'], $r['aciklama'], $r['karsi_taraf'],
        number_format((float)$r['tutar'], 2, ',', '.'),
        $r['borc_alacak'] === 'D' ? 'Borc' : 'Alacak',
        $r['para_birimi'],
        $r['bakiye_sonrasi'] !== null ? number_format((float)$r['bakiye_sonrasi'], 2, ',', '.') : '',
    ], ';');
}
fclose($out);
```

- [ ] **Step 2: Manuel doğrulama**

Dashboard'da "Excel/CSV" linkine tıkla.
Expected: `.csv` iner, Excel'de Türkçe karakterler ve `;` ayraçlı sütunlar düzgün açılır.

- [ ] **Step 3: Commit**

```bash
git add garanti/public/export.php
git commit -m "feat(garanti): filtrelenmis hareketleri CSV disa aktarma"
```

---

### Task 10: Callback endpoint (OAuth geri dönüş)

**Files:**
- Create: `garanti/public/callback.php`

**Interfaces:**
- Portal'daki Callback URL ile eşleşir: `https://partner.trek-turkey.com/garanti/callback.php`.

- [ ] **Step 1: callback.php yaz (minimal, güvenli)**

```php
<?php
// Garanti portal Callback URL hedefi. client_credentials akisinda tarayici
// yonlendirmesi beklenmez; bu uc yalnizca portal dogrulamasi/olasi kod donusu icindir.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
$code = $_GET['code'] ?? null;
if ($code !== null) {
    // Ileride authorization_code akisi gerekirse burada islenecek. Simdilik logla.
    error_log('[garanti-callback] code alindi: ' . substr($code, 0, 8) . '...');
}
echo 'OK';
```

- [ ] **Step 2: Commit**

```bash
git add garanti/public/callback.php
git commit -m "feat(garanti): OAuth callback endpoint"
```

---

### Task 11: Tüm test suite + deploy hazırlığı

**Files:**
- Modify: `garanti/config/config.example.php` (gerekiyorsa son alanlar)
- Create: `garanti/README.md`

- [ ] **Step 1: Tüm testleri çalıştır**

Run: `cd garanti && vendor/bin/phpunit`
Expected: PASS — TokenManager (2), GarantiClient (2), TransactionRepository (3), SyncJob (1), Database (1) = 9 test yeşil.

- [ ] **Step 2: README.md yaz (kurulum + deploy + cron)**

```markdown
# Garanti Hesap Takip

## Kurulum
1. `composer install`
2. `config/config.example.php` → `config/config.php` kopyala, gercek degerleri gir:
   - Client ID/Secret (portal Application), consent_id (EHO), DB, dashboard sifresi.
   - Dashboard sifre hash'i: `php -r "echo password_hash('SIFRE', PASSWORD_DEFAULT);"`
3. MySQL'de `sql/schema.sql` calistir.
4. Takip edilecek IBAN'lari `accounts` tablosuna ekle (consent verilenler).

## Cron (her 30 dk)
*/30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1

## Deploy
FTP hedefi: partner.trek-turkey.com → /garanti/  (config.php'yi elle, guvenli sekilde koy — repoda yok)

## Gercek API yaniti gelince
`config/field_map.php` ve `tests/fixtures/account_transactions.json` gercek
alan adlariyla guncellenir; `vendor/bin/phpunit` tekrar kosulur.
```

- [ ] **Step 3: Commit**

```bash
git add garanti/README.md garanti/config/config.example.php
git commit -m "docs(garanti): README, kurulum ve deploy notlari"
```

---

## Self-Review Notu

- **Spec kapsamı:** OAuth2+consent (Task 3,4,10), çoklu hesap/döviz (accounts.para_birimi, Task 5/8), bakiye+hareket+filtre (Task 8), grafik (Task 8), CSV export (Task 9), login (Task 7), idempotency (Task 5), hata yönetimi+sync_log (Task 6) — hepsi karşılandı.
- **Açık dış bağımlılık:** gerçek Account Transactions endpoint yolu + JSON alan adları. `GarantiClient` bunları `config/field_map.php` ve fixture üzerinden okur; gerçek örnek gelince yalnız bu iki dosya + `getTransactions()` içindeki URL/parametre satırı güncellenir (Task 4 notu).
- **Tip tutarlılığı:** `getTransactions()` çıktısı ↔ `TransactionRepository::save()` girdisi aynı anahtar setini kullanır; `SyncJob.run()` imzası bin/sync.php ve testte aynı.
- **balances tablosu:** Faz 1'de Account Information'dan doldurulması Task kapsamına eklenebilir; şu an dashboard bakiye özeti balances'tan okur, window function yoksa boş geçer (güvenli). Gerçek Account Information yanıtı gelince küçük bir ek task ile doldurulur.
