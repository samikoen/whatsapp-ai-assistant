# IBKR Android — Status Bar Çipinde Okunabilir NAV Metni

**Tarih:** 2026-07-10
**Proje:** `ibkr_android/` (com.ibkr.widget)

## Problem

Durum çubuğundaki yeşil çip, NAV'ı ("0.97") 48px'lik kare bitmap'e çizilmiş
small icon olarak gösteriyor. İkon yuvası ~24dp olduğu için 4 karakter
sığdırılınca rakamlar okunamayacak kadar küçük kalıyor.

## Tespit (dumpsys ile doğrulandı)

- Bildirim zaten `PROMOTED_ONGOING` (Android 16 Live Update) statüsünde.
- `android.shortCriticalText = "968"` sisteme gidiyor.
- Buna rağmen çipte sistem metni görünmüyor; kuvvetli hipotez: custom kare
  bitmap small icon, çipte metnin yerini işgal ediyor / Samsung yalnızca
  ikonu basıyor.

## Çözüm — Deneme 1 (asıl)

1. API 36 bildirim yolunda custom `createTextIcon()` bitmap'i **kullanma**.
2. Yerine sade, monokrom vektör drawable (`res/drawable/ic_stat_nav.xml`,
   beyaz ▲ üçgen) small icon olarak verilir.
3. `setShortCriticalText(title)` aynen kalır (title = NAV/1000, örn "968").
4. Çip zemin rengi `setColor(accentColor)` ile kâr/zarar yeşil/kırmızı kalır.

Beklenen sonuç: Samsung çipi "▲ 968" şeklinde sistem fontuyla (saat boyutuna
yakın, okunabilir) çizer.

## Doğrulama

Build (`debug_build.ps1`) → `adb install -r` → servis başlat → `screencap`
ile durum çubuğunu kontrol et. Metin görünüyorsa başarı.

## Plan B (Samsung metni yine basmazsa)

Bitmap ikona geri dön ama içerik "0.97" (4 kr) yerine `round(NAV/10000)`
("97", 1M üzeri "102") olarak 2-3 karaktere düşür + iç boşlukları kısarak
tam dolu çiz → rakamlar ~2.3 kat büyür.

## Kapsam dışı

- Bildirim paneli içeriği, Now Bar/AOD düzeni, home screen widget değişmiyor.
- `createTextIcon()` silinmiyor (hane-per-bildirim deneyi ve Plan B için duruyor).
