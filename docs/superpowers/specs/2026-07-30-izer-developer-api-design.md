# Trek B2B — İzer Geliştirici API'si (Tasarım)

**Tarih:** 30 Temmuz 2026
**Durum:** Tasarım — onay bekliyor

## Amaç

İzer'e (güvenilir geliştirici, sistemi geliştirmeye katkı yapacak) Trek B2B sisteminin
tüm verilerine **okuma + yazma** erişimi veren, dokümante ve iptal edilebilir bir HTTP API.

İzer dış müşteri/rakip değil; yine de erişim **iptal edilebilir** ve **loglanabilir** olmalı
(anahtar sızarsa tek bayrakla kapatılabilsin).

## Kapsam

- **Okuma:** katalog, fiyat (Trek dealer + bayi + manuel + DE/B2C), stok (TR + EU/NCI/DDC + ETA), değişiklikler
- **Yazma (TAM — okumadaki her kategorinin yazma karşılığı, admin seviyesi):**
  - Sipariş: oluştur / iptal / durum güncelle / sorgula
  - Fiyat: manuel fiyat belirle-sil, bayi çarpanı (genel + kategori) ayarla-sil
  - Bayi: oluştur / güncelle / sil
  - Stok & katalog: NCI CSV import, EPOS sync tetikle
- **Yazma bağlamı:** anahtar `dealer_id` parametresiyle **herhangi bir bayi** adına sipariş açabilir (esnek — seçenek b). Diğer yazma işlemleri admin seviyesindedir (bayi/fiyat/stok yönetimi).

## Mimari — Yaklaşım A (mevcut endpoint'lere API-key katmanı)

Bugün zaten çalışan JWT-korumalı endpoint'ler var. Bunları sıfırdan yazmak yerine
üstlerine ortak bir API-key doğrulama katmanı ekleriz. Bayi portalı (JWT) ile İzer'in
API'si (X-API-Key) **paralel** çalışır; portal bozulmaz.

### Ortak middleware — `config/api_auth.php`

```
apiAuth($requiredScope)  // 'read' veya 'read_write'
  1. Authorization: Bearer <JWT>  varsa  -> mevcut verifyToken() (bayi portali icin)
  2. X-API-Key: <key>            varsa  -> api_keys tablosundan dogrula
  3. Ikisi de yoksa/gecersizse   -> 401
  4. API-key ise: scope kontrolu (read_write gerektiren endpoint'te read anahtari -> 403)
  5. last_used = NOW(), request_count++ , (opsiyonel) api_request_log'a yaz
  -> donen: ['auth' => 'jwt'|'apikey', 'dealer_id' => ..., 'scope' => ...]
```

Her endpoint başında `verifyToken` bloğu bu helper çağrısıyla değiştirilir.

### Veritabanı — `api_keys` tablosu

```sql
CREATE TABLE api_keys (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  key_hash      CHAR(64) NOT NULL UNIQUE,   -- SHA-256(anahtar); duz metin TUTULMAZ
  owner         VARCHAR(100) NOT NULL,      -- 'izer'
  scope         ENUM('read','read_write') NOT NULL DEFAULT 'read',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  last_used     DATETIME NULL,
  request_count INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

Anahtar üretimi: `trek_izer_<32 hex>` — düz metin **bir kez** İzer'e verilir, DB'de sadece
hash durur. İptal: `UPDATE api_keys SET active=0 WHERE owner='izer'`.

(Opsiyonel) `api_request_log` — hangi anahtar, hangi endpoint, ne zaman, HTTP kodu.
İlk sürümde `api_keys.last_used` + `request_count` yeterli; log sonra eklenebilir.

## Endpoint Yüzeyi

Mevcut endpoint'ler korunur, sadece auth katmanı eklenir. İzer aynı URL'leri X-API-Key ile çağırır.

### Okuma (scope: read)

| Amaç | Endpoint |
|------|----------|
| Katalog (ürün+varyant+görsel+spec) | `GET /b2b/data/eu-catalog-grouped.json` — statik dosya, zaten açık, İzer doğrudan indirir (API-key gerektirmez) |
| Trek canlı dealer fiyatı | `POST /b2b/api/dealer/get-trek-prices.php` {skus:[...]} |
| Bayi fiyat çarpanı | `GET /b2b/api/dealer/get-pricing.php` |
| Manuel fiyatlar | `GET /b2b/api/dealer/get-custom-prices.php` |
| DE/B2C fiyatları | `GET /b2b/api/dealer/get-german-prices.php` |
| EU/NCI stok + ETA | `GET /b2b/api/dealer/get-nci-stock.php` |
| DDC stok | `GET /b2b/api/dealer/get-ddc-stock.php` |
| TR depo stok (BizimHesap) | `GET https://video.trek-turkey.com/bizimhesap-warehouse-with-prices-b2b-api-v2.php` (bu zaten açık) |
| Stok/fiyat değişiklikleri | `GET /b2b/api/dealer/get-changes-v3.php` |

### Yazma (scope: read_write) — TAM

**Sipariş**
| Amaç | Endpoint |
|------|----------|
| Sipariş oluştur | `POST /b2b/api/orders/create.php` (+ `dealer_id`) |
| Sipariş iptal | `POST /b2b/api/orders/cancel.php` |
| Sipariş durum güncelle | `POST /b2b/api/admin/orders/update-status.php` |
| Sipariş sorgula | `GET /b2b/api/orders/list.php`, `GET /b2b/api/orders/detail.php` |

**Fiyat yönetimi**
| Amaç | Endpoint |
|------|----------|
| Manuel fiyat belirle/sil | `POST /b2b/api/admin/products/set-custom-price.php` |
| Bayi genel çarpanı | `POST /b2b/api/admin/dealers/update-general-multiplier.php` |
| Kategori çarpanı belirle | `POST /b2b/api/admin/dealers/set-category-multiplier.php` |
| Kategori çarpanı sil | `POST /b2b/api/admin/dealers/delete-category-multiplier.php` |
| Bayi fiyatlandırma | `POST /b2b/api/admin/dealers/set-pricing.php` |

**Bayi yönetimi**
| Amaç | Endpoint |
|------|----------|
| Bayi oluştur | `POST /b2b/api/admin/dealers/create.php` |
| Bayi güncelle | `POST /b2b/api/admin/dealers/update.php` |
| Bayi sil | `POST /b2b/api/admin/dealers/delete.php` |

**Stok & katalog**
| Amaç | Endpoint |
|------|----------|
| NCI CSV import | `POST /b2b/api/admin/nci/import-csv.php` |
| EPOS sync tetikle | `GET /b2b/api/cron/sync-wrapper.php` (secret ile — İzer'e secret verilir) |

**Sipariş yazma (seçenek b):** API-key ile gelen `create.php` isteği, body'deki `dealer_id`'yi
kullanır. JWT ile gelen (portal) istekler eskisi gibi token'daki bayiyi kullanır — davranış değişmez.
`dealer_id` geçersizse 400. Diğer admin yazma endpoint'leri zaten global (bayi-bağımsız).

## Güvenlik

- Anahtar DB'de **hash'li** (sızıntıda düz metin açığa çıkmaz).
- `active=0` ile anında iptal.
- `scope=read` anahtar yazma endpoint'ine giderse **403**.
- Tüm API çağrıları HTTPS (mevcut).
- `dealer_id` doğrulaması: yazma isteğinde bayi gerçekten var mı kontrol edilir.
- Rate limit ilk sürümde YOK (İzer güvenilir, tek kullanıcı). Kötüye kullanım görülürse eklenir.

## Dokümantasyon

Tek dosya: `b2b/api/API-DOCS-IZER.md`
- Auth (X-API-Key header, örnek)
- Her endpoint: yöntem, URL, parametre, örnek istek (curl), örnek yanıt (JSON)
- Fiyat para birimi (USD), stok kodlaması (20+/51 tavanları, 2035=bilinmiyor)
- Hata kodları (401 auth, 403 scope, 400 girdi)

## Test / Doğrulama

- `api_keys`'e test anahtarı ekle, hem read hem read_write.
- Okuma: her endpoint'i X-API-Key ile çağır, JWT ile aynı sonucu döndürmeli.
- Scope: read anahtarıyla `create.php` → 403.
- İptal: `active=0` sonrası tüm çağrılar → 401.
- Yazma (sipariş): read_write anahtar + `dealer_id` ile test siparişi oluştur, sonra iptal et.
- Yazma (admin): read_write anahtarla manuel fiyat belirle/sil, çarpan ayarla, test bayisi oluştur/sil — her biri DB'de doğrulanır.
- JWT yolu bozulmadı: bayi portalı + admin paneli normal çalışmalı (regresyon).

## Kapsam Dışı (ilk sürüm)

- Ayrı `/api/v1/` katmanı (Yaklaşım B) — gerekirse sonra.
- Rate limiting, kota.
- Çoklu anahtar/kullanıcı yönetim arayüzü (şimdilik SQL ile elle).
- Webhook / push bildirim.

## Riskler

- **Admin seviyesi yazma (geniş yetki):** İzer'in anahtarı bayi silebilir, fiyat/çarpan
  değiştirebilir, EPOS sync tetikleyebilir. Yanlışlıkla veya hatalı kodla üretim verisi
  bozulabilir. Kabul edildi (İzer güvenilir geliştirici). Azaltıcılar: (1) anahtar `active=0`
  ile anında iptal, (2) `request_count`/`last_used` ile izleme, (3) İleride yazma işlemleri
  `api_request_log`'a yazılabilir — kim ne değiştirdi görünür. **Öneri:** kritik yazma
  (bayi sil, EPOS sync) öncesi İzer'in test-bayisi/staging kullanması.
- **Sipariş yazma (seçenek b):** İzer yanlış `dealer_id` ile sipariş açabilir. Kabul edildi.
  Gerçek sipariş akışında dikkat; test için ayrı bir test-bayisi kullanmak önerilir.
- **TR stok endpoint'i zaten açık** (auth'suz) — İzer'e vermek ek risk getirmez ama not edilmeli.
- **get-trek-prices** Trek B2B site login'ine bağlı; İzer yoğun çağırırsa Trek oturumu/ban riski.
  Proxy'nin cache'i (6 saat) bunu büyük ölçüde önler.
