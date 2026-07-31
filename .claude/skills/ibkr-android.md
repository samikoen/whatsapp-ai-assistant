# IBKR Android - Portfoy Widget ve Canli Piyasa Uygulamasi

Bu skill cagrildiginda asagidaki proje bilgilerini referans alarak kullaniciya yardimci ol.

## Proje Bilgileri

| Bilgi | Deger |
|-------|-------|
| **Proje Konumu** | `C:\Users\samik\Downloads\ibkr_android\` |
| **Package** | `com.ibkr.widget` |
| **APK Ciktisi** | `C:\Users\samik\Downloads\IBKRWidget.apk` |
| **Min SDK** | 26 (Android 8.0) |
| **Target SDK** | 36 (Android 16) |
| **Compile SDK** | 36 (Android 16) |
| **Java** | 17 (JDK 21 ile derlenir) |
| **Telefon** | Samsung Galaxy S23 Ultra, One UI 8, Android 16 |

## Build Ortami

| Bilesen | Konum / Versiyon |
|---------|------------------|
| **JAVA_HOME** | `C:\Program Files\Microsoft\jdk-21.0.10.7-hotspot` |
| **ANDROID_HOME** | `C:\Android` |
| **Gradle** | 8.11.1 (`ibkr_android/gradle-dist/gradle-8.11.1/`) |
| **AGP** | 8.7.3 |
| **Build Script** | `debug_build.ps1` (temiz build - `app/build` siler) |
| **SDK Platforms** | android-34, android-36 |

### Build Komutu
```powershell
cd "C:\Users\samik\Downloads\ibkr_android"
powershell -ExecutionPolicy Bypass -File debug_build.ps1
```

### KRITIK Build Notu
- XML resource degisikliklerinden sonra MUTLAKA `debug_build.ps1` kullan (incremental build bozulur)
- `debug_build.ps1` her seferinde `app/build` klasorunu siler, temiz build yapar
- Build sonrasi APK: `app/build/outputs/apk/debug/app-debug.apk`
- APK kopyalama: `Copy-Item app/build/outputs/apk/debug/app-debug.apk ../IBKRWidget.apk`
- AGP 8.7.3 compileSdk 36 icin uyari verir (test edilmis max 35) ama sorunsuz calisir

## Dosya Yapisi

```
ibkr_android/
├── build.gradle                    # Root build (AGP 8.7.3)
├── settings.gradle
├── gradle.properties
├── debug_build.ps1                 # Temiz build scripti (Gradle 8.11.1)
├── install_sdk36.bat               # SDK 36 kurulum scripti
├── gradle-dist/
│   ├── gradle-8.5/                 # Eski Gradle (artik kullanilmiyor)
│   ├── gradle-8.11.1/              # Aktif Gradle
│   └── gradle-8.11.1-bin.zip
├── app/
│   ├── build.gradle                # App build (compileSdk 36, Java 17)
│   └── src/main/
│       ├── AndroidManifest.xml
│       ├── assets/
│       │   └── index.html          # WebView ana sayfa (canli piyasa dashboard)
│       ├── java/com/ibkr/widget/
│       │   ├── MainActivity.java       # Ana uygulama (WebView + QuoteBridge)
│       │   ├── PortfolioService.java    # Foreground service (arka plan fetch + bildirim + Now Bar)
│       │   ├── IBKRWidgetProvider.java  # Home screen widget
│       │   ├── YahooSession.java        # Yahoo Finance session yonetimi
│       │   └── NavAlertManager.java     # Sesli uyari sistemi
│       └── res/
│           ├── drawable/
│           │   ├── widget_bg.xml           # Widget arka plan (seffaf)
│           │   └── ic_launcher_foreground.xml
│           ├── layout/
│           │   └── widget_layout.xml       # Widget UI layout
│           ├── values/
│           │   └── styles.xml              # AppTheme + string'ler
│           ├── xml/
│           │   └── widget_info.xml         # Widget metadata (180x80dp)
│           └── mipmap-*/                   # Launcher ikonlari
```

## Mimari

### Veri Akisi
```
Yahoo Finance API → PortfolioService (arka plan)  → Bildirim (telefon + Now Bar + AOD)
                                                   → SharedPreferences ("widget_data")
                                                   → NavAlertManager (ses uyarisi)
                                                   → IBKRWidgetProvider (home screen widget)
                 → MainActivity (QuoteBridge)      → WebView (index.html)
                                                   → SharedPreferences (ayni)
```

### Ana Prensip
- **App (MainActivity)**: Tum network islemleri burada yapilir
- **Widget (IBKRWidgetProvider)**: SIFIR network cagrisi - sadece SharedPreferences okur
- **Neden?** Widget context'inde Yahoo session (cookie+crumb) guvenilir calismiyordu. App her zaman basarili, widget surekli basarisiz oluyordu. Cozum: App veriyi hazirlar, widget sadece okur.

## Dosya Detaylari

### MainActivity.java (415 satir)
- WebView ile `file:///android_asset/index.html` yukler
- `QuoteBridge` sinifi `@JavascriptInterface` ile JS'ten cagirilir
- `fetchQuotes(symbolsCsv)`: JS'ten gelen sembol listesini ceker
  1. `YahooSession.init()` ile cookie+crumb hazirla
  2. Her sembol icin `fetchYahooQuote()` cagir
  3. VIX'i de cek (`%5EVIX`)
  4. `saveToWidgetPrefs()` ile NAV hesapla ve SharedPreferences'a kaydet
  5. `navAlertManager.checkAndAlert(liveNAV)` ile sesli uyari kontrol
  6. Widget'a broadcast gonder
  7. JSON'u WebView'a `onQuotesReceived()` ile ilet
- `deriveMarketState()`: `currentTradingPeriod` JSON objesinden REGULAR/PRE/POST/CLOSED durum cikarir
- `onBackPressed()`: App'i kapatmaz, `moveTaskToBack(true)` ile arka plana atar

#### Portfoy Sabitleri (ibkr_update.ps1 gunceller)
```java
static final double[][] TICKERS = {
    { 2153,       183.14, 125.1172 },   // NVDA: qty, embedPrice, avgCost
    { 372.0852,   597.26, 486.7171 },   // QQQ
    { 3028.245,    39.95,  39.9111 },   // IBIT
    { 741.0487,    95.65,  74.5799 },   // IAU
    { 1237.7538,   38.02,  58.2506 },   // NVO
    { 1218,        30.90,  45.1075 }    // SMCI
};
static final String[] SYMBOLS = { "NVDA", "QQQ", "IBIT", "IAU", "NVO", "SMCI" };
static final double LAST_KNOWN_NAV = 893040;
```

#### Avrupa Borsa Sembolleri (US kapali iken gosterge verisi)
```java
// MainActivity.java ve PortfolioService.java'da tanimli
static final String[][] EU_SYMBOLS = {
    { "NVDA", "NVD.F",     "EUR" },   // Frankfurt
    { "QQQ",  "EQQQ.DE",   "EUR" },   // XETRA (Nasdaq-100 UCITS ETF)
    { "NVO",  "NOVO-B.CO", "DKK" },   // Kopenhag
    { "SMCI", "MS51.F",    "EUR" }    // Frankfurt
    // IBIT -> BTC-USD, IAU -> GC=F (ayri cekilir)
};
```
EU verileri NAV hesabina KARISMAZ - sadece gosterge olarak `NVDA_EU`, `QQQ_EU` vb. key'lerle saklanir.

#### NAV Hesabi
```
embedPosValue = sum(qty * embedPrice) for each ticker
cash = LAST_KNOWN_NAV - embedPosValue
livePosValue = sum(qty * livePrice) for each ticker (fallback: embedPrice)
liveNAV = cash + livePosValue
navDayChg = sum((livePrice - prevClose) * qty)
navDayPct = (navDayChg / prevCloseNAV) * 100
```

#### Portfolio hourTrend Hesabi
```
Her ticker icin hourTrend (-1/0/+1) pozisyon degerine (qty * price) gore agirliklanir:
weightedTrend = sum(hourTrend * posValue) / sum(posValue)

weightedTrend > 0.05  → ▲ yesil (#10B981)  yukselis
weightedTrend < -0.05 → ▼ kirmizi (#EF4444) dusus
arada                  → ▸ gri (#64748B)    yatay
```
hourTrend her ticker icin `parseYahooResponse()` icerisinde 1 dakikalik close verilerinden hesaplanir:
- Son fiyat ile ~60 dakika onceki fiyat karsilastirilir
- %0.03'ten buyuk degisim → +1 (yukselis) veya -1 (dusus), aksi halde 0

#### SharedPreferences Kaydi ("widget_data")
```java
putString("nav_text", "$912,345")        // Formatli NAV
putString("change_text", "-$11,062 -1.20%") // Formatli degisim
putInt("change_color", 0xFFEF4444)       // Yesil veya kirmizi
putInt("vix_color", 0xFFFBBF24)          // VIX rengi
putString("trend_arrow", "▲")            // Trend ok isareti (▲/▼/▸)
putInt("trend_color", 0xFF10B981)        // Ok rengi (yesil/kirmizi/gri)
putLong("timestamp", System.currentTimeMillis())
```

### PortfolioService.java (473 satir)
- **Foreground Service** - arka planda calisir, app kapansa bile devam eder
- Kendi timer'i ile Yahoo Finance'ten veri ceker (WebView'a bagimli degil)
- `START_STICKY` - sistem oldurunce yeniden baslar
- Bildirim kanali: `ibkr_portfolio` (IMPORTANCE_LOW, ses/titresim yok)
- Bildirim ID: 1001 (ongoing, silent)
- Bildirim icerigi: `▼ $833K` (title) + `-$19,930 -2.34% ● VIX 21.5 ● ₿66.5K -2.4% ● Au4654 -3.3% ● NQ23838 -1.5%` (body)
- Bildirim accent rengi: yesil (kar) veya kirmizi (zarar)
- **Status bar text icon**: NAV degeri ("833") bitmap olarak render edilir, status bar'da ikon yerine metin gosterilir
- Tiklaninca MainActivity acilir

#### Samsung Now Bar / AOD Destegi

**Cift katmanli yaklasim** (buildNotification metodu):

1. **Android 16+ (API 36)**: `Notification.ProgressStyle` kullanilir
   - Samsung One UI 8 bu stili otomatik olarak Now Bar'a ve AOD'ye yansitir
   - `Notification.Builder` (native, NotificationCompat degil)
   - `ProgressStyle.Point(5000)` ile ilerleme noktasi
   - contentTitle ve contentText Now Bar'da gosterilir

2. **Eski API (<36)**: `NotificationCompat.Builder` ile standart bildirim

Her iki durumda da **Samsung Bundle extras** eklenir:
```java
extras.putInt("android.ongoingActivityNoti.style", 1);
extras.putString("android.ongoingActivityNoti.primaryInfo", title);
extras.putString("android.ongoingActivityNoti.secondaryInfo", body);
extras.putInt("android.ongoingActivityNoti.chipBgColor", accentColor);
extras.putString("android.ongoingActivityNoti.chipExpandedText", title);
extras.putString("android.ongoingActivityNoti.nowbarPrimaryInfo", title);
extras.putString("android.ongoingActivityNoti.nowbarSecondaryInfo", body);
```

**AndroidManifest.xml'de Samsung destegi**:
```xml
<meta-data
    android:name="com.samsung.android.support.ongoing_activity"
    android:value="true" />
```

#### Guncelleme Stratejisi (24 SAAT - Gece Modu YOK)
| Durum | Interval | UTC Saati |
|-------|----------|-----------|
| Borsa acik | **60 saniye** | 13:30-21:00 UTC (hafta ici) |
| Pre/Post market | **5 dakika** | 08:00-13:30 ve 21:00-24:00 UTC |
| Gece | **10 dakika** | 00:00-08:00 UTC |
| Hafta sonu | **15 dakika** | Cumartesi + Pazar |

**NOT**: Gece modu KALDIRILDI (2 Nisan 2026). 24 saat kesintisiz veri cekimi yapilir.
Gece BTC-USD (7/24), GC=F (23/5) ve NQ=F (23/5) canli veri saglar.

#### Akis
```
PortfolioService timer → YahooSession.init()
    → fetchYahooQuote() x6 (US semboller)
    → US CLOSED ise: Avrupa borsalarindan gosterge verisi cek (EU_SYMBOLS)
        → EUR/USD, DKK/USD kur cek → EUR/DKK→USD donusumu
        → "NVDA_EU", "QQQ_EU" vb. key'lerle ayri sakla (NAV'a KARISMAZ)
    → VIX + BTC-USD + GC=F + NQ=F cek
    → computeAndNotify()
        → NAV hesapla (sadece US fiyatlariyla)
        → NavAlertManager.checkAndAlert()
        → SharedPreferences guncelle (+ underlying_data JSON)
        → Home screen widget broadcast
        → Bildirim guncelle (text icon + ProgressStyle + Now Bar extras)
```

#### Underlying Asset Entegrasyonu
`fetchAndUpdate()` icerisinde US sembollerden sonra cekilir:
| Sembol | Aciklama | Aktif | Iliskili ETF |
|--------|----------|-------|-------------|
| BTC-USD | Bitcoin spot | 7/24 | IBIT |
| GC=F | Altin futures | 23/5 | IAU |
| NQ=F | Nasdaq 100 futures | 23/5 | NVDA/QQQ/SMCI |

Bildirim body format: `+$1,234 +0.15% ● VIX 16.2 ● ₿83.5K +1.2% ● Au3042 -0.3% ● NQ19850 +0.8%`

#### KRITIK: ProgressStyle API Detaylari
- `Notification.ProgressStyle` sadece API 36+ cihazlarda kullanilir (`Build.VERSION.SDK_INT >= 36`)
- `Notification.Builder` native API kullanilir (NotificationCompat DEGIL, cunku ProgressStyle native)
- Native `Notification.Builder`'da `setSilent()` YOKTUR - kanal zaten IMPORTANCE_LOW oldugu icin sessiz
- `ProgressStyle.Point(int position)` constructor'i tek parametre alir (String label YOK)
- try/catch ile sarmalanir, hata olursa NotificationCompat fallback'e duser

### IBKRWidgetProvider.java (75 satir)
- `AppWidgetProvider` extend eder
- **Sifir network kodu** - sadece SharedPreferences okur
- `updateFromPrefs()`: "widget_data" prefs'ten nav_text, change_text, trend_arrow, renkleri okur, RemoteViews'a yazar
- Veri yoksa: `"$---,---"` ve `"app'i ac"` gosterir
- Tiklaninca: `MainActivity` acilir (PendingIntent.getActivity)
- Widget broadcast geldiginde ve `onUpdate`'te guncellenir

### YahooSession.java (270 satir)
- 3 katmanli cache: Memory → SharedPreferences ("yahoo_session") → Network
- 30 dakika TTL
- 3 fallback network yontemi:
  1. `fc.yahoo.com` + manual cookie extraction
  2. `CookieManager` (otomatik cookie)
  3. `finance.yahoo.com/quote/AAPL` sayfasi
- Crumb: `query2` ve `query1` denenir
- User-Agent: `Mozilla/5.0 (Linux; Android 14; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36`
- `getCookie()`, `getCrumb()`, `getCrumbEncoded()`, `getUserAgent()` static getter'lar
- `invalidate(context)`: Session'i sifirlar (memory + prefs temizler)
- **Sadece MainActivity ve PortfolioService tarafindan kullanilir** (widget KULLANMAZ)

### NavAlertManager.java (227 satir)
- 1 saat onceki NAV'a gore %1 degisim oldugunda sesli uyari
- **Sinusoidal ton uretimi** (AudioTrack, PCM 16-bit, 44100Hz):
  - Yukselis (>=+1%): 440Hz → 880Hz (yukselen iki nota, "da-DIN!")
  - Dusus (<=-1%): 880Hz → 440Hz (dusen iki nota, "DIN-da...")
- 10ms fade in/out (baslangic/bitis tiklamasi yok)
- 30ms nota arasi bosluk
- `USAGE_ALARM` kullanir (sessiz modda bile calar)
- 30 dakika cooldown (surekli calmayi onler)
- NAV gecmisi SharedPreferences'ta ("nav_alert") JSON array olarak tutulur
- Son 2 saat kayit tutulur, eski kayitlar temizlenir
- 1 saat onceki en yakin kayit bulunur (10 dk tolerans)

#### Sabitler
```java
THRESHOLD_PCT = 1.0        // %1 esik
LOOKBACK_MS = 3600000      // 1 saat
COOLDOWN_MS = 1800000      // 30 dakika
MAX_HISTORY_MS = 7200000   // 2 saat gecmis tut
SAMPLE_RATE = 44100        // 44.1kHz
```

### widget_layout.xml
- Dikey LinearLayout (widget_root), seffaf arka plan
- **Ust kisim** (weight=2): Yatay LinearLayout
  - Trend ok (widget_trend_arrow): 22sp bold, 30dp sabit genislik, renkli (▲ yesil / ▼ kirmizi / ▸ gri)
  - NAV degeri (widget_nav): 28sp bold, autoSize 14-28sp, beyaz
- **Alt kisim** (weight=2): Yatay LinearLayout
  - VIX nokta (widget_vix_dot): 32sp, 30dp sabit genislik, ● unicode, renkli
  - Degisim yazisi (widget_change): 15sp, autoSize 10-15sp, kirmizi/yesil
- Trend ok ve VIX noktasi ayni sabit genislik (30dp) ile dikey hizali
- Her element `layout_gravity="center_vertical"` + `gravity="center"` ile hizali
- Golge efektleri (shadowColor, shadowDx, shadowDy, shadowRadius)

### widget_info.xml
- `minWidth="180dp"` `minHeight="80dp"` (2x1 widget boyutu)
- `updatePeriodMillis="1800000"` (30 dakika)
- `resizeMode="horizontal|vertical"`

### widget_bg.xml
- Seffaf dikdortgen shape (`#00000000`)

### VIX Renk Kodlari
| VIX Degeri | Renk | Anlam | Hex |
|------------|------|-------|-----|
| < 12 | Yesil | Sakin | `#10B981` |
| 12-16 | Mavi | Normal | `#3B82F6` |
| 16-25 | Sari | Volatil | `#FBBF24` |
| > 25 | Kirmizi | Panik | `#EF4444` |

### index.html (WebView Dashboard)
- `file:///android_asset/index.html`
- Koyu tema (arka plan: #0A0E17)
- 7 kolonlu grid layout: `Ok | Sembol | Gunluk% | Gunluk$ | Maliyet% | Maliyet$ | Fiyat`
- Grid: `12px auto 52px 62px 52px 70px 60px` (gap:0, padding:4px)
- Sembol kolonu `auto`: icerige gore daralir, rakamlar sembole yakin durur
- hourTrend oklari (▲/▼/▸) ayri ilk kolon olarak sembolun **solunda** (dikey hizali)
- PORTFOY satirinda trend kolonu bos (yer tutucu)
- JS'ten `Android.fetchQuotes("NVDA,QQQ,IBIT,IAU,NVO,SMCI")` cagrilir
- `onQuotesReceived(json)` callback ile sonuclar alinir
- PORTFOY satiri (mavi): toplam NAV + gunluk ve maliyet bazli K/Z
- 6 emtia satiri: fiyat, gunluk degisim, maliyet bazli K/Z
- **Underlying asset kutulari**: ₿ BTC, Au GOLD, NQ NASDQ (borsa kapaliyken "CANLI" etiketi)
- **EU gosterge etiketi**: Ticker yaninda sari "EU +0.8%" (borsa kapaliyken)
- **NAV gunluk degisim**: Per-ticker `(price - prevClose) * qty` toplami (LAST_KNOWN_NAV ile karsilastirma DEGIL)
- Footer: Son guncelleme saati + borsa durumu (Acik/Pre-Market/After-Hours/Kapali/**Avrupa Borsa**)
- 60 saniye interval + manuel refresh butonu
- Fallback: Android bridge yoksa dogrudan fetch (CORS izin veriyorsa)

#### Font Boyutlari
| Eleman | Boyut |
|--------|-------|
| Sembol | 14px bold |
| Trend ok | 10px |
| Gunluk%, Maliyet% | 12px bold |
| Gunluk$, Maliyet$, Fiyat | 11px |

#### Embed Edilmis Degerler (index.html icinde)
```javascript
var TICKERS = [
    { sym:'NVDA', color:'#76b900', qty:2153,      embedPrice:183.14, avgCost:125.1172 },
    { sym:'QQQ',  color:'#8b5cf6', qty:372.0852,  embedPrice:597.26, avgCost:486.7171 },
    { sym:'IBIT', color:'#f7931a', qty:3028.245,   embedPrice:39.95,  avgCost:39.9111  },
    { sym:'IAU',  color:'#ffd700', qty:741.0487,   embedPrice:95.65,  avgCost:74.5799  },
    { sym:'NVO',  color:'#e74c3c', qty:1237.7538,  embedPrice:38.02,  avgCost:58.2506  },
    { sym:'SMCI', color:'#00bcd4', qty:1218,       embedPrice:30.90,  avgCost:45.1075  }
];
var LAST_KNOWN_NAV = 893040;
var NET_DEPOSIT = 710882;
```

### AndroidManifest.xml
```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_SPECIAL_USE" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<application ...>
    <!-- Samsung Now Bar destegi -->
    <meta-data
        android:name="com.samsung.android.support.ongoing_activity"
        android:value="true" />

    <activity android:name=".MainActivity" ... />

    <service android:name=".PortfolioService"
        android:exported="false"
        android:foregroundServiceType="specialUse">
        <property
            android:name="android.app.PROPERTY_SPECIAL_USE_FGS_SUBTYPE"
            android:value="portfolio_monitoring" />
    </service>

    <receiver android:name=".IBKRWidgetProvider" ...>
        <intent-filter>
            <action android:name="android.appwidget.action.APPWIDGET_UPDATE" />
        </intent-filter>
        <meta-data android:name="android.appwidget.provider" android:resource="@xml/widget_info" />
    </receiver>
</application>
```

## Samsung Now Bar / AOD Implementasyonu

### Tarihce
1. **Samsung Bundle extras yaklasimi**: `android.ongoingActivityNoti.*` Bundle key'leri eklendi. Samsung whitelist gerektiriyordu, ucuncu parti uygulamalar icin calismadi.
2. **Developer option "Live notifications for all apps"**: Ayarlardan acildi ama etkisi olmadi.
3. **Android 16 ProgressStyle**: `Notification.ProgressStyle` API 36 ile eklendi. Samsung One UI 8, bu stili otomatik olarak Now Bar'a ve AOD'ye yansitir.

### Status Bar Text Icon
NAV degeri ("833") status bar'da ikon yerine metin olarak gosterilir:
```java
// createTextIcon("833") - bitmap render
float density = getResources().getDisplayMetrics().density;
int h = (int) (48 * density);  // Status bar saat boyutuna yakin
Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
paint.setTypeface(Typeface.create("sans-serif-black", Typeface.BOLD));
paint.setTextSize(h * 0.95f);
paint.setFakeBoldText(true);
// Bitmap.Config.ALPHA_8 kullanilir (sistem renk uygular)
```
- `buildNotification(title, body, accentColor, statusText)` 4. parametre ile cagirilir
- `statusText` = `"833"` (navShort, K olmadan)
- Status bar boyutu Android tarafindan sinirlanir (~24dp), daha buyuk yapilamaz

### Guncel Implementasyon (cift katmanli)
```java
// Status bar text icon
Icon textIcon = createTextIcon(statusText); // "833" → bitmap

// API 36+: Native Notification.Builder + ProgressStyle
if (Build.VERSION.SDK_INT >= 36) {
    Notification.ProgressStyle progressStyle = new Notification.ProgressStyle();
    progressStyle.addProgressPoint(new Notification.ProgressStyle.Point(5000));

    Notification.Builder nb = new Notification.Builder(this, CHANNEL_ID);
    if (textIcon != null) nb.setSmallIcon(textIcon);
    else nb.setSmallIcon(R.drawable.ic_transparent);
    
    Notification notification = nb
        .setContentTitle(title)    // "▼ $833K"
        .setContentText(body)      // "-$19,930 -2.34% ● VIX 21.5 ● ₿66.5K -2.4% ● Au4654 ..."
        .setOngoing(true)
        .setColor(accentColor)
        .setStyle(progressStyle)
        .build();
    // + Samsung Bundle extras eklenir
}

// API <36: NotificationCompat.Builder (fallback, ic_transparent)
// + Samsung Bundle extras eklenir
```

### Now Bar Icin Gereklilikler
- Telefon: Samsung, One UI 8 (Android 16)
- Ayarlar > Kilit Ekrani > Now Bar: Acik olmali
- Gelistirici seceneklerinde "Live notifications for all apps" acik olmali (opsiyonel)
- Uygulama bildirim izni verilmis olmali

## Bilinen Sorunlar ve Cozumleri

### 1. Widget "session hata" / Stale Veri
**Sorun**: Widget kendi basina Yahoo session kuramiyordu, her APK yuklemede bozuluyordu.
**Cozum**: Widget'tan tum network kodu kaldirildi. App SharedPreferences'a yazar, widget sadece okur.

### 2. VIX Noktasi Gorunmuyor
**Sorun**: 72sp nokta 40dp widget'a sigmiyordu (clip ediliyordu).
**Cozum**: Widget boyutu 180x80dp, nokta 32sp, `includeFontPadding="false"` + `layout_gravity="center_vertical"`.

### 3. Incremental Build Bozulmasi
**Sorun**: XML degisikliklerinden sonra `mergeDebugResources` hatasi.
**Cozum**: `debug_build.ps1` her seferinde `app/build` siler.

### 4. Yahoo Finance CORS / Auth
**Sorun**: Yahoo v8 chart API cookie+crumb gerektirir.
**Cozum**: `YahooSession.java` ile 3 fallback yontem. Widget'ta degil, sadece app'te ve service'te kullanilir.

### 5. prevClose Hatasi (DUZELTILDI - 2 Nisan 2026)
**Sorun v1 (18 Sub)**: `range=2d` ile after-hours kapanisi aliniyordu, regular close degil.
**Sorun v2 (25 Sub)**: CLOSED durumda `prevClose = regularMarketPrice` yapildi → gunluk degisim 0% gosteriyordu.
**Sorun v3 (2 Nis)**: Yahoo `previousClose`/`chartPreviousClose` IBKR'nin prevClose'u ile eslesmiyor. Widget -13K gosterirken IBKR -19K gosteriyordu.
**Cozum (GUNCEL)**: Market state'e gore prevClose secimi:
- `PRE` veya `CLOSED` durumda: `regularMarketPrice` kullanilir (dunku 16:00 ET kapanis = IBKR ile ayni)
- `REGULAR` veya `POST` durumda: `previousClose || chartPreviousClose || regularPrice`
- Bu mantik `MainActivity.java` ve `PortfolioService.java`'da `parseYahooResponse()` icerisinde uygulanir
- **Sonuc**: Widget gunluk degisimi IBKR ile birebir eslesiyor

### 6. ProgressStyle Derleme Hatalari
**Sorun 1**: `Point(5000, title)` - Point constructor'i tek parametre alir (int), String kabul etmez.
**Cozum**: `Point(5000)` kullan.

**Sorun 2**: `setSilent(true)` - Native `Notification.Builder`'da yok (sadece NotificationCompat'ta var).
**Cozum**: Kanal zaten IMPORTANCE_LOW, `setSilent()` gerekli degil. Kaldirildi.

### 7. AGP 8.7.3 + compileSdk 36 Uyari
**Sorun**: "We recommend using a newer Android Gradle plugin to use compileSdk = 36" uyarisi.
**Cozum**: Build basarili, uyari gozardi edilebilir. `android.suppressUnsupportedCompileSdk=36` gradle.properties'e eklenebilir.

### 8. Samsung Now Bar Whitelist
**Sorun**: Samsung Bundle extras yaklasimi ucuncu parti uygulamalar icin calismadi (whitelist).
**Cozum**: Android 16 ProgressStyle API kullanildi. One UI 8 bu standart API'yi otomatik olarak Now Bar'a yansitir.

## Portfoy Sabitleri Guncelleme

TICKERS dizisindeki degerler (qty, embedPrice, avgCost) ve LAST_KNOWN_NAV degeri `ibkr_update.ps1` tarafindan guncellenir. Manuel guncelleme gerektiginde:

1. `MainActivity.java`'daki TICKERS dizisini guncelle
2. `LAST_KNOWN_NAV` degerini guncelle
3. `index.html`'deki embedded degerler de guncellenebilir (WebView icin)
4. `IBKRWidgetProvider.java`'da deger YOK (widget sadece prefs okur)
5. Build ve APK olustur

## Bagimliliklar

```groovy
implementation 'androidx.appcompat:appcompat:1.6.1'
implementation 'androidx.webkit:webkit:1.8.0'
```

## Test Adimlari

1. `debug_build.ps1` ile build
2. `IBKRWidget.apk` telefondan yukle
3. App'i ac - bildirim izni istesin, kabul et
4. Bildirim cubugundan "Portfoy Takip" bildirimi gorunmeli
5. Bildirim `▲ 921K` + `+$8,123 +0.89% ● VIX 16.2 ● ₿98.5K +1.5%` gostermeli
6. App'i kapat - bildirim hala gorunur olmali (foreground service)
7. Home screen'e widget ekle (IBKR Widget)
8. Widget'ta trend ok (▲/▼/▸) + NAV ve VIX noktasi gorunmeli
9. Ok ve VIX noktasi dikey hizali olmali
10. Widget'a tikla - app acilmali
11. Ekrani kapat - AOD/Now Bar'da NAV gorunmeli (Samsung One UI 8)
12. 60 saniye bekle (borsa aciksa) - bildirim guncellenmeli
13. 1+ saat acik birak, %1 hareket olursa sesli uyari gelmeli

### Samsung Now Bar Test Adimlari
1. Ayarlar > Kilit Ekrani > Now Bar > Acik
2. (Opsiyonel) Gelistirici secenekleri > Live notifications for all apps > Acik
3. Uygulamayi yukle ve calistir
4. Bildirim gelsin, ekrani kapat
5. AOD'da Now Bar'da NAV degeri gorunmeli
6. Kilit ekraninda da Now Bar gorunmeli

## Degisiklik Gecmisi

### 31 Temmuz 2026 - Widget'a Gun Ici 3 Seansli NAV Grafigi
- **Ne eklendi**: Gauge'un altina pre-market / regular / after-hours seanslarini tek 1 gunluk grafikte gosteren panel (IBKR Mobile ETH grafigi mantigi, ama tek sembol degil TOPLAM NAV).
- **Ek network YOK**: Yahoo `interval=1m&range=1d&includePrePost=true` yaniti zaten cekiliyordu; `timestamp[]` + `close[]` dizileri simdiye kadar atiliyordu. Artik `parseYahooResponse()` bunlari `ts`/`cl` olarak dondurur.
- **Seans sinirlari**: `meta.tradingPeriods` (veri gunune ait) oncelikli, yoksa `meta.currentTradingPeriod`. DST ve yarim gunler otomatik dogru - sabit saat KODLAMA.
- **Yeni dosya `NavSeries.java`**: preStart->postEnd araligi 180 kovaya bolunur, her ticker'in dakikalik close'lari kovalara yazilir, forward-fill, kova basina NAV -> dunku kapanisa gore yuzde. Prefs'te tek satir: `nav_series = preStart;regStart;regEnd;postEnd;p0,...,p179` (`~` = veri yok).
- **Yeni dosya `SessionChartDrawer.java`**: Seans bantlari (PRE mavi / MARKET yesil / AFTER mor), kesikli ayraclar, noktali baseline (dunku kapanis), dolgulu NAV egrisi, ucunda beyaz nokta + yuzde, altta yerel saatle 4 saat etiketi.
- **KRITIK - tek bitmap**: Grafik gauge ile AYNI bitmap'e cizilir (ayri ImageView DEGIL). Iki buyuk bitmap RemoteViews boyut limitine takilir. `GaugeDrawer.draw()` artik `chartHeight` + `NavSeries.Data` alir, gauge olculeri `height` parametresine gore hesaplanmaya devam eder.
- **KRITIK - prefs sismesi**: Ham `ts`/`cl` dizileri prefs'e yazilmamali. `stripSeries()` ile `underlying_data`'dan ayiklanir. Dogrulandi: prefs 2.8 KB.
- **Widget boyutu**: `widget_info.xml` minHeight 200->250dp, targetCellHeight 2->3. Bitmap 380x200dp -> 380x304dp. Seri yoksa `chartH=0` -> eski gorunum (guvenli geri dusus).
- **Etkilenen dosyalar**: `NavSeries.java` (yeni), `SessionChartDrawer.java` (yeni), `PortfolioService.java`, `GaugeDrawer.java`, `IBKRWidgetProvider.java`, `res/xml/widget_info.xml`
- **Tasarim dokumani**: `docs/superpowers/specs/2026-07-31-ibkr-widget-session-chart-design.md`

### 10 Temmuz 2026 - Status Bar Cipinde Buyuk Rakamlar
- **Sorun**: Status bar'daki yesil cip NAV'i "0.97" (milyon, 4 karakter) olarak 48px kare bitmap'e ciziyordu; rakamlar okunamayacak kadar kucuktu.
- **Deneme 1 (basarisiz)**: Custom bitmap kaldirilip monokrom drawable (`ic_stat_nav.xml`) + `setShortCriticalText` denendi. dumpsys dogruladi: bildirim PROMOTED_ONGOING ve shortCriticalText="968" sisteme gidiyor, AMA One UI 8.x ucuncu parti chip'te sistem metnini CIZMIYOR (sadece ikon basiliyor). Yani Samsung'da "gercek metin" yolu kapali.
- **Cozum (Plan B)**: Bitmap ikona geri donuldu, icerik kisaltildi: `statusText = round(NAV/10000)` -> "97" ($967k), 1M ustunde "102" ($1.02M). Ayrica `createTextIcon()` ic bosluklari kisildi. Sonuc: rakamlar ~2 kat buyudu, saat fontuna yakin okunurluk (ekran goruntusuyle dogrulandi).
- **Beyaz kutu fix'i (ayni gece)**: One UI bir sure sonra chip'i normal ikon alanina indirip beyaz ALFA-TINT uyguluyor -> opak yesil kutu tamamen beyaz bloga donustu. Cozum: `createTextIcon()` arka plan kutusu tamamen kaldirildi, SADECE rakam cizilir (accent renkte). Renkli chip modunda yesil/kirmizi rakam, tint'li modda beyaz rakam - her iki modda da okunur, kutu yok. KURAL: status bar icon bitmap'inde opak zemin KULLANMA (tint her seyi tek renge boyar).
- **Yeni dosya**: `res/drawable/ic_stat_nav.xml` (beyaz ucgen, bitmap uretimi basarisiz olursa fallback small icon)
- **Etkilenen dosyalar**: `PortfolioService.java` (statusText formati, buildNotification API36 yolu, createTextIcon padding)
- **Tasarim dokumani**: `docs/superpowers/specs/2026-07-10-ibkr-statusbar-chip-text-design.md`

### 2 Nisan 2026 - 24 Saat Veri Cekimi + Underlying Assets + prevClose Duzeltme
- **Gece modu KALDIRILDI**: 00:00-07:00 UTC durdurma kaldirildi, 24 saat kesintisiz veri cekimi
- **Yeni interval tablosu**: Gece 10dk, Pre/Post 5dk, Regular 60sn, Hafta sonu 15dk
- **Underlying asset'ler eklendi**: BTC-USD (7/24), GC=F altin (23/5), NQ=F Nasdaq (23/5)
- **Avrupa borsa gostergeleri**: NVD.F, EQQQ.DE, NOVO-B.CO, MS51.F (EUR/DKK→USD donusumu)
- **EU verileri NAV'a KARISMAZ**: Ayri key'lerle saklanir (`NVDA_EU` vb.), sadece gosterge
- **Dashboard underlying kutulari**: ₿ BTC, Au GOLD, NQ NASDQ bilgi kutulari, borsa kapaliyken "CANLI" etiketi
- **Dashboard EU gosterge**: Ticker yaninda sari "EU +0.8%" etiketi (borsa kapaliyken)
- **Borsa durumu EU destegi**: Footer'da "Avrupa Borsa" durumu
- **prevClose IBKR ile eslesti**: PRE/CLOSED durumda `regularMarketPrice` kullanilir (dunku 16:00 ET kapanis)
- **NAV gunluk degisim duzeltildi**: Hardcoded LAST_KNOWN_NAV yerine per-ticker (price-prevClose)*qty toplami
- **Widget gunluk degisim duzeltildi**: Ayni per-ticker prevClose mantigi
- **Status bar text icon**: NAV degeri ("833") bitmap olarak render edilir, status bar'da metin gosterilir
- **Bildirime Gold/NQ eklendi**: Body'de `● Au4654 -3.3% ● NQ23838 -1.5%` formatinda
- **Etkilenen dosyalar**: `PortfolioService.java`, `MainActivity.java`, `index.html`

### 22 Subat 2026 - BTC Bildirim + Dashboard Layout Yenileme
- **BTC-USD bildirime eklendi**: `PortfolioService.java`'da `fetchAndUpdate()` icerisinde `BTC-USD` sembolü cekilir, `computeAndNotify()` icerisinde formatlanir (`● ₿98.5K +1.5%`), bildirim body'sine VIX'ten sonra eklenir
- **Dashboard 7 kolonlu grid'e gecti**: `index.html`'de layout `12px auto 52px 62px 52px 70px 60px` (onceki: `1fr 60px 70px 55px 75px 70px`)
- **Trend oklari ayri kolon olarak sembolun soluna tasindi**: Oklari (▲/▼/▸) ayri 12px kolon, dikey hizali cizgi olusturur
- **Sembol kolonu `1fr` → `auto`**: Gereksiz bosluk kaldirildi, rakamlar sembole yakin
- **gap:0, padding:4px**: Kolonlar arasi bosluk sifirlandi, kenar bosluklari azaltildi
- **Font boyutlari arttirildi**: Sembol 13→14px, %, 11→12px, $, fiyat 10→11px
- **min-width ve overflow-x:auto kaldirildi**: Yatay scroll yok, tum veriler tek ekrana sigiyor

## IBKR Web API Entegrasyonu (PLANLANDI - Asama 2)

### Mevcut Durum
- IBKR Pro hesap mevcut (hesap no: UH576882)
- IBKR Flex Web Service token: `452568886616020091362301` (raporlama icin)
- OAuth 2.0 (beta) bireysel kullanicilar icin onay gerektirebilir

### Plan
1. IBKR'ye email at: `API-Feedback@interactivebrokers.com` - OAuth 2.0 beta erisimi iste
2. OAuth onaylaninca: `IBKRClient.java` ekle, dogrudan `api.ibkr.com` endpoint'lerine baglan
3. Market data endpoint: `GET /iserver/marketdata/snapshot?conids=...&fields=31,82,83,84,86`
4. Yahoo fallback olarak kalir, IBKR birincil kaynak olur
5. Tam 24 saat gercek fiyat (Blue Ocean ATS dahil)

### IBKR Web API Detaylari
- Base URL: `https://api.ibkr.com/v1/api/`
- Auth: OAuth 2.0 `private_key_jwt` (RFC 7521/7523)
- Rate limit: 10 req/sec global, snapshot 10 req/sec
- Session: Cookie management + `/tickle` endpoint
- Fields: 31=Last, 82=Change, 83=Change%, 84=Bid, 86=Ask
- Conid bulma: `POST /iserver/secdef/search` + symbol

### Alternatif: Oracle Cloud Free VPS + Client Portal Gateway
- Oracle Cloud daima ucretsiz ARM sunucu (4 CPU, 24GB RAM)
- IBKR Client Portal Gateway Java uygulamasini orada calistir
- Android app → VPS'ye baglanir
- PC'ye bagimli degil, 24/7 calisir

## Degisiklik Gecmisi

### 8 May 2026 - Coklu Agent Review Sonrasi Duzeltmeler

5 agent (code-review, find-bugs, simplify, sharp-edges, insecure-defaults) projenin tamamini paralel inceledi. Toplam 51 bulgu, 15'i giderildi (~80 satir azaltma).

#### KRITIK Bug Fix'leri

1. **`marketState` JSON'a yazilmiyordu** (`PortfolioService.parseYahooResponse`)
   - **Sorun**: MainActivity'de var, PortfolioService'te eksikti -> `q.has("marketState")` her zaman false -> US daima "CLOSED" sayiliyor -> EU fetch surekli tetikleniyor.
   - **Fix**: Satir 333'e `result.put("marketState", marketState);` eklendi.

2. **`fetched + "/7"` ama `SYMBOLS.length = 6`** (2 dosya)
   - **Fix**: `fetched + "/" + SYMBOLS.length` (MainActivity), `fetched + "/" + symbols.length` (PortfolioService).

3. **`scheduleNext` shutdown sonrasi `RejectedExecutionException`**
   - **Fix**: Bas tarafta `if (scheduler == null || scheduler.isShutdown()) return;` + `try/catch RejectedExecutionException`.

4. **NavAlertManager cift instance race**
   - **Sorun**: MainActivity ve PortfolioService ayri instance olusturuyor, ayni `lastAlertTime` field iki yerde -> cooldown bypass -> cift alarm.
   - **Fix**: `lastAlertTime` instance field kaldirildi, her `checkAndAlert` cagrisinda `prefs.getLong("last_alert", 0)` ile prefs'ten okunur. `saveLastAlert(now)` zaten prefs'e yaziyordu.

5. **Exception swallowing** (20+ yerde `catch { /* ignore */ }`)
   - **Fix**: `import android.util.Log` eklendi, `private static final String TAG = "PortfolioService"`. Kritik fetch path'lerinde `Log.w(TAG, "fetchYahooQuote " + sym, e)`. Ayrica yeni mantik: tum semboller fail olursa `YahooSession.invalidate(context)` -> stale crumb sonsuza dek tekrar denenmiyor. (Ayni mantik MainActivity'de "MainActivity" TAG ile.)

#### ONEMLI Duzeltmeler

6. **`AndroidManifest.xml`**: `usesCleartextTraffic="true"` kaldirildi, `allowBackup="false"` + `fullBackupContent="false"` eklendi. Yahoo zaten HTTPS, cleartext gereksizdi.

7. **WebView guvenlik sertlestirme** (`MainActivity.onCreate`)
   ```java
   settings.setAllowFileAccess(false);
   settings.setAllowContentAccess(false);
   settings.setAllowFileAccessFromFileURLs(false);
   settings.setAllowUniversalAccessFromFileURLs(false);
   settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
   webView.setWebViewClient(new WebViewClient() {
       @Override
       public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
           return !req.getUrl().toString().startsWith("file:///android_asset/");
       }
   });
   ```

8. **`GaugeDrawer.draw` bitmap cache**
   - **Sorun**: Her widget update'inde ~1MB ARGB_8888 allocate -> 60sn'de bir GC pressure.
   - **Fix**: Static `cachedBitmap` + `cachedW`/`cachedH`; ayni boyutsa `bitmap.eraseColor(0)` ile reuse, degisirse yeni allocate.

9. **EU FX rate fallback 1.0 -> 0**
   - **Sorun**: EURUSD/DKKUSD fetch fail olursa fxRate=1.0 ile carpilir -> EUR fiyat USD sayilir (~%8 hata).
   - **Fix**: Default 0, `if (fxRate <= 0) continue;` -> EU sembolu atlanir, yanlis deger gosterilmez. (2 dosyada.)

10. **`prevCloseNAV <= 0` sessiz fallback**
    - **Sorun**: navDayPct sessizce 0 doner -> kullanici "veri yok" yerine "%0 degisim" gorur.
    - **Fix**: `boolean prevCloseValid = prevCloseNAV > 0;` + `Log.w`. ChangeText: gecerli ise normal format, degilse `"veri yok"`. (2 dosyada.)

#### KOZMETIK Temizlik

11. **`trendWeight`/`totalWeight` olu kod silindi** (~14 satir, 2 dosya). Trend ok `navDayPct`'e gore atanir, hourTrend agirlikli hesap zaten kullanilmiyordu.

12. **`JSONArray timestamps` kullanilmayan degisken silindi** (2 dosya).

13. **`createTextIcon` metodu + `statusText` parametresi tamamen silindi**. Her zaman `null` geciliyor, dead code (~25 satir).

14. **`buildNotification` 3-param overload silindi**, 4-param 3-param oldu. 2-param hala 3-param'a delegate.

15. **Magic number sabitleri** (`PortfolioService` sinifi basinda):
    ```java
    static final int COLOR_GREEN = 0xFF10B981;
    static final int COLOR_RED = 0xFFEF4444;
    static final int COLOR_GRAY = 0xFF64748B;
    static final int COLOR_AMBER = 0xFFFBBF24;
    private static final int MARKET_OPEN_UTC_MIN = 810;   // 13:30 UTC
    private static final int MARKET_CLOSE_UTC_MIN = 1260; // 21:00 UTC
    private static final int PRE_MARKET_START_UTC_MIN = 480;
    private static final long INTERVAL_LIVE_MS = 60_000;
    private static final long INTERVAL_PRE_POST_MS = 5 * 60_000;
    private static final long INTERVAL_NIGHT_MS = 10 * 60_000;
    private static final long INTERVAL_WEEKEND_MS = 15 * 60_000;
    ```
    `getIntervalMs` 18 satir -> 9 satir.

#### Ertelenenler (gelecekte yapilacak)

- **K) NAV math duplicate** (~100 satir): `MainActivity.saveToWidgetPrefs` ve `PortfolioService.computeAndNotify` ayni hesabi yapiyor (embedPosValue + cash + livePosValue + navDayChg + navDayPct + trend + prefs yazimi + broadcast). `PortfolioMath.java` util sinifina cikar.
- **L) Yahoo fetch duplicate** (~200 satir): `fetchYahooQuote` + `parseYahooResponse` + EU dongus iki dosyada birebir. `YahooFetcher.java` static util'e cikar.

Bu refactor'lar buyuk risk tasidigi icin (test yok) ileri tarihe birakildi. Test yazmadan once dokunulmasa daha guvenli.

#### Notlar

- Build/install sonrasi service force-stop gerekli olabilir (`adb shell am force-stop com.ibkr.widget`) - eski instance yeni kodu okumayabilir.
- `prefs.xml`'de `vix_change_pct` field eklendi (8 May VIX renk degisikligi icin).

### 8 May 2026 - 4x2 Widget Redesign (Logolar + TWR + App-Icon Stili)

#### Widget Boyutu
- **4x2** (320x200dp): `widget_info.xml` - `minWidth=320`, `minHeight=200`, `targetCellWidth=4`, `targetCellHeight=2`
- Bitmap: 380x200dp (density-scaled), `IBKRWidgetProvider.java`
- **Eski 2x1 widget kaldirilip yeniden eklenmeli** - boyut degisiklikleri mevcut widget'a uygulanmaz

#### Gauge Geometri Sabitleri (`GaugeDrawer.java`)
```java
cx = width / 2f;
cy = height * 0.55f;       // Asagi - arc'in ustunde alan kalsin
radius = height * 0.46f;
```
- Trend ok: font `radius * 0.28f`, pozisyon `cy - radius * 0.65f` (arc'in icinde, renkli bara degmeden)
- Total return %: font `radius * 0.16f`, pozisyon `cy - radius * 0.40f`
- NAV text: font `radius * 0.30f`, pozisyon `cy + radius * 0.62f`
- Change text: font `radius * 0.19f`, pozisyon `cy + radius * 0.85f`

#### Yan Panel (Logolar + Gunluk %)
- **Sol kolon (snake order, pozisyon buyuk -> kucuk)**: NVDA, IBIT, NVO
- **Sag kolon (snake order)**: QQQ, IAU, SMCI
- Panel genisligi: `width * 0.24f` (her tarafta)
- Row yuksekligi: `height / 3f` (3 satir)
- Logo boyutu: `min(rowH * 0.55, panelW * 0.32)`
- % font: `rowH * 0.22f` (gauge'in ic +%XX yazisiyla ayni boyut)
- % rengi: pozitif ise yesil `0xFF10B981`, negatif ise kirmizi `0xFFEF4444`

#### Logo Kaynaklari
Konum: `app/src/main/res/drawable-nodpi/`
| Dosya | Kaynak |
|-------|--------|
| `logo_nvda.png` | `https://financialmodelingprep.com/image-stock/NVDA.png` |
| `logo_qqq.png`  | `https://financialmodelingprep.com/image-stock/QQQ.png` |
| `logo_ibit.png` | `https://cryptologos.cc/logos/bitcoin-btc-logo.png` (Bitcoin) |
| `logo_iau.png`  | `https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/1fa99.png` (gold coin emoji) |
| `logo_nvo.png`  | `companiesmarketcap.com/img/company-logos/256/NVO.png` -> beyaza cevirilmis |
| `logo_smci.png` | `https://financialmodelingprep.com/image-stock/SMCI.png` |

`src_*.png` yedek olarak orijinaller (app-icon stiline cevirmeden once).

#### App-Icon Stili Logo Generator
PowerShell + System.Drawing ile programatik olusturuldu:
- 256x256 PNG, %22 yuvarlak kose (iOS squircle yaklasimi)
- Marka rengi gradient arka plan (top -> bottom)
- Logo ortali, %18-20 padding
- Aspect ratio korunarak fitCenter

| Ticker | BG Renk Birinci | BG Renk Ikinci |
|--------|-----------------|----------------|
| NVDA | `#000000` | (solid siyah) |
| QQQ  | `#1E4A9C` | `#0F2A5C` (Invesco navy) |
| IBIT | `#FFB54D` | `#F7931A` (Bitcoin turuncu) |
| IAU  | `#FFD700` | `#B8860B` (altin) |
| NVO  | `#003399` | `#001965` (Novo Nordisk navy) |
| SMCI | `#0099FF` | `#0066CC` (mavi) |

NVO orijinal logosu lacivert oldugu icin once `[System.Drawing]` ile pikselleri beyaza cevrilir, sonra app-icon olarak gradient mavi bg uzerine bindirilir.

#### TWR Kalibrasyon Mantigi
- IBKR'in TWR (Time-Weighted Return) hesabi cash-on-cash'ten farklidir (para giris/cikislari icin asindirilir)
- Bizim formul (cash-on-cash): `(liveNAV - NET_DEPOSIT) / NET_DEPOSIT * 100`
- Daha dogru cozum (TWR carpimsal):
```java
static final double ANCHOR_NAV = 1030000;        // O an IBKR ekraninda gorunen NAV
static final double ANCHOR_TWR_FACTOR = 1.5343;  // O andaki IBKR TWR (53.43% -> 1.5343)
totalReturnPct = ((liveNAV / ANCHOR_NAV) * ANCHOR_TWR_FACTOR - 1) * 100;
```
- **Kisitlama**: Yeni para giris/cikisinda anchor guncellenmeli
- IBKR'da gorunen toplam getiri ile bire bir uyumlu

#### Trend Ok Davranisi (DEGISTI)
- **Eski**: hourTrend (son 60 dk agirlikli pozisyon trendi) -> bazen notr (▸ gri yatay) goruntu
- **Yeni**: `navDayPct >= 0 ? "▲" : "▼"` - her zaman yon gosterir, gunluk degisime gore
- Kaldirilan kod: `if (totalWeight > 0) { weightedTrend > 0.05 ... }` blogu

#### Per-Symbol Pct SharedPreferences
`MainActivity.buildSymbolPctJson(allResults)` - JSON formatinda her sembolun gunluk %'si:
```json
{"NVDA": 2.1, "QQQ": 1.5, "IBIT": -0.3, "IAU": 0.2, "NVO": 0.5, "SMCI": 3.1}
```
`prefs.putString("symbol_pcts", ...)` - widget yan panelleri bunu okur.

#### IBKR Pozisyon Adetleri (8 May 2026)
| Ticker | Adet | Embed Price | Avg Cost |
|--------|------|-------------|----------|
| NVDA | 2153 | 183.14 | 125.1172 |
| QQQ  | 372.0852 | 597.26 | 486.7171 |
| IBIT | **3059.235** (eski 3028.245) | 39.95 | 39.9111 |
| IAU  | 741.0487 | 95.65 | 74.5799 |
| NVO  | 1237.7538 | 38.02 | 58.2506 |
| SMCI | 1218 | 30.90 | 45.1075 |

`LAST_KNOWN_NAV = 894360` (eski 893040, IBIT alimi sonrasi guncellendi)

#### Notification Chip (Samsung Live Notifications)
- Status bar metni: sadece NAV/1000 (orn "1030") - `String.format("%.0f", liveNAV / 1000)`
- Ok ve $ kaldirildi - chip kapsulu icin temiz format
- Chip rengi: `changeColor` (gunluk degisime gore yesil/kirmizi) - mor sabit kaldirildi
- ProgressStyle + Samsung extras + `enable_notification_nowbar_test=1` (ADB ile geliştirici test modu)
- Komut: `adb shell settings put secure enable_notification_nowbar_test 1`

#### Logo Yeniden Olusturma Workflow
```powershell
# Logoyu indir (FMP, Twemoji, vb)
Invoke-WebRequest -Uri $url -OutFile "src_<ticker>.png"

# App-icon stiline cevir (256x256, %22 corner, gradient bg)
# Bkz. detayli script: brand renk + padding + DrawImage compose
```

### 12 Mart 2026 - prevClose After-Hours Duzeltmesi
- **CLOSED ozel durumu kaldirildi**: `prevClose = regularMarketPrice` mantigi cikarildi (0% gosteriyordu)
- **Tum state'lerde ayni prevClose**: `chartPreviousClose || previousClose || regularPrice`
- **currentPrice closes dizisinden**: After-hours son fiyatini icerir
- **Etkilenen dosyalar**: `MainActivity.java`, `PortfolioService.java`, `index.html`
- **Sonuc**: Borsa kapandiktan sonra da gunluk performans (AH dahil) gorunur

### 18 Subat 2026 - prevClose Duzeltmesi (Regular Market Close)
- **range=2d → range=1d**: Yahoo Finance API URL'i degistirildi
- **prevClose mantigi degistirildi**: `meta.chartPreviousClose` kullaniliyor
- **Win 11 Electron widget ile uyum**: Ayni prevClose mantigi

### 12 Subat 2026 - Android 16 / Now Bar Destegi
- **SDK 36 kuruldu**: `C:\Android\platforms\android-36`
- **Gradle 8.5 → 8.11.1**: AGP 8.7.3 destegi icin gerekli
- **AGP 8.2.2 → 8.7.3**: compileSdk 36 destegi
- **compileSdk/targetSdk 34 → 36**: Android 16 API'leri (ProgressStyle)
- **ProgressStyle eklendi**: `PortfolioService.java`'da API 36+ icin `Notification.ProgressStyle` kullanilir
- **Samsung Bundle extras eklendi**: `android.ongoingActivityNoti.*` key'leri
- **Manifest guncellendi**: `com.samsung.android.support.ongoing_activity` meta-data eklendi
- **FGS subtype eklendi**: `PROPERTY_SPECIAL_USE_FGS_SUBTYPE = portfolio_monitoring`
