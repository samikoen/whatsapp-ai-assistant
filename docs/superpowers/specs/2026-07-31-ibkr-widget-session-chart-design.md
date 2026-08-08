# IBKR Widget — Gün İçi 3 Seanslı NAV Grafiği

**Tarih:** 2026-07-31
**Proje:** `ibkr_android/` (com.ibkr.widget)

## Amaç

Home screen widget'ındaki hız göstergesinin altına, bugünün üç seansını
(pre-market / regular / after-hours) tek bir 1 günlük grafikte gösteren bir
panel eklemek. IBKR Mobile'ın ETH (extended hours) grafiğiyle aynı mantık,
ama tek sembol yerine **toplam portföy NAV'ı** çizilir.

## Veri kaynağı — ek network YOK

`PortfolioService` her tick'te zaten şu isteği atıyor:

    /v8/finance/chart/{sym}?interval=1m&range=1d&includePrePost=true

Yanıttaki `timestamp[]` + `indicators.quote[0].close[]` dizileri şimdiye kadar
sadece son fiyat ve saatlik trend için kullanılıp atılıyordu. Artık bu ham seri
`parseYahooResponse()` çıktısına `ts` / `cl` olarak ekleniyor ve NAV eğrisi
buradan hesaplanıyor. Ek istek yok, ek gecikme yok.

Seans sınırları `meta.tradingPeriods` (veri gününe ait) üzerinden alınır,
yoksa `meta.currentTradingPeriod`'a düşülür. Böylece DST ve yarım günler
otomatik doğru çalışır — sabit saat kodlanmaz.

## Hesaplama (`NavSeries.java`)

1. Seans sınırları: `preStart → postEnd` aralığı 180 eşit kovaya bölünür
   (normal günde ~5.3 dakika/kova).
2. Her ticker'ın dakikalık close'ları ilgili kovaya yazılır (kova içindeki son
   tik geçerli).
3. Boşluklar forward-fill edilir; ilk veriden önce `prevClose` kullanılır.
4. Kova başına `NAV = cash + Σ(qty × price)`, sonra dünkü kapanış NAV'ına göre
   yüzde farka çevrilir. `cash` ve `prevCloseNAV` mevcut `computeAndNotify()`
   mantığından gelir (tek doğruluk kaynağı).
5. Hiç işlem görmemiş kovalar (henüz gelmemiş seans) `~` = boş kalır.

Prefs'te tek satır olarak saklanır:

    nav_series = preStart;regStart;regEnd;postEnd;p0,p1,...,p179

Ham `ts`/`cl` dizileri prefs'e **yazılmaz** (`stripSeries()` ile ayıklanır) —
`underlying_data` şişmesin diye. Doğrulandı: prefs toplam 2.8 KB.

## Çizim (`SessionChartDrawer.java`)

Gauge ile **aynı bitmap'e** çizilir (ayrı ImageView değil) — RemoteViews'un
bitmap boyut limitine iki ayrı büyük bitmap'le takılmamak için.

- Üç seans bandı arka planda renk tonuyla ayrılır: PRE mavi, MARKET yeşil,
  AFTER mor; aralarında kesikli dikey ayraç.
- Noktalı yatay çizgi = dünkü kapanış NAV'ı (baseline, her zaman eksende).
- NAV eğrisi: gün artıda yeşil, ekside kırmızı; altı yarı saydam dolgulu.
- Eğrinin ucunda beyaz nokta + güncel günlük yüzde etiketi.
- Alt eksende dört saat etiketi, telefonun **yerel saatiyle** (TR).
- Y ekseni veriye göre otomatik ölçeklenir; en dar aralık %0.30 (düz gün
  gürültü gibi görünmesin).

## Widget boyutu

`widget_info.xml`: minHeight 200dp → 250dp, targetCellHeight 2 → 3.
Bitmap: 380×200dp → 380×304dp (grafik 104dp). Seri yoksa `chartH = 0` →
widget eskisi gibi sadece gauge çizer (güvenli geri düşüş).

## Doğrulama

Build → `adb install` → 20 sn bekle → `screencap`. Doğrulandı: seri prefs'e
yazıldı (16 saat = 5.5 pre + 6.5 regular + 4 after), grafik ekranda çizildi,
saat etiketleri 11:00 / 16:30 / 23:00 / 03:00, eğri ucundaki %0.33 gauge'daki
değerle birebir aynı.

## Kapsam dışı

Bildirim, Now Bar, WebView dashboard ve `MainActivity` değişmedi. MainActivity
prefs'e yazarken `nav_series` anahtarına dokunmaz, dolayısıyla grafik korunur
(servis en geç 60 sn'de bir tazeler).
