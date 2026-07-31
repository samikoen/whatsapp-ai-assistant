package com.ibkr.widget;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.appwidget.AppWidgetManager;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.os.Build;
import android.os.Bundle;
import android.os.IBinder;
import android.util.Log;

import androidx.core.app.NotificationCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Calendar;
import java.util.TimeZone;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

public class PortfolioService extends Service {

    private static final String TAG = "PortfolioService";
    private static final String CHANNEL_ID = "ibkr_portfolio_v2";
    private static final int NOTIFICATION_ID = 1001;

    // Renk sabitleri (Tailwind benzeri)
    static final int COLOR_GREEN = 0xFF10B981;
    static final int COLOR_RED = 0xFFEF4444;
    static final int COLOR_GRAY = 0xFF64748B;
    static final int COLOR_AMBER = 0xFFFBBF24;

    // UTC dakika sabitleri (NYSE saatleri)
    private static final int MARKET_OPEN_UTC_MIN = 810;   // 13:30 UTC
    private static final int MARKET_CLOSE_UTC_MIN = 1260; // 21:00 UTC
    private static final int PRE_MARKET_START_UTC_MIN = 480; // 08:00 UTC

    // Guncelleme araliklari
    private static final long INTERVAL_LIVE_MS = 60_000;
    private static final long INTERVAL_PRE_POST_MS = 5 * 60_000;
    private static final long INTERVAL_NIGHT_MS = 10 * 60_000;
    private static final long INTERVAL_WEEKEND_MS = 15 * 60_000;

    private ScheduledExecutorService scheduler;
    private NavAlertManager navAlertManager;

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        navAlertManager = new NavAlertManager(this);
        scheduler = Executors.newSingleThreadScheduledExecutor();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(NOTIFICATION_ID, buildNotification(
                "\u25B8 $---,---", "Veri bekleniyor..."));

        // Ilk fetch hemen
        scheduler.execute(this::fetchAndUpdate);

        // Periyodik zamanlama
        scheduleNext();

        return START_STICKY;
    }

    // ==================== Zamanlama ====================

    private void scheduleNext() {
        if (scheduler == null || scheduler.isShutdown()) return;
        long delayMs = getIntervalMs();
        try {
            scheduler.schedule(() -> {
                fetchAndUpdate();
                scheduleNext();
            }, delayMs, TimeUnit.MILLISECONDS);
        } catch (java.util.concurrent.RejectedExecutionException ignored) {
            // Service kapaniyor, yeniden zamanlama bos
        }
    }

    private long getIntervalMs() {
        Calendar cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"));
        int dow = cal.get(Calendar.DAY_OF_WEEK);
        int timeMin = cal.get(Calendar.HOUR_OF_DAY) * 60 + cal.get(Calendar.MINUTE);

        if (dow == Calendar.SATURDAY || dow == Calendar.SUNDAY) return INTERVAL_WEEKEND_MS;
        if (timeMin >= MARKET_OPEN_UTC_MIN && timeMin <= MARKET_CLOSE_UTC_MIN) return INTERVAL_LIVE_MS;
        if (timeMin >= PRE_MARKET_START_UTC_MIN) return INTERVAL_PRE_POST_MS;
        return INTERVAL_NIGHT_MS;
    }

    // ==================== Veri Cekme ====================

    // Avrupa borsa sembolleri (US borsa kapali iken kullanilir)
    private static final String[][] EU_SYMBOLS = {
        // { US_sym, EU_Yahoo_sym, currency }
        { "NVDA", "NVD.F",     "EUR" },
        { "QQQ",  "EQQQ.DE",   "EUR" },
        { "NVO",  "NOVO-B.CO", "DKK" },
        { "SMCI", "MS51.F",    "EUR" }
        // IBIT -> BTC-USD, IAU -> GC=F (ayri cekilir)
    };

    private void fetchAndUpdate() {
        try {
            YahooSession.init(this);

            JSONObject allResults = new JSONObject();

            // 1) US sembollerini cek
            int failCount = 0;
            for (String sym : MainActivity.SYMBOLS) {
                try {
                    JSONObject quote = fetchYahooQuote(sym);
                    if (quote != null) allResults.put(sym, quote);
                    else failCount++;
                } catch (Exception e) {
                    Log.w(TAG, "fetchYahooQuote " + sym + " failed", e);
                    failCount++;
                }
            }
            // Tum semboller fail ise session bozulmus, sifirla
            if (failCount == MainActivity.SYMBOLS.length) {
                Log.w(TAG, "Tum semboller fail - YahooSession invalidate");
                YahooSession.invalidate(this);
            }

            // 2) Avrupa borsalarindan gosterge verileri cek (NAV hesabina KARISMAZ)
            //    EU verileri ayri key'lerle saklanir: "NVDA_EU", "QQQ_EU" vb.
            String usMarketState = "CLOSED";
            for (String sym : MainActivity.SYMBOLS) {
                JSONObject q = allResults.optJSONObject(sym);
                if (q != null && q.has("marketState")) {
                    usMarketState = q.optString("marketState", "CLOSED");
                    break;
                }
            }

            if ("CLOSED".equals(usMarketState)) {
                double eurUsd = 0;
                double dkkUsd = 0;
                try {
                    JSONObject eurQ = fetchYahooQuote("EURUSD%3DX");
                    if (eurQ != null) eurUsd = eurQ.optDouble("price", 0);
                } catch (Exception e) {
                    Log.w(TAG, "EURUSD fetch failed", e);
                }
                try {
                    JSONObject dkkQ = fetchYahooQuote("DKKUSD%3DX");
                    if (dkkQ != null) dkkUsd = dkkQ.optDouble("price", 0);
                } catch (Exception e) {
                    Log.w(TAG, "DKKUSD fetch failed", e);
                }

                for (String[] euSym : EU_SYMBOLS) {
                    String usSym = euSym[0];
                    String euYahoo = euSym[1];
                    String currency = euSym[2];
                    double fxRate = "EUR".equals(currency) ? eurUsd : dkkUsd;
                    // FX rate yoksa bu EU sembolunu atla (1.0 ile carparak ~%8 hata yapma)
                    if (fxRate <= 0) continue;

                    try {
                        JSONObject euQuote = fetchYahooQuote(euYahoo);
                        if (euQuote != null && euQuote.optDouble("price", 0) > 0) {
                            double euPrice = euQuote.optDouble("price", 0) * fxRate;
                            double euPrevClose = euQuote.optDouble("prevClose", 0) * fxRate;
                            double euChange = euPrice - euPrevClose;
                            double euPctChange = euPrevClose > 0 ? (euChange / euPrevClose) * 100 : 0;

                            JSONObject euData = new JSONObject();
                            euData.put("price", euPrice);
                            euData.put("prevClose", euPrevClose);
                            euData.put("change", euChange);
                            euData.put("pctChange", euPctChange);
                            euData.put("hourTrend", euQuote.optInt("hourTrend", 0));
                            euData.put("source", euYahoo);
                            // AYRI key ile sakla - NAV hesabina karismaz
                            allResults.put(usSym + "_EU", euData);
                        }
                    } catch (Exception e) { /* skip */ }
                }
            }

            // 3) VIX
            try {
                JSONObject vix = fetchYahooQuote("%5EVIX");
                if (vix != null) allResults.put("^VIX", vix);
            } catch (Exception e) { /* skip */ }

            // 4) BTC-USD (7/24 canli - IBIT underlying)
            try {
                JSONObject btc = fetchYahooQuote("BTC-USD");
                if (btc != null) allResults.put("BTC-USD", btc);
            } catch (Exception e) { /* skip */ }

            // 5) GC=F - Altin futures (23/5 canli - IAU underlying)
            try {
                JSONObject gold = fetchYahooQuote("GC%3DF");
                if (gold != null) allResults.put("GC=F", gold);
            } catch (Exception e) { /* skip */ }

            // 6) NQ=F - Nasdaq 100 futures (23/5 canli - NVDA/QQQ/SMCI underlying)
            try {
                JSONObject nq = fetchYahooQuote("NQ%3DF");
                if (nq != null) allResults.put("NQ=F", nq);
            } catch (Exception e) { /* skip */ }

            computeAndNotify(allResults);

        } catch (Exception e) {
            // Session init basarisiz, sonraki denemede tekrar dene
        }
    }

    private JSONObject fetchYahooQuote(String symbol) {
        HttpURLConnection conn = null;
        try {
            String crumbEnc = YahooSession.getCrumbEncoded();
            String cookie = YahooSession.getCookie();
            String ua = YahooSession.getUserAgent();
            URL url = new URL("https://query2.finance.yahoo.com/v8/finance/chart/"
                    + symbol + "?interval=1m&range=1d&includePrePost=true&crumb=" + crumbEnc);
            conn = (HttpURLConnection) url.openConnection();
            conn.setInstanceFollowRedirects(true);
            conn.setRequestProperty("User-Agent", ua);
            conn.setRequestProperty("Cookie", cookie);
            conn.setRequestProperty("Accept", "application/json");
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(10000);

            int code = conn.getResponseCode();
            if (code != 200) return null;

            BufferedReader reader = new BufferedReader(
                    new InputStreamReader(conn.getInputStream()));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = reader.readLine()) != null) sb.append(line);
            reader.close();

            return parseYahooResponse(sb.toString());
        } catch (Exception e) {
            return null;
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    private JSONObject parseYahooResponse(String rawJson) {
        try {
            JSONObject root = new JSONObject(rawJson);
            JSONObject chart = root.getJSONObject("chart");
            JSONArray results = chart.getJSONArray("result");
            if (results.length() == 0) return null;

            JSONObject r = results.getJSONObject(0);
            JSONObject meta = r.getJSONObject("meta");

            double regularPrice = meta.optDouble("regularMarketPrice", 0);

            // Timestamps ve closes dizileri
            double latestPrice = 0;
            JSONArray closes = new JSONArray();
            try {
                JSONObject indicators = r.getJSONObject("indicators");
                JSONArray quoteArr = indicators.getJSONArray("quote");
                closes = quoteArr.getJSONObject(0).getJSONArray("close");
                for (int i = closes.length() - 1; i >= 0; i--) {
                    if (!closes.isNull(i)) {
                        latestPrice = closes.getDouble(i);
                        break;
                    }
                }
            } catch (Exception e) { /* pass */ }

            double currentPrice = latestPrice > 0 ? latestPrice : regularPrice;

            // Market state tespiti
            String marketState = "CLOSED";
            try {
                JSONObject ctp = meta.getJSONObject("currentTradingPeriod");
                long now = System.currentTimeMillis() / 1000;
                JSONObject regular = ctp.optJSONObject("regular");
                if (regular != null && now >= regular.getLong("start") && now < regular.getLong("end"))
                    marketState = "REGULAR";
                else {
                    JSONObject pre = ctp.optJSONObject("pre");
                    if (pre != null && now >= pre.getLong("start") && now < pre.getLong("end"))
                        marketState = "PRE";
                    else {
                        JSONObject post = ctp.optJSONObject("post");
                        if (post != null && now >= post.getLong("start") && now < post.getLong("end"))
                            marketState = "POST";
                    }
                }
            } catch (Exception e) { /* CLOSED kalir */ }

            // prevClose: IBKR ile uyumlu regular session close
            double prevClose;
            if ("PRE".equals(marketState) || "CLOSED".equals(marketState)) {
                // Pre-market/kapali: regularMarketPrice = dunku regular close (IBKR ile ayni)
                prevClose = regularPrice;
            } else {
                // Regular/Post: previousClose kullan
                prevClose = meta.optDouble("previousClose",
                        meta.optDouble("chartPreviousClose", regularPrice));
            }

            if (currentPrice <= 0 || prevClose <= 0) return null;

            int hourTrend = 0;
            if (closes.length() > 10) {
                int latestIdx = -1;
                for (int i = closes.length() - 1; i >= 0; i--) {
                    if (!closes.isNull(i)) { latestIdx = i; break; }
                }
                int hourAgoIdx = Math.max(0, latestIdx - 60);
                double hourAgoPrice = 0;
                for (int i = hourAgoIdx; i <= Math.min(hourAgoIdx + 10, latestIdx - 5); i++) {
                    if (i >= 0 && i < closes.length() && !closes.isNull(i)) {
                        hourAgoPrice = closes.getDouble(i);
                        break;
                    }
                }
                if (hourAgoPrice > 0 && latestPrice > 0) {
                    double hourPct = ((latestPrice - hourAgoPrice) / hourAgoPrice) * 100;
                    if (hourPct > 0.03) hourTrend = 1;
                    else if (hourPct < -0.03) hourTrend = -1;
                }
            }

            double change = currentPrice - prevClose;
            double pctChange = ((currentPrice - prevClose) / prevClose) * 100;

            JSONObject result = new JSONObject();
            result.put("price", currentPrice);
            result.put("prevClose", prevClose);
            result.put("change", change);
            result.put("pctChange", pctChange);
            result.put("hourTrend", hourTrend);
            result.put("marketState", marketState);

            // Gun ici NAV grafigi icin ham seri + seans sinirlari (NavSeries kullanir)
            try {
                JSONArray timestamps = r.optJSONArray("timestamp");
                if (timestamps != null && closes.length() > 0) {
                    result.put("ts", timestamps);
                    result.put("cl", closes);
                }
                putSessionBounds(result, meta);
            } catch (Exception e) { /* grafik olmadan devam */ }

            return result;
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * Pre/regular/post seans sinirlarini epoch saniye olarak result'a yazar.
     * Once `tradingPeriods` (veri gunune ait) denenir, yoksa `currentTradingPeriod`.
     */
    private void putSessionBounds(JSONObject result, JSONObject meta) {
        try {
            JSONObject tp = meta.optJSONObject("tradingPeriods");
            if (tp != null) {
                JSONObject pre = firstPeriod(tp.optJSONArray("pre"));
                JSONObject reg = firstPeriod(tp.optJSONArray("regular"));
                JSONObject post = firstPeriod(tp.optJSONArray("post"));
                if (pre != null && reg != null && post != null) {
                    result.put("preStart", pre.optLong("start", 0));
                    result.put("regStart", reg.optLong("start", 0));
                    result.put("regEnd", reg.optLong("end", 0));
                    result.put("postEnd", post.optLong("end", 0));
                    return;
                }
            }
            JSONObject ctp = meta.optJSONObject("currentTradingPeriod");
            if (ctp != null) {
                JSONObject pre = ctp.optJSONObject("pre");
                JSONObject reg = ctp.optJSONObject("regular");
                JSONObject post = ctp.optJSONObject("post");
                if (pre != null && reg != null && post != null) {
                    result.put("preStart", pre.optLong("start", 0));
                    result.put("regStart", reg.optLong("start", 0));
                    result.put("regEnd", reg.optLong("end", 0));
                    result.put("postEnd", post.optLong("end", 0));
                }
            }
        } catch (Exception e) { /* sinir yoksa grafik cizilmez */ }
    }

    /** Ham zaman serisi alanlarini atarak sadece skaler degerleri kopyalar. */
    private JSONObject stripSeries(JSONObject q) {
        try {
            JSONObject out = new JSONObject();
            String[] keys = { "price", "prevClose", "change", "pctChange", "hourTrend", "marketState" };
            for (String k : keys) {
                if (q.has(k)) out.put(k, q.get(k));
            }
            return out;
        } catch (Exception e) {
            return q;
        }
    }

    /** tradingPeriods dizileri [[{...}]] seklinde ic ice gelir. */
    private JSONObject firstPeriod(JSONArray arr) {
        if (arr == null || arr.length() == 0) return null;
        Object first = arr.opt(0);
        if (first instanceof JSONArray) {
            JSONArray inner = (JSONArray) first;
            return inner.length() > 0 ? inner.optJSONObject(0) : null;
        }
        return arr.optJSONObject(0);
    }

    // ==================== NAV Hesaplama + Bildirim ====================

    private void computeAndNotify(JSONObject allResults) {
        try {
            double[][] tickers = MainActivity.TICKERS;
            String[] symbols = MainActivity.SYMBOLS;
            double lastKnownNav = MainActivity.LAST_KNOWN_NAV;

            double embedPosValue = 0;
            for (double[] t : tickers) {
                embedPosValue += t[0] * t[1];
            }
            double cash = lastKnownNav - embedPosValue;

            double livePosValue = 0;
            double navDayChg = 0;
            int fetched = 0;

            for (int i = 0; i < symbols.length; i++) {
                double qty = tickers[i][0];
                JSONObject q = allResults.optJSONObject(symbols[i]);
                if (q != null) {
                    double price = q.optDouble("price", 0);
                    double prevClose = q.optDouble("prevClose", 0);
                    if (price > 0) {
                        livePosValue += qty * price;
                        if (prevClose > 0) {
                            navDayChg += (price - prevClose) * qty;
                        }
                        fetched++;
                    } else {
                        livePosValue += qty * tickers[i][1];
                    }
                } else {
                    livePosValue += qty * tickers[i][1];
                }
            }

            if (fetched == 0) return;

            double liveNAV = cash + livePosValue;
            double prevCloseNAV = liveNAV - navDayChg;
            boolean prevCloseValid = prevCloseNAV > 0;
            double navDayPct = prevCloseValid ? (navDayChg / prevCloseNAV) * 100 : 0;
            if (!prevCloseValid) {
                Log.w(TAG, "prevCloseNAV invalid (" + prevCloseNAV + ") - prevClose verileri eksik");
            }

            // Sesli uyari
            if (navAlertManager != null) {
                navAlertManager.checkAndAlert(liveNAV);
            }

            // Metin formatlama
            String navText = "$" + String.format("%,.0f", liveNAV);
            String sign = navDayPct >= 0 ? "+" : "";
            String dSign = navDayChg >= 0 ? "+$" : "-$";
            String changeText = prevCloseValid
                ? (dSign + String.format("%,.0f", Math.abs(navDayChg))
                    + " " + sign + String.format("%.2f%%", navDayPct))
                : "veri yok";
            int changeColor = navDayPct >= 0 ? COLOR_GREEN : COLOR_RED;

            if (fetched < symbols.length) {
                changeText += " (" + fetched + "/" + symbols.length + ")";
            }

            // Trend ok: gunluk degisime gore (her zaman yon)
            String trendArrow = navDayPct >= 0 ? "\u25B2" : "\u25BC";
            int trendColor = navDayPct >= 0 ? COLOR_GREEN : COLOR_RED;

            // VIX
            float vixValue = 20f;
            float vixChangePct = 0f;
            String vixText = "";
            JSONObject vixQ = allResults.optJSONObject("^VIX");
            if (vixQ != null) {
                double vix = vixQ.optDouble("price", -1);
                if (vix > 0) {
                    vixValue = (float) vix;
                    vixChangePct = (float) vixQ.optDouble("pctChange", 0);
                    vixText = " \u25CF VIX " + String.format("%.1f", vix);
                }
            }

            // BTC
            String btcText = "";
            JSONObject btcQ = allResults.optJSONObject("BTC-USD");
            if (btcQ != null) {
                double btcPrice = btcQ.optDouble("price", -1);
                double btcPct = btcQ.optDouble("pctChange", 0);
                if (btcPrice > 0) {
                    String btcFormatted = String.format("%.1fK", btcPrice / 1000);
                    String btcSign = btcPct >= 0 ? "+" : "";
                    btcText = " \u25CF \u20BF" + btcFormatted + " " + btcSign + String.format("%.1f%%", btcPct);
                }
            }

            // GC=F (Altin)
            String goldText = "";
            JSONObject goldQ = allResults.optJSONObject("GC=F");
            if (goldQ != null) {
                double goldPrice = goldQ.optDouble("price", -1);
                double goldPct = goldQ.optDouble("pctChange", 0);
                if (goldPrice > 0) {
                    String goldFormatted = String.format("%.0f", goldPrice);
                    String goldSign = goldPct >= 0 ? "+" : "";
                    goldText = " \u25CF Au" + goldFormatted + " " + goldSign + String.format("%.1f%%", goldPct);
                }
            }

            // NQ=F (Nasdaq futures)
            String nqText = "";
            JSONObject nqQ = allResults.optJSONObject("NQ=F");
            if (nqQ != null) {
                double nqPrice = nqQ.optDouble("price", -1);
                double nqPct = nqQ.optDouble("pctChange", 0);
                if (nqPrice > 0) {
                    String nqFormatted = String.format("%.0f", nqPrice);
                    String nqSign = nqPct >= 0 ? "+" : "";
                    nqText = " \u25CF NQ" + nqFormatted + " " + nqSign + String.format("%.1f%%", nqPct);
                }
            }

            // SharedPreferences guncelle (home screen widget + WebView icin)
            SharedPreferences prefs = getSharedPreferences(MainActivity.WIDGET_PREFS, Context.MODE_PRIVATE);
            SharedPreferences.Editor editor = prefs.edit()
                .putString("nav_text", navText)
                .putString("change_text", changeText)
                .putInt("change_color", changeColor)
                .putFloat("vix_value", vixValue)
                .putFloat("vix_change_pct", vixChangePct)
                .putString("trend_arrow", trendArrow)
                .putInt("trend_color", trendColor)
                .putFloat("pct_change", (float) navDayPct)
                .putFloat("total_return_pct", (float) (((liveNAV / MainActivity.ANCHOR_NAV) * MainActivity.ANCHOR_TWR_FACTOR - 1) * 100))
                .putString("symbol_pcts", MainActivity.buildSymbolPctJson(allResults))
                .putLong("timestamp", System.currentTimeMillis());

            // Gun ici NAV serisi (widget grafigi) - basarisiz olursa onceki seri kalir
            if (prevCloseValid) {
                String series = NavSeries.build(allResults, symbols, tickers, cash, prevCloseNAV);
                if (series != null) {
                    editor.putString("nav_series", series);
                    // Grafikteki min/max cizgilerini $ olarak yazabilmek icin baseline
                    editor.putFloat("prev_close_nav", (float) prevCloseNAV);
                }
            }

            // Underlying asset verileri (JSON olarak sakla)
            // NOT: ham "ts"/"cl" dizileri haric tutulur - prefs'i sisirmesin.
            try {
                JSONObject underlyingData = new JSONObject();
                if (btcQ != null) underlyingData.put("BTC-USD", stripSeries(btcQ));
                if (goldQ != null) underlyingData.put("GC=F", stripSeries(goldQ));
                if (nqQ != null) underlyingData.put("NQ=F", stripSeries(nqQ));
                editor.putString("underlying_data", underlyingData.toString());
            } catch (Exception e) { /* ignore */ }

            editor.apply();

            // Home screen widget guncelle
            AppWidgetManager manager = AppWidgetManager.getInstance(this);
            int[] ids = manager.getAppWidgetIds(
                    new ComponentName(this, IBKRWidgetProvider.class));
            if (ids != null && ids.length > 0) {
                Intent intent = new Intent(this, IBKRWidgetProvider.class);
                intent.setAction(AppWidgetManager.ACTION_APPWIDGET_UPDATE);
                intent.putExtra(AppWidgetManager.EXTRA_APPWIDGET_IDS, ids);
                sendBroadcast(intent);
            }

            // Bildirim basligi
            String notifTitle = String.format(java.util.Locale.US, "%.0f", liveNAV / 1000);
            String notifBody = changeText + vixText + btcText + goldText + nqText;
            // Status bar ikonu: 10bin$ cinsinden tam sayi - az hane = buyuk rakam
            // (orn $967,581 -> "97", $1.02M -> "102")
            String statusText = String.valueOf(Math.round(liveNAV / 10_000.0));

            NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            // Tek renkli chip (rakam)
            nm.notify(NOTIFICATION_ID, buildNotification(notifTitle, notifBody, changeColor, statusText));
            // Eski hane-test bildirimlerini temizle
            for (int i = 0; i <= lastDigitCount; i++) nm.cancel(DIGIT_NOTIF_BASE + i);
            lastDigitCount = 0;

        } catch (Exception e) {
            // ignore
        }
    }

    // ==================== Bildirim ====================

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);

            // IMPORTANCE_DEFAULT - Live Update chip'i status bar'da gostermek icin gerekli
            // (LOW kanal chip'i bastiriyor). Sessiz tutmak icin sound=null + titresim kapali.
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID, "Portfoy Takip",
                    NotificationManager.IMPORTANCE_DEFAULT);
            channel.setDescription("Canli portfoy NAV bildirimi");
            channel.setShowBadge(false);
            channel.enableLights(false);
            channel.enableVibration(false);
            channel.setSound(null, null);  // sessiz
            nm.createNotificationChannel(channel);
        }
    }

    // NAV degerini status bar ikonu olarak render et (orn: "97")
    // ARKA PLAN KUTUSU YOK - sadece rakam cizilir. Sebep: One UI normal ikon
    // alaninda ikonlara beyaz ALFA-TINT uygular; opak kutu tamamen beyaz
    // bloga donusuyordu (10 Tem 2026). Kutusuz cizimde:
    //   - tint'li mod: rakamlar beyaz gorunur (saat yazisi gibi)
    //   - renkli chip modu: rakamlar accent renginde (yesil/kirmizi) gorunur
    private android.graphics.drawable.Icon createTextIcon(String text, int accentColor) {
        float density = getResources().getDisplayMetrics().density;
        // KARE bitmap - sistem yuvayi tam doldurur
        int side = (int) (48 * density);
        Paint textPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        textPaint.setColor(accentColor);
        textPaint.setTypeface(Typeface.create("sans-serif-black", Typeface.BOLD));
        textPaint.setTextAlign(Paint.Align.CENTER);
        textPaint.setFakeBoldText(true);

        // Rakami karenin genisligine sigacak sekilde olceklendir (padding yok)
        float maxTextW = side * 0.98f;
        float ts = side * 0.95f;
        textPaint.setTextSize(ts);
        float tw = textPaint.measureText(text);
        if (tw > maxTextW) {
            ts = ts * maxTextW / tw;
            textPaint.setTextSize(ts);
        }

        Bitmap bmp = Bitmap.createBitmap(side, side, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(bmp);
        float y = side / 2f - (textPaint.descent() + textPaint.ascent()) / 2f;
        canvas.drawText(text, side / 2f, y, textPaint);
        return android.graphics.drawable.Icon.createWithBitmap(bmp);
    }

    private static final int DIGIT_NOTIF_BASE = 2001;
    private int lastDigitCount = 0;

    // Kalan haneleri (index 1..n) ayri bildirim olarak postala.
    // Index 0 ana foreground bildirimde gosteriliyor.
    // Grouping YOK - yoksa Samsung tek ikona topluyor. setWhen ile sira korunur.
    private void postDigitNotifications(NotificationManager nm, String number, int color) {
        int n = number.length();
        // Onceki fazla haneleri temizle
        for (int i = n; i <= lastDigitCount; i++) {
            nm.cancel(DIGIT_NOTIF_BASE + i);
        }
        lastDigitCount = n;

        Intent launchIntent = new Intent(this, MainActivity.class);
        launchIntent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 0, launchIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        long baseWhen = System.currentTimeMillis();
        for (int i = 1; i < n; i++) {  // index 0 ana bildirimde
            String digit = String.valueOf(number.charAt(i));
            android.graphics.drawable.Icon icon = createTextIcon(digit, color);
            Notification.Builder nb = new Notification.Builder(this, CHANNEL_ID);
            nb.setSmallIcon(icon)
              .setContentTitle("NAV " + number)
              .setContentIntent(pi)
              .setOngoing(true)
              .setColor(color)
              .setOnlyAlertOnce(true)
              .setVisibility(Notification.VISIBILITY_PUBLIC)
              .setWhen(baseWhen + i)       // sira: soldan saga artan
              .setShowWhen(false);
            nm.notify(DIGIT_NOTIF_BASE + i, nb.build());
        }
    }

    private Notification buildNotification(String title, String body) {
        return buildNotification(title, body, 0xFF3B82F6, null);
    }

    private Notification buildNotification(String title, String body, int accentColor, String statusText) {
        Intent launchIntent = new Intent(this, MainActivity.class);
        launchIntent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pi = PendingIntent.getActivity(this, 0, launchIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        // Android 16 (API 36+) - ProgressStyle ile Now Bar / AOD desteği
        if (Build.VERSION.SDK_INT >= 36) {
            try {
                Notification.ProgressStyle progressStyle = new Notification.ProgressStyle();
                progressStyle.addProgressPoint(
                        new Notification.ProgressStyle.Point(5000).setColor(accentColor));
                Notification.Builder nb = new Notification.Builder(this, CHANNEL_ID);
                // shortCriticalText denendi: bildirim PROMOTED_ONGOING oluyor ama
                // One UI 8.x ucuncu parti chip'te sistem metnini cizmiyor (10 Tem 2026
                // testi). Bu yuzden rakam bitmap ikon olarak ciziliyor; az hane
                // (NAV/10bin, orn "97") + tam dolu cizim = okunabilir boyut.
                android.graphics.drawable.Icon textIcon = null;
                if (statusText != null && !statusText.isEmpty()) {
                    try { textIcon = createTextIcon(statusText, accentColor); } catch (Exception e) { /* ignore */ }
                }
                if (textIcon != null) nb.setSmallIcon(textIcon);
                else nb.setSmallIcon(R.drawable.ic_stat_nav);
                nb.setContentTitle(title)
                        .setContentText(body)
                        .setContentIntent(pi)
                        .setOngoing(true)
                        .setColor(accentColor)
                        .setOnlyAlertOnce(true)
                        .setCategory(Notification.CATEGORY_PROGRESS)
                        .setVisibility(Notification.VISIBILITY_PUBLIC)
                        .setStyle(progressStyle);
                // Android 16 Live Update: status bar chip'e terfi ettir + chip metni
                // NOT: setColorized(true) promoted notification'da YASAK (chip'i bozar)
                try {
                    nb.setShortCriticalText(title);      // chip metni (4 hane, <7 -> tam gorunur)
                } catch (Throwable t) { /* API yoksa gecersiz */ }
                // setRequestPromotedOngoing kurulu SDK platformunda olmayabilir -> reflection
                try {
                    Notification.Builder.class
                            .getMethod("setRequestPromotedOngoing", boolean.class)
                            .invoke(nb, true);
                } catch (Throwable t) { /* runtime'da yoksa gecersiz */ }
                Notification notification = nb.build();

                // Samsung Now Bar extras
                try {
                    Bundle extras = notification.extras;
                    if (extras == null) extras = new Bundle();
                    extras.putInt("android.ongoingActivityNoti.style", 1);
                    extras.putString("android.ongoingActivityNoti.primaryInfo", title);
                    extras.putString("android.ongoingActivityNoti.secondaryInfo", body);
                    extras.putInt("android.ongoingActivityNoti.chipBgColor", accentColor);
                    extras.putString("android.ongoingActivityNoti.chipExpandedText", title);
                    extras.putString("android.ongoingActivityNoti.nowbarPrimaryInfo", title);
                    extras.putString("android.ongoingActivityNoti.nowbarSecondaryInfo", body);
                    notification.extras = extras;
                } catch (Exception e) { /* ignore */ }

                return notification;
            } catch (Exception e) {
                // ProgressStyle basarisiz olursa fallback
            }
        }

        // Fallback: Eski bildirim (API < 36)
        Notification notification = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.mipmap.ic_launcher)
                .setContentTitle(title)
                .setContentText(body)
                .setContentIntent(pi)
                .setOngoing(true)
                .setSilent(true)
                .setColor(accentColor)
                .setOnlyAlertOnce(true)
                .setCategory(NotificationCompat.CATEGORY_STATUS)
                .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
                .build();

        // Samsung Now Bar extras (One UI 7/8)
        try {
            Bundle extras = notification.extras;
            if (extras == null) extras = new Bundle();
            extras.putInt("android.ongoingActivityNoti.style", 1);
            extras.putString("android.ongoingActivityNoti.primaryInfo", title);
            extras.putString("android.ongoingActivityNoti.secondaryInfo", body);
            extras.putInt("android.ongoingActivityNoti.chipBgColor", 0x00000000);
            extras.putString("android.ongoingActivityNoti.chipExpandedText", title);
            extras.putString("android.ongoingActivityNoti.nowbarPrimaryInfo", title);
            extras.putString("android.ongoingActivityNoti.nowbarSecondaryInfo", body);
            notification.extras = extras;
        } catch (Exception e) { /* ignore */ }

        return notification;
    }

    // ==================== Service Lifecycle ====================

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onDestroy() {
        if (scheduler != null) scheduler.shutdownNow();
        super.onDestroy();
    }
}
