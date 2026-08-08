package com.ibkr.widget;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.Arrays;
import java.util.Locale;

/**
 * Gun ici NAV yuzde serisi (pre-market + regular + after-hours).
 *
 * Yahoo'dan zaten cekilen 1 dakikalik chart verisini kullanir - EK NETWORK YOK.
 * Her ticker'in dakikalik close'lari ortak zaman kovalarina yerlestirilir,
 * her kova icin NAV hesaplanip dunku kapanis NAV'ina gore yuzde farka cevrilir.
 *
 * Prefs'te tek satirlik metin olarak saklanir:
 *   preStart;regStart;regEnd;postEnd;p0,p1,...,pN   ('~' = veri yok)
 */
public class NavSeries {

    /** Gun boyunca kac nokta cizilecek (16 saat / 180 = ~5.3 dakika). */
    public static final int BUCKETS = 180;

    public static class Data {
        public long preStart, regStart, regEnd, postEnd;
        public float[] pct;       // NaN = o kovada veri yok
        public int firstIdx = -1; // ilk dolu kova
        public int lastIdx = -1;  // son dolu kova (su anki nokta)
    }

    /**
     * Seriyi uretir. Veri yetersizse null doner (widget grafigi gizler).
     *
     * @param cash         nakit bilesen (NAV - pozisyon degeri)
     * @param prevCloseNAV dunku kapanisa gore NAV (baseline)
     */
    static String build(JSONObject allResults, String[] symbols, double[][] tickers,
                        double cash, double prevCloseNAV) {
        try {
            if (allResults == null || prevCloseNAV <= 0) return null;

            // 1) Seans sinirlari - ilk saglikli sembolden
            long[] b4 = sessionBounds(allResults, symbols);
            if (b4 == null) return null;
            long preStart = b4[0], regStart = b4[1], regEnd = b4[2], postEnd = b4[3];

            double bucketSec = (double) (postEnd - preStart) / BUCKETS;
            if (bucketSec < 30) return null;

            // 2) Her sembol icin kova bazli fiyat izgarasi
            float[][] grid = new float[symbols.length][BUCKETS];
            double[] prevCloses = new double[symbols.length];
            boolean[] anyData = new boolean[BUCKETS];
            boolean gotAny = false;

            for (int s = 0; s < symbols.length; s++) {
                Arrays.fill(grid[s], Float.NaN);

                JSONObject q = allResults.optJSONObject(symbols[s]);
                double pc = q != null ? q.optDouble("prevClose", 0) : 0;
                prevCloses[s] = pc > 0 ? pc : tickers[s][1];
                if (q == null) continue;

                JSONArray ts = q.optJSONArray("ts");
                JSONArray cl = q.optJSONArray("cl");
                if (ts == null || cl == null) continue;

                int n = Math.min(ts.length(), cl.length());
                for (int i = 0; i < n; i++) {
                    if (cl.isNull(i)) continue;
                    double price = cl.optDouble(i, 0);
                    if (price <= 0) continue;
                    long t = ts.optLong(i, 0);
                    int b = (int) ((t - preStart) / bucketSec);
                    if (b < 0 || b >= BUCKETS) continue;
                    grid[s][b] = (float) price;   // kova icindeki son tik gecerli
                    anyData[b] = true;
                    gotAny = true;
                }
            }
            if (!gotAny) return null;

            // 3) Islem goren aralik (once/sonrasi bos kalir)
            int first = -1, last = -1;
            for (int b = 0; b < BUCKETS; b++) {
                if (anyData[b]) {
                    if (first < 0) first = b;
                    last = b;
                }
            }
            if (first < 0 || last <= first) return null;

            // 4) Forward-fill: bosluklarda son bilinen fiyat, baslangicta prevClose
            for (int s = 0; s < symbols.length; s++) {
                float carry = (float) prevCloses[s];
                for (int b = 0; b < BUCKETS; b++) {
                    if (!Float.isNaN(grid[s][b])) carry = grid[s][b];
                    else grid[s][b] = carry;
                }
            }

            // 5) Kova basina NAV -> yuzde
            StringBuilder sb = new StringBuilder(1400);
            sb.append(preStart).append(';').append(regStart).append(';')
              .append(regEnd).append(';').append(postEnd).append(';');
            for (int b = 0; b < BUCKETS; b++) {
                if (b > 0) sb.append(',');
                if (b < first || b > last) { sb.append('~'); continue; }
                double posValue = 0;
                for (int s = 0; s < symbols.length; s++) {
                    posValue += tickers[s][0] * grid[s][b];
                }
                double nav = cash + posValue;
                double pct = (nav - prevCloseNAV) / prevCloseNAV * 100.0;
                sb.append(String.format(Locale.US, "%.3f", pct));
            }
            return sb.toString();

        } catch (Exception e) {
            return null;
        }
    }

    /** Seans sinirlari {preStart, regStart, regEnd, postEnd} - bulunamazsa null. */
    static long[] sessionBounds(JSONObject allResults, String[] symbols) {
        if (allResults == null) return null;
        for (String sym : symbols) {
            JSONObject q = allResults.optJSONObject(sym);
            if (q == null) continue;
            long ps = q.optLong("preStart", 0);
            long rs = q.optLong("regStart", 0);
            long re = q.optLong("regEnd", 0);
            long pe = q.optLong("postEnd", 0);
            if (ps > 0 && rs > ps && re > rs && pe > re) {
                return new long[]{ ps, rs, re, pe };
            }
        }
        return null;
    }

    /**
     * Tek bir sembolun ham kapanis serisini NAV serisiyle AYNI kova izgarasina
     * yerlestirir (VIX overlay icin). Bosluklar '~' kalir - cizerken atlanir.
     * Cikti: "preStart;v0,v1,...,vN"
     */
    static String buildRaw(JSONObject q, long preStart, long postEnd) {
        try {
            if (q == null || postEnd <= preStart) return null;
            double bucketSec = (double) (postEnd - preStart) / BUCKETS;
            if (bucketSec < 30) return null;

            JSONArray ts = q.optJSONArray("ts");
            JSONArray cl = q.optJSONArray("cl");
            if (ts == null || cl == null) return null;

            float[] grid = new float[BUCKETS];
            Arrays.fill(grid, Float.NaN);
            boolean any = false;

            int n = Math.min(ts.length(), cl.length());
            for (int i = 0; i < n; i++) {
                if (cl.isNull(i)) continue;
                double v = cl.optDouble(i, 0);
                if (v <= 0) continue;
                int b = (int) ((ts.optLong(i, 0) - preStart) / bucketSec);
                if (b < 0 || b >= BUCKETS) continue;
                grid[b] = (float) v;
                any = true;
            }
            if (!any) return null;

            StringBuilder sb = new StringBuilder(1200);
            sb.append(preStart).append(';');
            for (int b = 0; b < BUCKETS; b++) {
                if (b > 0) sb.append(',');
                if (Float.isNaN(grid[b])) sb.append('~');
                else sb.append(String.format(Locale.US, "%.2f", grid[b]));
            }
            return sb.toString();
        } catch (Exception e) {
            return null;
        }
    }

    /**
     * buildRaw ciktisini cozer. preStart eslesmiyorsa (onceki gunden kalma)
     * null doner - yanlis gune ait cizgi cizilmesin.
     */
    static float[] parseRaw(String payload, long expectedPreStart) {
        try {
            if (payload == null) return null;
            int sc = payload.indexOf(';');
            if (sc <= 0) return null;
            if (Long.parseLong(payload.substring(0, sc)) != expectedPreStart) return null;

            String[] parts = payload.substring(sc + 1).split(",");
            float[] out = new float[parts.length];
            boolean any = false;
            for (int i = 0; i < parts.length; i++) {
                String p = parts[i];
                if (p.isEmpty() || p.charAt(0) == '~') {
                    out[i] = Float.NaN;
                } else {
                    out[i] = Float.parseFloat(p);
                    any = true;
                }
            }
            return any ? out : null;
        } catch (Exception e) {
            return null;
        }
    }

    /** Prefs metnini cizime hazir hale getirir. Bozuk/eksikse null. */
    static Data parse(String payload) {
        try {
            if (payload == null || payload.length() < 16) return null;
            String[] head = payload.split(";", 5);
            if (head.length < 5) return null;

            Data d = new Data();
            d.preStart = Long.parseLong(head[0]);
            d.regStart = Long.parseLong(head[1]);
            d.regEnd   = Long.parseLong(head[2]);
            d.postEnd  = Long.parseLong(head[3]);
            if (d.postEnd <= d.preStart) return null;

            String[] parts = head[4].split(",");
            d.pct = new float[parts.length];
            for (int i = 0; i < parts.length; i++) {
                String p = parts[i];
                if (p.isEmpty() || p.charAt(0) == '~') {
                    d.pct[i] = Float.NaN;
                } else {
                    d.pct[i] = Float.parseFloat(p);
                    if (d.firstIdx < 0) d.firstIdx = i;
                    d.lastIdx = i;
                }
            }
            return (d.firstIdx >= 0 && d.lastIdx > d.firstIdx) ? d : null;
        } catch (Exception e) {
            return null;
        }
    }
}
