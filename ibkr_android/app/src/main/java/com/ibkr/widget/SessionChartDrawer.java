package com.ibkr.widget;

import android.graphics.Canvas;
import android.graphics.DashPathEffect;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.Typeface;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/**
 * Gun ici NAV grafigini uc seans bandi halinde cizer:
 * PRE (pre-market) | MARKET (regular) | AFTER (after-hours).
 *
 * Verilen canvas bolgesine cizer - gauge ile ayni bitmap'i paylasir
 * (RemoteViews bitmap boyut limitine takilmamak icin tek bitmap kullaniliyor).
 */
public class SessionChartDrawer {

    private static final int BAND_PRE = 0x1A3B82F6;
    private static final int BAND_REG = 0x1410B981;
    private static final int BAND_AFT = 0x1A8B5CF6;
    private static final int LBL_PRE  = 0xFF60A5FA;
    private static final int LBL_REG  = 0xFF34D399;
    private static final int LBL_AFT  = 0xFFA78BFA;
    private static final int COL_UP   = 0xFF10B981;
    private static final int COL_DOWN = 0xFFEF4444;
    private static final int COL_SEP  = 0x70475569;
    private static final int COL_BASE = 0xA0647488;
    private static final int COL_TIME = 0xFF7C8CA3;

    /** Yuzde ekseninde en dar aralik (duz cizgi gurultu gibi gorunmesin). */
    private static final float MIN_SPAN_PCT = 0.30f;

    static void draw(Canvas canvas, float x, float y, float w, float h, NavSeries.Data d) {
        if (d == null || d.pct == null || d.lastIdx <= d.firstIdx) return;

        float labelH = h * 0.19f;
        float timeH  = h * 0.17f;
        float plotL  = x + w * 0.015f;
        float plotR  = x + w * 0.985f;
        float plotT  = y + labelH;
        float plotB  = y + h - timeH;
        float plotW  = plotR - plotL;
        float plotH  = plotB - plotT;
        if (plotW <= 0 || plotH <= 0) return;

        long span = d.postEnd - d.preStart;
        float xReg = plotL + plotW * ((float) (d.regStart - d.preStart) / span);
        float xEnd = plotL + plotW * ((float) (d.regEnd   - d.preStart) / span);

        // ---- 1) Seans bantlari ----
        Paint band = new Paint(Paint.ANTI_ALIAS_FLAG);
        band.setStyle(Paint.Style.FILL);
        band.setColor(BAND_PRE);
        canvas.drawRect(plotL, plotT, xReg, plotB, band);
        band.setColor(BAND_REG);
        canvas.drawRect(xReg, plotT, xEnd, plotB, band);
        band.setColor(BAND_AFT);
        canvas.drawRect(xEnd, plotT, plotR, plotB, band);

        // ---- 2) Seans ayiriclari ----
        Paint sep = new Paint(Paint.ANTI_ALIAS_FLAG);
        sep.setStyle(Paint.Style.STROKE);
        sep.setColor(COL_SEP);
        sep.setStrokeWidth(Math.max(1.2f, h * 0.012f));
        sep.setPathEffect(new DashPathEffect(new float[]{h * 0.06f, h * 0.045f}, 0));
        canvas.drawLine(xReg, plotT, xReg, plotB, sep);
        canvas.drawLine(xEnd, plotT, xEnd, plotB, sep);

        // ---- 3) Seans basliklari ----
        Paint lbl = new Paint(Paint.ANTI_ALIAS_FLAG);
        lbl.setTextAlign(Paint.Align.CENTER);
        lbl.setTextSize(labelH * 0.72f);
        lbl.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
        lbl.setShadowLayer(2, 1, 1, 0xCC000000);
        float lblY = y + labelH * 0.80f;
        lbl.setColor(LBL_PRE);
        canvas.drawText("PRE", (plotL + xReg) / 2f, lblY, lbl);
        lbl.setColor(LBL_REG);
        canvas.drawText("MARKET", (xReg + xEnd) / 2f, lblY, lbl);
        lbl.setColor(LBL_AFT);
        canvas.drawText("AFTER", (xEnd + plotR) / 2f, lblY, lbl);

        // ---- 4) Y ekseni araligi (0 = dunku kapanis her zaman icinde) ----
        float min = 0f, max = 0f;
        for (int i = d.firstIdx; i <= d.lastIdx; i++) {
            float v = d.pct[i];
            if (Float.isNaN(v)) continue;
            if (v < min) min = v;
            if (v > max) max = v;
        }
        float mid = (min + max) / 2f;
        float half = Math.max((max - min) / 2f, MIN_SPAN_PCT / 2f);
        half *= 1.18f;                 // ust/alt nefes payi
        float lo = mid - half, hi = mid + half;

        // ---- 5) Baseline (dunku kapanis) ----
        float baseY = pctToY(0f, lo, hi, plotT, plotH);
        Paint basePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        basePaint.setStyle(Paint.Style.STROKE);
        basePaint.setColor(COL_BASE);
        basePaint.setStrokeWidth(Math.max(1f, h * 0.010f));
        basePaint.setPathEffect(new DashPathEffect(new float[]{h * 0.028f, h * 0.035f}, 0));
        canvas.drawLine(plotL, baseY, plotR, baseY, basePaint);

        // ---- 6) NAV egrisi ----
        float lastPct = d.pct[d.lastIdx];
        int lineColor = lastPct >= 0 ? COL_UP : COL_DOWN;

        Path line = new Path();
        int nPts = d.pct.length;
        float lastX = plotL, lastY = baseY;
        boolean started = false;
        for (int i = d.firstIdx; i <= d.lastIdx; i++) {
            float v = d.pct[i];
            if (Float.isNaN(v)) continue;
            float px = plotL + plotW * (i / (float) (nPts - 1));
            float py = pctToY(v, lo, hi, plotT, plotH);
            if (!started) { line.moveTo(px, py); started = true; }
            else line.lineTo(px, py);
            lastX = px; lastY = py;
        }
        if (!started) return;

        // Dolgu (egri ile baseline arasi)
        float startX = plotL + plotW * (d.firstIdx / (float) (nPts - 1));
        Path fill = new Path(line);
        fill.lineTo(lastX, baseY);
        fill.lineTo(startX, baseY);
        fill.close();
        Paint fillPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        fillPaint.setStyle(Paint.Style.FILL);
        fillPaint.setColor((lineColor & 0x00FFFFFF) | 0x38000000);
        canvas.drawPath(fill, fillPaint);

        // Cizgi
        Paint linePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        linePaint.setStyle(Paint.Style.STROKE);
        linePaint.setColor(lineColor);
        linePaint.setStrokeWidth(Math.max(1.8f, h * 0.026f));
        linePaint.setStrokeJoin(Paint.Join.ROUND);
        linePaint.setStrokeCap(Paint.Cap.ROUND);
        canvas.drawPath(line, linePaint);

        // ---- 7) Guncel nokta + yuzde etiketi ----
        Paint dot = new Paint(Paint.ANTI_ALIAS_FLAG);
        dot.setStyle(Paint.Style.FILL);
        dot.setColor(0xFFFFFFFF);
        float dotR = Math.max(2.5f, h * 0.035f);
        canvas.drawCircle(lastX, lastY, dotR, dot);

        Paint valPaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        valPaint.setColor(lineColor);
        valPaint.setTextSize(labelH * 0.78f);
        valPaint.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
        valPaint.setShadowLayer(3, 1, 1, 0xE0000000);
        String valText = String.format(Locale.US, "%+.2f%%", lastPct);
        boolean rightSide = lastX < plotR - plotW * 0.22f;
        valPaint.setTextAlign(rightSide ? Paint.Align.LEFT : Paint.Align.RIGHT);
        float valX = rightSide ? lastX + dotR * 2.2f : lastX - dotR * 2.2f;
        float valY = Math.max(plotT + labelH * 0.7f, lastY - dotR * 2.0f);
        canvas.drawText(valText, valX, valY, valPaint);

        // ---- 8) Saat etiketleri (telefonun yerel saati) ----
        Paint timePaint = new Paint(Paint.ANTI_ALIAS_FLAG);
        timePaint.setColor(COL_TIME);
        timePaint.setTextSize(timeH * 0.66f);
        timePaint.setTypeface(Typeface.create("sans-serif", Typeface.NORMAL));
        float timeY = plotB + timeH * 0.78f;
        SimpleDateFormat fmt = new SimpleDateFormat("HH:mm", Locale.US);

        timePaint.setTextAlign(Paint.Align.LEFT);
        canvas.drawText(fmt.format(new Date(d.preStart * 1000L)), plotL, timeY, timePaint);
        timePaint.setTextAlign(Paint.Align.CENTER);
        canvas.drawText(fmt.format(new Date(d.regStart * 1000L)), xReg, timeY, timePaint);
        canvas.drawText(fmt.format(new Date(d.regEnd * 1000L)), xEnd, timeY, timePaint);
        timePaint.setTextAlign(Paint.Align.RIGHT);
        canvas.drawText(fmt.format(new Date(d.postEnd * 1000L)), plotR, timeY, timePaint);
    }

    private static float pctToY(float pct, float lo, float hi, float plotT, float plotH) {
        float t = (pct - lo) / (hi - lo);
        t = Math.max(0f, Math.min(1f, t));
        return plotT + plotH * (1f - t);
    }
}
