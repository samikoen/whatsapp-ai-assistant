# Garanti Hesap Takip Uygulaması — Tasarım (Faz 1)

- **Tarih:** 2026-07-01
- **Durum:** Onaylandı (Faz 1 kapsamı)
- **Sahip:** Sami Koen
- **Hedef sunucu:** partner.trek-turkey.com (PHP + MySQL)

## 1. Amaç

Garanti BBVA kurumsal hesaplarının bakiye ve hareketlerini, bankanın **Electronic Bank Statement** REST API'leri (Account Information + Account Transactions) üzerinden otomatik çekip, kendi sunucumuzda saklayıp bir web dashboard'unda takip etmek.

Dosya (CAMT/MT940 + FTP) yolu B planı olarak elenmedi ama tercih edilmedi; API yolu daha modern (anlık, JSON, programlanabilir).

## 2. Entegrasyon gerçekleri (araştırmadan)

| Konu | Değer |
|------|-------|
| Kimlik doğrulama | OAuth2, `grant_type=client_credentials` |
| Token endpoint | `https://apis.garantibbva.com.tr/auth/oauth/v2/token` |
| Token isteği | `POST`, `Content-Type: application/x-www-form-urlencoded`, alanlar: `grant_type`, `client_id`, `client_secret`, `redirect_uri` |
| Application ayarı | Manage → Applications → Add Application; Scope=`OOB`, Type=`Confidential`, HTTPS Callback URL |
| Hesap onayı (consent) | Garanti İnternet Bankacılığı → "Elektronik Hesap Özeti (EHÖ)" servisi → uygulama Client ID girilir → hesaplar seçilir → `consentId` alınır |
| Aylık ücret | EHÖ servisi ~₺194/ay (bankaya teyit ettirilecek) |
| Callback URL (bizde) | `https://partner.trek-turkey.com/garanti/callback.php` |

### Açık teknik kalem (ÖNKOŞUL)
Account Transactions ve Account Information API'lerinin **tam endpoint yolu**, **istek parametreleri** ve **örnek JSON yanıtı** portal login'inin arkasında; henüz elimizde yok. Kod, gerçek yanıt alan adlarına göre `GarantiClient` içinde eşlenecek. Bu netleşene kadar alan eşlemesi (mapping) bir konfigürasyon katmanında toplanır, çekirdek akış bundan bağımsız yazılır.

## 3. Mimari

```
Garanti API (apis.garantibbva.com.tr)
        ▲ OAuth2 (client_credentials) + consentId
        │
┌───────┴───────────────────────────────────────┐
│  partner.trek-turkey.com  (PHP 7.4 + MySQL)    │
│                                                │
│  TokenManager  → access token al / cache / yenile
│  GarantiClient → Account Transactions + Information çağır
│  SyncJob (cron)→ periyodik çek, DB'ye idempotent yaz
│  MySQL         → accounts, transactions, balances, sync_log
│  Dashboard     → login korumalı web arayüz
└────────────────────────────────────────────────┘
```

Uygulama, dosya yolundaki gibi FTP beklemez; arka planda (cron) API'yi çağırıp veriyi DB'ye yazar. Dashboard her zaman DB'den okur — hızlı ve API limit-dostu.

## 4. Bileşenler

Her modül tek sorumluluk taşır, iyi tanımlı arayüzle konuşur, bağımsız test edilebilir.

### 4.1 TokenManager
- **Ne yapar:** OAuth2 `client_credentials` ile access token alır, süresini (`expires_in`) izler, dolmadan yeniler, bellek/DB'de cache'ler.
- **Girdi:** client_id, client_secret, redirect_uri (config'ten).
- **Çıktı:** geçerli `access_token` string.
- **Bağımlılık:** cURL, config.

### 4.2 GarantiClient
- **Ne yapar:** Verilen access token + consentId ile Account Information ve Account Transactions endpoint'lerine istek atar, ham JSON'u normalize edilmiş PHP dizisine çevirir.
- **Girdi:** hesap listesi, tarih aralığı.
- **Çıktı:** normalize `transactions[]`, `balances[]`.
- **Not:** Gerçek endpoint yolu + alan adları netleşince buradaki mapping güncellenir. Alan eşlemesi `config/garanti_fields.php` benzeri tek yerde tutulur.

### 4.3 SyncJob (cron)
- **Ne yapar:** Belirli aralıkla (örn. 30 dk) çalışır; her hesap için son N günün hareketlerini çeker, DB'ye **idempotent** yazar (banka referansı/benzersiz anahtarla tekrarı önler), bakiyeleri günceller, `sync_log`'a kaydeder.
- **Idempotency anahtarı:** hesap + banka hareket referansı (yoksa hesap+tarih+tutar+sıra hash'i).
- **Hata durumu:** başarısız senkron `sync_log`'a `error` olarak yazılır; bir sonraki tur tekrar dener (kaldığı tarihten).

### 4.4 Veritabanı şeması (MySQL)
- `accounts`: id, iban, hesap_no, sube, para_birimi, ad, aktif, olusturma
- `transactions`: id, account_id, banka_ref (unique), tarih, valor_tarihi, tutar, borc_alacak (D/C), para_birimi, aciklama, karsi_taraf, bakiye_sonrasi, ham_json, olusturma
- `balances`: id, account_id, tarih, acilis_bakiye, kapanis_bakiye, para_birimi, guncelleme
- `sync_log`: id, baslangic, bitis, durum (ok/error), cekilen_kayit, mesaj

`transactions.banka_ref` üzerinde UNIQUE index → idempotency.

### 4.5 Dashboard (login korumalı)
- **Giriş:** basit oturum (mevcut projelerdeki gibi), tek kullanıcı yeterli.
- **Ekranlar:**
  - Özet: toplam bakiye (para birimine göre), hesap kartları, son hareketler.
  - Hareketler: tablo + filtre (hesap, tarih aralığı, tutar, borç/alacak, arama).
  - Grafikler: günlük/aylık giriş-çıkış, bakiye trendi (SOLD'daki grafik yaklaşımı).
  - Dışa aktarma: filtrelenmiş sonucu Excel/CSV indir.

## 5. Kapsam

### Faz 1 (bu spec)
- OAuth2 + consent ile API entegrasyonu (TokenManager, GarantiClient).
- SyncJob (cron) + idempotent yazım.
- Çoklu hesap + çoklu döviz (TL/USD/EUR).
- Dashboard: bakiye + hareket listesi + filtre/arama.
- Grafikler (giriş-çıkış, bakiye trendi).
- Excel/CSV dışa aktarma.
- Login koruması.

### Faz 2 (bu spec dışında)
- Kategorileme/etiketleme (otomatik kural + elle).
- Bildirim/uyarı (tutar eşiği; WhatsApp/e-posta).
- Mutabakat (beklenen vs gerçekleşen).

## 6. Hata yönetimi

- **Token hatası (401/expired):** TokenManager token'ı yeniler ve isteği bir kez tekrar dener.
- **Consent hatası:** kullanıcıya dashboard'da "EHÖ onayı gerekli/yenilenmeli" uyarısı; `sync_log`'a yazılır.
- **API/ağ hatası:** SyncJob turu `error` işaretlenir, veri kaybı olmaz; sonraki tur kaldığı yerden devam eder.
- **Kısmi veri:** idempotent yazım sayesinde tekrar çekim güvenli, çift kayıt oluşmaz.
- **Gizli bilgiler:** client_secret ve consentId sunucuda `.env`/`secret.php` benzeri, repoya girmeyen dosyada; loglara yazılmaz.

## 7. Güvenlik

- client_secret / consentId repoya commit edilmez (`.gitignore`).
- Callback ve dashboard HTTPS.
- Dashboard login zorunlu; API kimlik bilgileri sadece sunucu tarafında.
- Token ve secret'lar loglanmaz.

## 8. Test yaklaşımı

- **Birim:** TokenManager (token cache/yenileme mantığı), GarantiClient normalize/mapping (sabit örnek JSON ile), idempotency (aynı hareketi iki kez yazmama).
- **Entegrasyon:** SyncJob'ın örnek API yanıtından DB'ye doğru yazması (mock/fixture yanıtla, gerçek endpoint netleşince gerçek sandbox ile).
- **Manuel:** dashboard filtre/grafik/dışa aktarma gözle doğrulama.

## 9. Önkoşullar (kullanıcı tarafında)

1. Portal'da **Application** oluştur (Scope=OOB, Type=Confidential, Callback=`https://partner.trek-turkey.com/garanti/callback.php`) → Client ID + Secret al.
2. Application'a **Account Information** + **Account Transactions** API'lerini ekle.
3. İnternet bankacılığından **EHÖ** ile hesapları onayla → `consentId` al (aylık ücret teyidi).
4. Account Transactions API'sinin **endpoint + örnek JSON yanıtını** paylaş (alan eşlemesi için).

## 10. Kabul kriterleri (Faz 1)

- Cron çalıştığında Garanti API'den hareketler çekilip DB'ye çift kayıt olmadan yazılıyor.
- Dashboard'da her hesabın güncel bakiyesi ve hareket listesi doğru görünüyor.
- Tarih/tutar/hesap/arama filtreleri çalışıyor.
- En az bir grafik (bakiye trendi) ve Excel/CSV dışa aktarma çalışıyor.
- Kimlik bilgileri repoda değil; API hataları veri kaybına yol açmıyor.
