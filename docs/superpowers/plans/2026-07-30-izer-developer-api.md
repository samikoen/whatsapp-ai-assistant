# İzer Geliştirici API'si Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** İzer'e (güvenilir geliştirici) Trek B2B'nin tüm verilerine `X-API-Key` ile okuma/yazma erişimi veren, iptal edilebilir + loglanan bir HTTP API.

**Architecture:** Mevcut JWT-korumalı endpoint'lere ortak bir `apiAuth()` katmanı eklenir; bu katman hem eski JWT'yi (bayi portalı) hem yeni X-API-Key'i kabul eder. Anahtarlar `api_keys` tablosunda hash'li tutulur. Yeni endpoint yazılmaz — mevcut olanların auth bloğu değiştirilir.

**Tech Stack:** PHP (sunucu 7.4.33), MySQL (`db()` fonksiyonu, `trek_b2b`), FTP deploy.

## Global Constraints

- **PHP 7.4 uyumlu kod** — canlı sunucu 7.4.33 (lokal 8.3; ok'a bakma, 7.4 sözdizimi kullan: arrow fn yerine closure, `??` OK, named args YOK, `str_contains` YOK → `strpos`).
- **MySQL, `db()`** — `require_once '.../config/database.php'; $pdo = db();` (PDO).
- **verifyToken()** payload döndürür: `$decoded['id']`, `['email']`, `['role']`, `['company_name']`. `false` = geçersiz.
- **Test = deploy + curl** — bu projede formal test framework YOK. Her task'ın testi: FTP deploy + canlı curl doğrulaması.
- **FTP deploy:** `curl --ftp-ssl --insecure -u 'trektur:P6tQ8dp$Ezxwy_a6' -T <lokal> "ftp://77.245.148.150/partner.trek-turkey.com/b2b/<yol>"`
- **Git YOK** — server-only (kullanıcı kararı). `git commit` adımı YOK; onun yerine "deploy" adımı.
- **Admin login (test token):** `admin@trek-turkey.com` / `Izerko11.` → `POST /b2b/api/auth/login.php`, token `data.token`'da.
- **PHP syntax kontrolü:** `C:/Users/samik/php/php.exe -l <dosya>`

---

## Dosya Yapısı

**Yeni:**
- `sql/create-api-keys-table.sql` — api_keys tablosu DDL
- `config/api_auth.php` — ortak `apiAuth($requiredScope)` helper (TEK sorumluluk: auth)
- `api/admin/api-keys/generate.php` — anahtar üret (admin JWT korumalı, düz metni bir kez döndürür)
- `api/test/diag-api-auth.php` — apiAuth katmanının canlı teşhis/regresyon aracı
- `api/API-DOCS-IZER.md` — İzer'in dokümantasyonu

**Değiştirilecek (auth bloğu → apiAuth çağrısı):**
- Okuma: `get-trek-prices`, `get-pricing`, `get-custom-prices`, `get-german-prices`, `get-nci-stock`, `get-ddc-stock`, `get-changes-v3`
- Yazma (sipariş): `orders/create` (+ dealer_id), `orders/cancel`, `orders/list`, `orders/detail`
- Yazma (admin): `admin/orders/update-status`, `admin/products/set-custom-price`, `admin/dealers/{create,update,delete,set-category-multiplier,delete-category-multiplier,update-general-multiplier,set-pricing}`, `admin/nci/import-csv`

---

## Task 1: api_keys tablosu + anahtar üretim endpoint'i

**Files:**
- Create: `sql/create-api-keys-table.sql`
- Create: `api/admin/api-keys/generate.php`

**Interfaces:**
- Produces: `api_keys` tablosu (kolonlar: `id, key_hash CHAR(64), owner, scope ENUM('read','read_write'), active TINYINT, last_used, request_count, created_at`).
- Produces: `POST /b2b/api/admin/api-keys/generate.php` → düz anahtar `trek_izer_<32hex>` (bir kez), DB'ye hash yazar.

- [ ] **Step 1: SQL DDL yaz**

`sql/create-api-keys-table.sql`:
```sql
CREATE TABLE IF NOT EXISTS api_keys (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  key_hash      CHAR(64) NOT NULL UNIQUE,
  owner         VARCHAR(100) NOT NULL,
  scope         ENUM('read','read_write') NOT NULL DEFAULT 'read',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  last_used     DATETIME NULL,
  request_count INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: generate.php yaz (admin JWT korumalı)**

`api/admin/api-keys/generate.php` — mevcut admin auth kalıbını kullanır (`verifyToken` + `role==='admin'`). Body: `{"owner":"izer","scope":"read_write"}`. Tabloyu yoksa oluşturur (idempotent), anahtar üretir:
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/jwt.php';
$h = function_exists('getallheaders') ? getallheaders() : [];
$a = $h['Authorization'] ?? ($h['authorization'] ?? '');
if (!preg_match('/Bearer\s+(.*)$/i', $a, $mm)) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Token yok']); exit; }
$dec = verifyToken($mm[1]);
if (!$dec || ($dec['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Admin gerekli']); exit; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$owner = preg_replace('/[^a-z0-9_-]/i', '', $in['owner'] ?? 'izer');
$scope = ($in['scope'] ?? 'read') === 'read_write' ? 'read_write' : 'read';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (id INT AUTO_INCREMENT PRIMARY KEY, key_hash CHAR(64) NOT NULL UNIQUE, owner VARCHAR(100) NOT NULL, scope ENUM('read','read_write') NOT NULL DEFAULT 'read', active TINYINT(1) NOT NULL DEFAULT 1, last_used DATETIME NULL, request_count INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$plain = 'trek_' . $owner . '_' . bin2hex(random_bytes(16));
$hash = hash('sha256', $plain);
$st = $pdo->prepare("INSERT INTO api_keys (key_hash, owner, scope) VALUES (?, ?, ?)");
$st->execute([$hash, $owner, $scope]);
echo json_encode(['success'=>true, 'api_key'=>$plain, 'owner'=>$owner, 'scope'=>$scope, 'note'=>'Bu anahtar bir daha gosterilmeyecek. Guvenli sakla.'], JSON_UNESCAPED_SLASHES);
```

- [ ] **Step 3: PHP syntax kontrolü**

Run: `C:/Users/samik/php/php.exe -l api/admin/api-keys/generate.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Deploy**

```bash
curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/admin/api-keys/generate.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/admin/api-keys/generate.php"
```
Not: `api/admin/api-keys/` klasörü FTP'de yoksa curl `-T` ile üst klasör oluşmaz → önce `curl --ftp-ssl --insecure -u ... "ftp://.../b2b/api/admin/api-keys/" --ftp-create-dirs` ya da yükleme sırasında `--ftp-create-dirs` ekle.

- [ ] **Step 5: Test — İzer için read_write anahtar üret**

```bash
TOKEN=$(curl -sS -X POST "https://partner.trek-turkey.com/b2b/api/auth/login.php" -H "Content-Type: application/json" -d '{"email":"admin@trek-turkey.com","password":"Izerko11."}' | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['token'])")
curl -sS -X POST "https://partner.trek-turkey.com/b2b/api/admin/api-keys/generate.php" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"owner":"izer","scope":"read_write"}'
```
Expected: `{"success":true,"api_key":"trek_izer_<32hex>",...}`. **Bu anahtarı `$KEY` olarak kaydet** (İzer'e verilecek read_write anahtar + sonraki task testleri). Admin token'sız çağrı → 403.

Ayrıca test için bir **read-only** anahtar da üret (scope testleri `$KEY_R` kullanır):
```bash
curl -sS -X POST "https://partner.trek-turkey.com/b2b/api/admin/api-keys/generate.php" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"owner":"izer-readonly","scope":"read"}'
```
Bunu `$KEY_R` olarak kaydet. (Test bitince silinebilir: `DELETE FROM api_keys WHERE owner='izer-readonly'`.)

---

## Task 2: apiAuth() ortak katmanı

**Files:**
- Create: `config/api_auth.php`
- Test: `api/test/diag-api-auth.php`

**Interfaces:**
- Consumes: `db()` (database.php), `verifyToken()` (jwt.php), Task 1'in `api_keys` tablosu + test anahtarı.
- Produces: `apiAuth($requiredScope = 'read'): array` — başarıda `['auth_type'=>'jwt'|'apikey', 'id'=>?, 'email'=>?, 'role'=>?, 'company_name'=>?, 'scope'=>?]` döndürür; başarısızlıkta HTTP kodu yazıp `exit` eder (401 auth yok/geçersiz, 403 scope yetersiz).

- [ ] **Step 1: apiAuth() yaz**

`config/api_auth.php`:
```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/jwt.php';

/**
 * Hem JWT (bayi portali) hem X-API-Key (Izer) kabul eden ortak auth.
 * @param string $requiredScope 'read' | 'read_write'
 * @return array auth bilgisi. Basarisizsa HTTP kodu yazip exit eder.
 */
function apiAuth($requiredScope = 'read') {
    $h = function_exists('getallheaders') ? getallheaders() : [];
    // getallheaders bazen kucuk harf key doner
    $hl = [];
    foreach ($h as $k => $v) { $hl[strtolower($k)] = $v; }

    // 1) X-API-Key onceligi
    $apiKey = $hl['x-api-key'] ?? '';
    if ($apiKey !== '') {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM api_keys WHERE key_hash = ? AND active = 1");
        $st->execute([hash('sha256', $apiKey)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Geçersiz API anahtarı']);
            exit;
        }
        // Scope kontrolu: read_write gereken yerde read anahtar -> 403
        if ($requiredScope === 'read_write' && $row['scope'] !== 'read_write') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu işlem için yazma yetkisi gerekli']);
            exit;
        }
        // Kullanim izleme
        $pdo->prepare("UPDATE api_keys SET last_used = NOW(), request_count = request_count + 1 WHERE id = ?")
            ->execute([$row['id']]);
        return [
            'auth_type'    => 'apikey',
            'id'           => null,
            'email'        => $row['owner'] . '@api',
            'role'         => 'api',
            'company_name' => 'API (' . $row['owner'] . ')',
            'scope'        => $row['scope'],
        ];
    }

    // 2) JWT (mevcut bayi/admin portali)
    $auth = $hl['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
        $dec = verifyToken($m[1]);
        if ($dec) {
            $dec['auth_type'] = 'jwt';
            $dec['scope']     = 'read_write'; // portal kullanicilari tam yetkili (mevcut davranis)
            return $dec;
        }
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token veya API anahtarı gerekli']);
    exit;
}
```

- [ ] **Step 2: Teşhis endpoint'i yaz**

`api/test/diag-api-auth.php` — apiAuth'u çağırıp dönen bilgiyi gösterir (regresyon + İzer testi için):
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/api_auth.php';
$scope = ($_GET['scope'] ?? 'read') === 'read_write' ? 'read_write' : 'read';
$auth = apiAuth($scope);
echo json_encode(['success' => true, 'auth' => $auth], JSON_UNESCAPED_SLASHES);
```

- [ ] **Step 3: PHP syntax kontrolü**

Run: `C:/Users/samik/php/php.exe -l config/api_auth.php && C:/Users/samik/php/php.exe -l api/test/diag-api-auth.php`
Expected: her ikisi `No syntax errors detected`

- [ ] **Step 4: Deploy**

```bash
curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "config/api_auth.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/config/api_auth.php"
curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/test/diag-api-auth.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/test/diag-api-auth.php"
```

- [ ] **Step 5: Test — dört senaryo**

`KEY` = Task 1'de üretilen read_write anahtar.
```bash
B="https://partner.trek-turkey.com/b2b/api/test/diag-api-auth.php"
# a) Gecerli API-key -> 200, auth_type=apikey, scope=read_write
curl -sS "$B" -H "X-API-Key: $KEY" -w "\n%{http_code}\n"
# b) Anahtar yok -> 401
curl -sS "$B" -w "\n%{http_code}\n"
# c) Gecersiz anahtar -> 401
curl -sS "$B" -H "X-API-Key: trek_izer_sahte" -w "\n%{http_code}\n"
# d) read anahtarla read_write scope iste -> 403  (once Task1 ile read anahtar uret, KEY_R)
curl -sS "$B?scope=read_write" -H "X-API-Key: $KEY_R" -w "\n%{http_code}\n"
```
Expected: (a) 200 + `apikey`, (b) 401, (c) 401, (d) 403.

---

## Task 3: Okuma endpoint'lerine katman

**Files:**
- Modify: `api/dealer/get-nci-stock.php`, `api/dealer/get-ddc-stock.php`, `api/dealer/get-trek-prices.php`, `api/dealer/get-pricing.php`, `api/dealer/get-custom-prices.php`, `api/dealer/get-german-prices.php`, `api/dealer/get-changes-v3.php`

**Interfaces:**
- Consumes: `apiAuth('read')` (Task 2).

**Kalıp (her dosyaya uygulanır):** Dosyanın başındaki JWT bloğu — `getallheaders()` + `preg_match Bearer` + `verifyToken` + `if (!$decoded)` — tek satırla değiştirilir. Örnek `get-nci-stock.php` için:

- [ ] **Step 1: get-nci-stock.php auth bloğunu değiştir**

Mevcut (satır ~32-50, `require`'lardan sonra) şu blok:
```php
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Token bulunamadı']); exit; }
$token = $matches[1];
$decoded = verifyToken($token);
if (!$decoded) { http_response_code(401); ... exit; }
```
şununla değiştirilir:
```php
require_once __DIR__ . '/../../config/api_auth.php';
$decoded = apiAuth('read');   // JWT veya X-API-Key; basarisizsa iceride exit
```
(Not: `require_once database.php` ve `jwt.php` zaten var; api_auth.php ikisini de include ediyor, çift include zararsız — `require_once`.)

- [ ] **Step 2: Kalan 6 okuma dosyasına aynı değişikliği uygula**

Her birinde JWT auth bloğunu bul (aynı `preg_match Bearer` + `verifyToken` kalıbı) ve `$decoded = apiAuth('read');` ile değiştir; başına `require_once __DIR__ . '/../../config/api_auth.php';` ekle. Dosyalar: `get-ddc-stock.php`, `get-trek-prices.php`, `get-pricing.php`, `get-custom-prices.php`, `get-german-prices.php`, `get-changes-v3.php`.

- [ ] **Step 3: PHP syntax kontrolü (7 dosya)**

Run: `for f in api/dealer/get-nci-stock.php api/dealer/get-ddc-stock.php api/dealer/get-trek-prices.php api/dealer/get-pricing.php api/dealer/get-custom-prices.php api/dealer/get-german-prices.php api/dealer/get-changes-v3.php; do C:/Users/samik/php/php.exe -l "$f"; done`
Expected: hepsi `No syntax errors detected`

- [ ] **Step 4: Deploy (7 dosya)**

```bash
for f in get-nci-stock get-ddc-stock get-trek-prices get-pricing get-custom-prices get-german-prices get-changes-v3; do
  curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/dealer/$f.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/dealer/$f.php"
done
```

- [ ] **Step 5: Test — API-key + JWT ikisi de çalışıyor (regresyon)**

```bash
# API-key ile NCI stok -> 200 + veri
curl -sS "https://partner.trek-turkey.com/b2b/api/dealer/get-nci-stock.php" -H "X-API-Key: $KEY" -o /dev/null -w "apikey: %{http_code}\n"
# JWT ile (bayi portali bozulmadi) -> 200
curl -sS "https://partner.trek-turkey.com/b2b/api/dealer/get-nci-stock.php" -H "Authorization: Bearer $TOKEN" -o /dev/null -w "jwt: %{http_code}\n"
# get-trek-prices API-key ile POST -> 200 + fiyat
curl -sS -X POST "https://partner.trek-turkey.com/b2b/api/dealer/get-trek-prices.php" -H "X-API-Key: $KEY" -H "Content-Type: application/json" -d '{"skus":["5326020"]}' -w "\ntrek-prices: %{http_code}\n"
```
Expected: apikey 200, jwt 200, trek-prices 200 + Domane fiyatı.

---

## Task 4: Sipariş yazma + dealer_id (seçenek b)

**Files:**
- Modify: `api/orders/create.php`, `api/orders/cancel.php`, `api/orders/list.php`, `api/orders/detail.php`

**Interfaces:**
- Consumes: `apiAuth('read_write')` (create/cancel), `apiAuth('read')` (list/detail).
- Behavior: API-key ile `create.php` çağrısında `dealer_id` body'den alınır; JWT ile eskisi gibi `$decoded['id']`.

- [ ] **Step 1: create.php auth + dealer_id değişikliği**

Auth bloğunu `$decoded = apiAuth('read_write');` ile değiştir (başına api_auth.php require). Sonra `dealer_id`/`user_id` atamasını (mevcut `'dealer_id' => $userId`) şu mantıkla güncelle:
```php
require_once __DIR__ . '/../../config/api_auth.php';
$decoded = apiAuth('read_write');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
// API-key ise dealer_id body'den; JWT ise token'daki kullanici
if (($decoded['auth_type'] ?? '') === 'apikey') {
    $dealerId = isset($input['dealer_id']) ? (int)$input['dealer_id'] : 0;
    if ($dealerId <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'API anahtarı ile sipariş için dealer_id zorunlu']); exit; }
    // dealer gercekten var mi
    $chk = db()->prepare("SELECT id FROM users WHERE id = ? AND role = 'dealer'");
    $chk->execute([$dealerId]);
    if (!$chk->fetch()) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Geçersiz dealer_id']); exit; }
    $userId = $dealerId;
} else {
    $userId = $decoded['id'];
}
```
`$userId` mevcut kodda hem `user_id` hem `dealer_id` olarak kullanılıyor — bu atama korunur, sadece kaynağı yukarıdaki mantıkla belirlenir. Mevcut `$userId = $decoded['id'];` satırı yukarıdaki blokla değiştirilir. `$userEmail`/`$userCompany` API-key ise `$decoded['email']`/`$decoded['company_name']`'den gelir (apiAuth zaten dolduruyor).

- [ ] **Step 2: cancel.php, list.php, detail.php auth değişikliği**

`cancel.php` → `apiAuth('read_write')`; `list.php` ve `detail.php` → `apiAuth('read')`. Her birinde mevcut JWT bloğunu değiştir, `require_once .../api_auth.php` ekle. (Bunlar `$decoded['id']` kullanıyorsa: JWT'de gelir; API-key'de `null` — list/detail için sipariş filtreleme `dealer_id` query param'a düşürülür: `$dealerId = ($decoded['auth_type']==='apikey') ? (int)($_GET['dealer_id'] ?? 0) : $decoded['id'];`. detail.php tek sipariş id'siyle çalışıyorsa dealer filtresi opsiyonel.)

- [ ] **Step 3: PHP syntax kontrolü (4 dosya)**

Run: `for f in create cancel list detail; do C:/Users/samik/php/php.exe -l api/orders/$f.php; done`
Expected: hepsi temiz.

- [ ] **Step 4: Deploy (4 dosya)**

```bash
for f in create cancel list detail; do curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/orders/$f.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/orders/$f.php"; done
```

- [ ] **Step 5: Test — API-key ile test siparişi (bir test bayisi ile)**

Önce bir dealer_id bul: `curl -sS "https://partner.trek-turkey.com/b2b/api/admin/dealers/list.php" -H "X-API-Key: $KEY"` → bir dealer id al (ör. 7).
```bash
# read anahtarla create -> 403 (scope)
curl -sS -X POST ".../orders/create.php" -H "X-API-Key: $KEY_R" -H "Content-Type: application/json" -d '{"dealer_id":7,"items":[...]}' -w "\n%{http_code}\n"   # 403
# read_write + dealer_id yok -> 400
curl -sS -X POST ".../orders/create.php" -H "X-API-Key: $KEY" -H "Content-Type: application/json" -d '{"items":[...]}' -w "\n%{http_code}\n"   # 400
# read_write + gecerli dealer_id + gecerli item -> 200, siparis olusur
# (items formati orders/create.php'nin bekledigi sekilde; mevcut bir SKU + qty)
# sonra: olusan order iptal edilir (cancel.php)
```
Expected: 403 (read scope), 400 (dealer_id yok), 200 (geçerli → sipariş oluşur), iptal 200. **Not:** gerçek üretim bayisine test siparişi açma — mümkünse bir test bayisi kullan; açılırsa hemen iptal et.

---

## Task 5: Admin yazma endpoint'lerine katman

**Files:**
- Modify: `api/admin/orders/update-status.php`, `api/admin/products/set-custom-price.php`, `api/admin/dealers/create.php`, `api/admin/dealers/update.php`, `api/admin/dealers/delete.php`, `api/admin/dealers/set-category-multiplier.php`, `api/admin/dealers/delete-category-multiplier.php`, `api/admin/dealers/update-general-multiplier.php`, `api/admin/dealers/set-pricing.php`, `api/admin/nci/import-csv.php`

**Interfaces:**
- Consumes: `apiAuth('read_write')`.

- [ ] **Step 1: Her admin dosyasının JWT/admin-role bloğunu değiştir**

Mevcut kalıp bu dosyalarda: `verifyToken` + genelde `role === 'admin'` kontrolü. Değişiklik: `require_once` ile api_auth.php ekle, auth bloğunu `$decoded = apiAuth('read_write');` ile değiştir. **Rol notu:** apiAuth API-key için `role='api'` döndürür; bu dosyalarda `role==='admin'` şartı varsa, onu `in_array($decoded['role'], ['admin','api'], true)` yap (API anahtarı admin işlevine erişebilsin — spec gereği İzer admin yazma yapabilir). API-key zaten `read_write` scope ile korunuyor.

`require_once` yol derinliği: `api/admin/dealers/*.php` → `__DIR__ . '/../../../config/api_auth.php'` (3 seviye). `api/admin/orders/`, `api/admin/products/`, `api/admin/nci/` de 3 seviye.

- [ ] **Step 2: PHP syntax kontrolü (10 dosya)**

Run: `for f in admin/orders/update-status admin/products/set-custom-price admin/dealers/create admin/dealers/update admin/dealers/delete admin/dealers/set-category-multiplier admin/dealers/delete-category-multiplier admin/dealers/update-general-multiplier admin/dealers/set-pricing admin/nci/import-csv; do C:/Users/samik/php/php.exe -l "api/$f.php"; done`
Expected: hepsi temiz.

- [ ] **Step 3: Deploy (10 dosya)**

```bash
for f in admin/orders/update-status admin/products/set-custom-price admin/dealers/create admin/dealers/update admin/dealers/delete admin/dealers/set-category-multiplier admin/dealers/delete-category-multiplier admin/dealers/update-general-multiplier admin/dealers/set-pricing admin/nci/import-csv; do
  curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/$f.php" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/$f.php"
done
```

- [ ] **Step 4: Test — admin yazma API-key ile**

```bash
# set-custom-price read_write ile -> 200 (bir test SKU + fiyat)
curl -sS -X POST ".../api/admin/products/set-custom-price.php" -H "X-API-Key: $KEY" -H "Content-Type: application/json" -d '{"dealer_id":7,"sku":"5326020","custom_price":5000}' -w "\n%{http_code}\n"
# read anahtarla ayni istek -> 403
curl -sS -X POST ".../api/admin/products/set-custom-price.php" -H "X-API-Key: $KEY_R" -H "Content-Type: application/json" -d '{"dealer_id":7,"sku":"5326020","custom_price":5000}' -w "\n%{http_code}\n"
# JWT admin ile (regresyon - panel bozulmadi) -> 200
curl -sS -X POST ".../api/admin/products/set-custom-price.php" -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"dealer_id":7,"sku":"5326020","custom_price":5000}' -w "\n%{http_code}\n"
```
Expected: read_write 200, read 403, JWT admin 200. (Test sonrası set edilen custom_price'ı sil/geri al.)

---

## Task 6: İzer dokümantasyonu

**Files:**
- Create: `api/API-DOCS-IZER.md`

- [ ] **Step 1: Dokümantasyonu yaz**

`api/API-DOCS-IZER.md` — İçerik:
- **Auth:** `X-API-Key: trek_izer_...` header. Örnek. Anahtar iptal edilebilir; scope `read` veya `read_write`.
- **Base:** `https://partner.trek-turkey.com/b2b`
- **Okuma endpoint'leri** — her biri: yöntem, URL, parametre, örnek `curl -H "X-API-Key: ..."`, örnek JSON yanıt. (Task 3 listesi: katalog statik JSON, get-trek-prices, get-pricing, get-custom-prices, get-german-prices, get-nci-stock, get-ddc-stock, get-changes-v3.)
- **Yazma endpoint'leri** — sipariş (create + `dealer_id`, cancel, list, detail), admin (set-custom-price, çarpanlar, bayi CRUD, nci import, epos sync).
- **Veri notları:** fiyat USD; stok kodlaması (NCI 20 = "20+", DDC 51 = "açık sipariş", `31/12/2040`/2035+ = "tarih bilinmiyor", ETA `bugün+12` tahmini); TR stok endpoint'i (video.trek-turkey.com, açık).
- **Hata kodları:** 401 (auth yok/geçersiz), 403 (scope yetersiz), 400 (girdi hatası).
- **EPOS sync tetikleme:** `GET /b2b/api/cron/sync-wrapper.php?secret=trek_epos_sync_2025_secure_key_xyz123&method=cron` (İzer'e secret verilir).

- [ ] **Step 2: Deploy**

```bash
curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' -T "api/API-DOCS-IZER.md" "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/API-DOCS-IZER.md"
```
(Not: `.md` sunucuda çalışmaz, sadece İzer'e dosya olarak da verilebilir. Deploy opsiyonel.)

---

## Task 7: Uçtan uca doğrulama + iptal testi + temizlik

**Files:**
- Test: `api/test/diag-api-auth.php` (Task 2)

- [ ] **Step 1: İptal mekanizması testi**

```bash
# Anahtari iptal et (SQL - phpMyAdmin veya bir admin endpoint):
#   UPDATE api_keys SET active=0 WHERE owner='izer';
# Sonra herhangi bir endpoint -> 401
curl -sS "https://partner.trek-turkey.com/b2b/api/dealer/get-nci-stock.php" -H "X-API-Key: $KEY" -o /dev/null -w "iptal sonrasi: %{http_code}\n"
```
Expected: 401. (Test sonrası `active=1` ile geri aç.)

- [ ] **Step 2: Kullanım logu doğrulaması**

```bash
# Birkaç cagri sonrasi request_count / last_used arttI mI:
#   SELECT owner, scope, active, request_count, last_used FROM api_keys;
```
Expected: `request_count > 0`, `last_used` güncel.

- [ ] **Step 3: Regresyon — bayi portalı + admin paneli**

Tarayıcıda (veya JWT ile curl): bayi portalı ürün listesi, fiyat, stok, sipariş görüntüleme; admin panelinde bayi/fiyat işlemleri. Hepsi JWT ile eskisi gibi çalışmalı (apiAuth JWT yolunu kırmadı).

- [ ] **Step 4: Teşhis dosyalarını temizle**

```bash
# diag-api-auth.php'yi birak (izleme icin faydali) VEYA sil:
curl --ftp-ssl --insecure -sS -u 'trektur:P6tQ8dp$Ezxwy_a6' "ftp://77.245.148.150/partner.trek-turkey.com/b2b/api/test/diag-api-auth.php" -Q "DELE /partner.trek-turkey.com/b2b/api/test/diag-api-auth.php"
```
(Karar: teşhis dosyası secret'lı değil ama auth gerektiriyor — bırakmak zararsız, İzer'in kendi anahtarını test etmesi için faydalı. Silme opsiyonel.)

- [ ] **Step 5: İzer'e teslim paketi**

İzer'e verilecekler: (1) `api_key` (Task 1 çıktısı), (2) `API-DOCS-IZER.md`, (3) EPOS sync secret. Anahtar `read_write` scope'ta. İptal için: `UPDATE api_keys SET active=0 WHERE owner='izer'`.
