# Garanti Hesap Takip

## Kurulum
1. `composer install`
2. `config/config.example.php` → `config/config.php` kopyala, gercek degerleri gir:
   - Client ID/Secret (portal Application), consent_id (EHO), DB, dashboard sifresi.
   - Dashboard sifre hash'i: `php -r "echo password_hash('SIFRE', PASSWORD_DEFAULT);"`
3. MySQL'de `sql/schema.sql` calistir.
4. Takip edilecek IBAN'lari `accounts` tablosuna ekle (consent verilenler).

## Cron (her 30 dk)
```
*/30 * * * * php /path/garanti/bin/sync.php >> /path/garanti/sync.out 2>&1
```

## Deploy
FTP hedefi: partner.trek-turkey.com → /garanti/  (config.php'yi elle, guvenli sekilde koy — repoda yok)

## Gercek API yaniti gelince
`config/field_map.php` ve `tests/fixtures/account_transactions.json` gercek
alan adlariyla guncellenir; `vendor/bin/phpunit` tekrar kosulur.
