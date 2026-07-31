package com.ibkr.widget;

import android.graphics.*;

public class GaugeDrawer {

    // Arc: 8 o'clock (150°) -> 4 o'clock (30°), 240° sweep, center = 270° = 12:00
    private static final float ARC_START = 150f;
    private static final float ARC_SWEEP = 240f;
    private static final float MAX_PCT = 4.0f; // ±4% = full deflection

    // VIX arc araligi (18 = merkez 12:00)
    private static final float VIX_MIN = 13f;
    private static final float VIX_MAX = 25f;
    private static final float VIX_CENTER = 18f;

    // Arc renk gradyani
    private static final int[] ARC_COLORS = {
        0xFFFF0000, 0xFFEF4444, 0xFFF97316, 0xFFFFD700,
        0xFFFFD700, 0xFFB2CC16, 0xFF22C55E, 0xFF00E676
    };
    private static final float[] ARC_STOPS = {
        0f, 0.12f, 0.30f, 0.45f, 0.55f, 0.70f, 0.88f, 1.0f
    };

    // Bitmap cache - GC pressure'i azaltmak icin (her widget update 1MB allocate ediyordu)
    private static Bitmap cachedBitmap;
    private static int cachedW, cachedH;

    /**
     * @param height      gauge bolgesinin yuksekligi (tum gauge olculeri buna gore)
     * @param chartHeight altta gun ici seans grafigi icin ayrilan yukseklik (0 = grafik yok)
     * @param series      gun ici NAV serisi (null ise grafik cizilmez)
     */
    public static Bitmap draw(int width, int height, int chartHeight,
                              String navText, String changeText,
                              float pctChange, float vixValue, String trendArrow, int trendColor,
                              float totalReturnPct,
                              float[] sidePcts, Bitmap[] sideLogos, float vixChangePct,
                              NavSeries.Data series, float prevCloseNav, float[] vixSeries) {
        int totalH = height + Math.max(0, chartHeight);
        Bitmap bitmap;
        if (cachedBitmap != null && !cachedBitmap.isRecycled()
                && cachedW == width && cachedH == totalH) {
            bitmap = cachedBitmap;
            bitmap.eraseColor(0); // seffaf temizle
        } else {
            bitmap = Bitmap.createBitmap(width, totalH, Bitmap.Config.ARGB_8888);
            cachedBitmap = bitmap;
            cachedW = width;
            cachedH = totalH;
        }
        Canvas canvas = new Canvas(bitmap);

        float cx = width / 2f;
        float cy = height * 0.55f;
        float radius = height * 0.46f;

        // Yan paneller (logolar + gunluk %)
        drawSidePanels(canvas, width, height, sidePcts, sideLogos);

        RectF arcRect = new RectF(cx - radius, cy - radius, cx + radius, cy + radius);

        // 1. Arc track (koyu arka plan)
        Paint trackPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        trackPaint.setStyle(Paint.Style.STROKE);
        trackPaint.setStrokeWidth(radius * 0.18f);
        trackPaint.setStrokeCap(Paint.Cap.ROUND);
        trackPaint.setColor(0xFF1E293B);
        canvas.drawArc(arcRect, ARC_START, ARC_SWEEP, false, trackPaint);

        // 2. Renkli arc (kirmizi -> sari -> yesil)
        drawColoredArc(canvas, arcRect, radius);

        // 3. Tick isaretleri
        drawTicks(canvas, cx, cy, radius);

        // 4. VIX centigi (yukseliyorsa kirmizi, dususe yesil, esitse siyah) + pivot rengini hesapla
        int vixTickColor;
        if (vixChangePct > 0.01f) vixTickColor = 0xFFEF4444;       // kirmizi (VIX yukseldi)
        else if (vixChangePct < -0.01f) vixTickColor = 0xFF10B981; // yesil (VIX dustu)
        else vixTickColor = 0xFF000000;                             // siyah (degisim yok)
        int vixArcColor = drawVixTick(canvas, cx, cy, radius, vixValue, vixTickColor);

        // 5. Ibre
        drawNeedle(canvas, cx, cy, radius, pctChange, trendColor);

        // 6. Merkez pivot (VIX arc rengi)
        Paint pivotGlow = new Paint(Paint.ANTI_ALIAS_FLAG);
        pivotGlow.setColor(vixArcColor & 0x40FFFFFF);
        pivotGlow.setStyle(Paint.Style.FILL);
        canvas.drawCircle(cx, cy, radius * 0.13f, pivotGlow);

        Paint pivotPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        pivotPaint.setColor(vixArcColor);
        pivotPaint.setStyle(Paint.Style.FILL);
        canvas.drawCircle(cx, cy, radius * 0.08f, pivotPaint);

        // 7. Trend oku (12:00 pozisyonunda)
        if (trendArrow != null) {
            Paint arrowPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
            arrowPaint.setColor(trendColor);
            arrowPaint.setTextAlign(Paint.Align.CENTER);
            arrowPaint.setTextSize(radius * 0.28f);
            arrowPaint.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
            arrowPaint.setShadowLayer(2, 1, 1, 0xCC000000);
            canvas.drawText(trendArrow, cx, cy - radius * 0.65f, arrowPaint);
        }

        // 7b. Ilk gunden beri toplam getiri % (ok'un hemen altinda)
        if (!Float.isNaN(totalReturnPct)) {
            int retColor = totalReturnPct >= 0 ? 0xFF10B981 : 0xFFEF4444;
            String retText = String.format(java.util.Locale.US, "%s%.1f%%",
                    totalReturnPct >= 0 ? "+" : "", totalReturnPct);
            Paint retPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
            retPaint.setColor(retColor);
            retPaint.setTextAlign(Paint.Align.CENTER);
            retPaint.setTextSize(radius * 0.16f);
            retPaint.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
            retPaint.setShadowLayer(2, 1, 1, 0xCC000000);
            canvas.drawText(retText, cx, cy - radius * 0.40f, retPaint);
        }

        // 8. Ibre pozisyonundaki arc rengi (text'ler icin)
        int needleArcColor = colorForPct(pctChange);

        // 9. NAV text (beyaz)
        Paint navPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        navPaint.setColor(0xFFFFFFFF);
        navPaint.setTextAlign(Paint.Align.CENTER);
        navPaint.setTextSize(radius * 0.30f);
        navPaint.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
        navPaint.setShadowLayer(3, 1, 1, 0xCC000000);
        String nav = navText != null ? navText : "$---,---";
        canvas.drawText(nav, cx, cy + radius * 0.62f, navPaint);

        // 10. Degisim text (ibre rengiyle)
        Paint changePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        changePaint.setTextAlign(Paint.Align.CENTER);
        changePaint.setTextSize(radius * 0.19f);
        changePaint.setTypeface(Typeface.create("sans-serif", Typeface.NORMAL));
        changePaint.setColor(needleArcColor);
        changePaint.setShadowLayer(2, 1, 1, 0xCC000000);
        String chg = changeText != null ? changeText : "--";
        canvas.drawText(chg, cx, cy + radius * 0.85f, changePaint);

        // 11. Gun ici seans grafigi (gauge'un altinda)
        if (chartHeight > 0 && series != null) {
            SessionChartDrawer.draw(canvas, 0, height, width, chartHeight,
                    series, prevCloseNav, vixSeries);
        }

        return bitmap;
    }

    /**
     * VIX centigi cizer ve o pozisyondaki arc rengini dondurur.
     * VIX dusuk (15) -> sag (yesil), VIX yuksek (25) -> sol (kirmizi)
     */
    private static void drawSidePanels(Canvas canvas, int width, int height,
                                        float[] sidePcts, Bitmap[] sideLogos) {
        if (sideLogos == null) return;
        // Sol/sag panel her biri ~%24 genislik
        float panelW = width * 0.24f;
        float rowH = height / 3f;
        float logoSize = Math.min(rowH * 0.55f, panelW * 0.32f);

        Paint pctPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        pctPaint.setTextSize(rowH * 0.22f);
        pctPaint.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
        pctPaint.setShadowLayer(2, 1, 1, 0xCC000000);

        Paint bmpPaint = new Paint(Paint.FILTER_BITMAP_FLAG | Paint.ANTI_ALIAS_FLAG);

        for (int i = 0; i < 6; i++) {
            boolean isLeft = i < 3;
            int row = i % 3;
            float rowCY = (row + 0.5f) * rowH;

            float panelX = isLeft ? 0 : (width - panelW);

            // Logo + % yan yana
            float logoX, pctX;
            Paint.Align pctAlign;
            if (isLeft) {
                logoX = panelX + panelW * 0.10f;
                pctX  = panelX + panelW * 0.95f;
                pctAlign = Paint.Align.RIGHT;
            } else {
                logoX = panelX + panelW * 0.90f - logoSize;
                pctX  = panelX + panelW * 0.05f;
                pctAlign = Paint.Align.LEFT;
            }

            if (sideLogos[i] != null) {
                RectF dst = new RectF(
                        logoX, rowCY - logoSize / 2f,
                        logoX + logoSize, rowCY + logoSize / 2f);
                canvas.drawBitmap(sideLogos[i], null, dst, bmpPaint);
            }

            if (sidePcts != null) {
                float pct = sidePcts[i];
                int pctColor = pct >= 0 ? 0xFF10B981 : 0xFFEF4444;
                pctPaint.setColor(pctColor);
                pctPaint.setTextAlign(pctAlign);
                String pctText = String.format(java.util.Locale.US, "%+.1f%%", pct);
                Paint.FontMetrics fm = pctPaint.getFontMetrics();
                float textY = rowCY - (fm.ascent + fm.descent) / 2f;
                canvas.drawText(pctText, pctX, textY, pctPaint);
            }
        }
    }

    private static int drawVixTick(Canvas canvas, float cx, float cy, float radius, float vixValue, int tickColor) {
        // VIX -> arc pozisyonu (ters: dusuk VIX = sag/yesil, 18 = 12:00)
        // Piecewise linear: sag taraf 24°/VIX, sol taraf ~17.14°/VIX
        float vixAngle;
        float t;
        float clampedVix = Math.max(VIX_MIN, Math.min(VIX_MAX, vixValue));
        if (clampedVix <= VIX_CENTER) {
            // Sag taraf: VIX 13-18 -> 390° - 270° (24° per unit)
            float ratio = (VIX_CENTER - clampedVix) / (VIX_CENTER - VIX_MIN);
            vixAngle = 270f + ratio * 120f;
            t = 0.5f + ratio * 0.5f;
        } else {
            // Sol taraf: VIX 18-25 -> 270° - 150° (~17.14° per unit)
            float ratio = (clampedVix - VIX_CENTER) / (VIX_MAX - VIX_CENTER);
            vixAngle = 270f - ratio * 120f;
            t = 0.5f - ratio * 0.5f;
        }

        // Arc uzerindeki renk
        int arcColor = multiStopColor(ARC_COLORS, ARC_STOPS, t);

        // Siyah centik ciz - arc uzerinde, arki tam kaplayan kalinlikta
        // Arc uzerinde kisa bir siyah yay olarak ciz (drawArc ile)
        RectF arcRect = new RectF(cx - radius, cy - radius, cx + radius, cy + radius);
        Paint tickPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        tickPaint.setStyle(Paint.Style.STROKE);
        tickPaint.setStrokeWidth(radius * 0.20f); // arc track'ten biraz kalin
        tickPaint.setStrokeCap(Paint.Cap.BUTT);
        tickPaint.setColor(tickColor);
        canvas.drawArc(arcRect, vixAngle - 2.5f, 5f, false, tickPaint);

        return arcColor;
    }

    private static void drawColoredArc(Canvas canvas, RectF rect, float radius) {
        Paint arcPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        arcPaint.setStyle(Paint.Style.STROKE);
        arcPaint.setStrokeWidth(radius * 0.09f);
        arcPaint.setStrokeCap(Paint.Cap.BUTT);

        int segments = 60;
        float segmentSweep = ARC_SWEEP / segments;

        for (int i = 0; i < segments; i++) {
            float t = (float) i / segments;
            int color = multiStopColor(ARC_COLORS, ARC_STOPS, t);
            arcPaint.setColor(color);
            float angle = ARC_START + i * segmentSweep;
            canvas.drawArc(rect, angle, segmentSweep + 0.5f, false, arcPaint);
        }
    }

    private static void drawTicks(Canvas canvas, float cx, float cy, float radius) {
        Paint tickPaint = new Paint(Paint.ANTI_ALIAS_FLAG);

        for (float angle = ARC_START; angle <= ARC_START + ARC_SWEEP + 0.5f; angle += 30f) {
            boolean isCenter = Math.abs(angle - 270f) < 1f;
            if (isCenter) continue;

            float rad = (float) Math.toRadians(angle);
            tickPaint.setStrokeWidth(1.5f);
            tickPaint.setColor(0x50FFFFFF);
            float innerR = radius * 0.83f;
            float outerR = radius * 0.92f;

            canvas.drawLine(
                cx + innerR * (float) Math.cos(rad),
                cy + innerR * (float) Math.sin(rad),
                cx + outerR * (float) Math.cos(rad),
                cy + outerR * (float) Math.sin(rad),
                tickPaint
            );
        }
    }

    private static void drawNeedle(Canvas canvas, float cx, float cy, float radius,
                                   float pctChange, int accentColor) {
        float pct = Math.max(-MAX_PCT, Math.min(MAX_PCT, pctChange));
        float needleAngle = 270f + (pct / MAX_PCT) * (ARC_SWEEP / 2f);
        float rad = (float) Math.toRadians(needleAngle);

        float needleLen = radius * 0.75f;
        float tailLen = radius * 0.12f;

        float tipX = cx + needleLen * (float) Math.cos(rad);
        float tipY = cy + needleLen * (float) Math.sin(rad);
        float tailX = cx - tailLen * (float) Math.cos(rad);
        float tailY = cy - tailLen * (float) Math.sin(rad);

        Paint shadowPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        shadowPaint.setColor(0x30000000);
        shadowPaint.setStrokeWidth(5f);
        shadowPaint.setStrokeCap(Paint.Cap.ROUND);
        canvas.drawLine(tailX + 2, tailY + 2, tipX + 2, tipY + 2, shadowPaint);

        Paint needlePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        needlePaint.setColor(0xFFF8FAFC);
        needlePaint.setStrokeWidth(2.5f);
        needlePaint.setStrokeCap(Paint.Cap.ROUND);
        canvas.drawLine(tailX, tailY, tipX, tipY, needlePaint);

        Paint tipPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        tipPaint.setColor(accentColor);
        tipPaint.setStyle(Paint.Style.FILL);
        canvas.drawCircle(tipX, tipY, 3f, tipPaint);
    }

    /**
     * Bir gunluk yuzdenin gauge yayindaki rengi.
     *
     * Ibre ile BIREBIR ayni skalayi kullanir (±MAX_PCT = ±%4). Seans grafigi de
     * bunu cagirir - widget'ta tek renk dili olsun diye tek kaynak burasi.
     * NOT: ±%0.4 araligi duz sari; tipik gunlerde cizgi agirlikli sari cikar
     * (kullanici bunu bilerek sectiyi - 1 Agu 2026).
     */
    public static int colorForPct(float pctChange) {
        float clamped = Math.max(-MAX_PCT, Math.min(MAX_PCT, pctChange));
        float angle = 270f + (clamped / MAX_PCT) * (ARC_SWEEP / 2f);
        float t = (angle - ARC_START) / ARC_SWEEP;
        return multiStopColor(ARC_COLORS, ARC_STOPS, t);
    }

    private static int multiStopColor(int[] colors, float[] stops, float t) {
        t = Math.max(0f, Math.min(1f, t));
        for (int i = 0; i < stops.length - 1; i++) {
            if (t <= stops[i + 1]) {
                float local = (t - stops[i]) / (stops[i + 1] - stops[i]);
                return lerpColor(colors[i], colors[i + 1], local);
            }
        }
        return colors[colors.length - 1];
    }

    private static int lerpColor(int c1, int c2, float t) {
        t = Math.max(0f, Math.min(1f, t));
        int a = (int) (Color.alpha(c1) + (Color.alpha(c2) - Color.alpha(c1)) * t);
        int r = (int) (Color.red(c1) + (Color.red(c2) - Color.red(c1)) * t);
        int g = (int) (Color.green(c1) + (Color.green(c2) - Color.green(c1)) * t);
        int b = (int) (Color.blue(c1) + (Color.blue(c2) - Color.blue(c1)) * t);
        return Color.argb(a, r, g, b);
    }
}
