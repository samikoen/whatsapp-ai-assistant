package com.ibkr.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.util.DisplayMetrics;
import android.widget.RemoteViews;

import org.json.JSONObject;

public class IBKRWidgetProvider extends AppWidgetProvider {

    // Gauge'un dogal orani: 380dp genislige 200dp yukseklik.
    // Gauge GENISLIGE bagli olceklenir (yan paneller + yay icin), yuksekligi
    // buyutmek onu buyutmez. Bu yuzden kullanicinin verdigi fazla yukseklik
    // tamamen GRAFIGE aktarilir.
    private static final float GAUGE_ASPECT = 200f / 380f;
    private static final int DEFAULT_W_DP = 380;
    private static final int MIN_CHART_DP = 90;
    /** Grafik gauge'dan daha baskin olmasin. */
    private static final float MAX_CHART_RATIO = 1.0f;
    /** RemoteViews bitmap limitine takilmamak icin ust sinir. */
    private static final int MAX_PIXELS = 1_300_000;

    // Sol kolon (pozisyon buyuk -> kucuk): NVDA, IBIT, NVO
    // Sag kolon (pozisyon buyuk -> kucuk): QQQ, IAU, SMCI
    private static final String[] SIDE_SYMBOLS = {
            "NVDA", "IBIT", "NVO",
            "QQQ",  "IAU",  "SMCI"
    };

    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent.getAction();

        if (AppWidgetManager.ACTION_APPWIDGET_UPDATE.equals(action)) {
            AppWidgetManager manager = AppWidgetManager.getInstance(context);
            int[] ids = manager.getAppWidgetIds(
                    new ComponentName(context, IBKRWidgetProvider.class));
            for (int widgetId : ids) {
                updateFromPrefs(context, manager, widgetId);
            }
        }
        super.onReceive(context, intent);
    }

    @Override
    public void onUpdate(Context context, AppWidgetManager appWidgetManager, int[] appWidgetIds) {
        for (int widgetId : appWidgetIds) {
            updateFromPrefs(context, appWidgetManager, widgetId);
        }
    }

    /** Kullanici widget'i yeniden boyutlandirinca cizimi yeni olcuye gore yenile. */
    @Override
    public void onAppWidgetOptionsChanged(Context context, AppWidgetManager manager,
                                          int widgetId, android.os.Bundle newOptions) {
        updateFromPrefs(context, manager, widgetId);
        super.onAppWidgetOptionsChanged(context, manager, widgetId, newOptions);
    }

    private void updateFromPrefs(Context context, AppWidgetManager manager, int widgetId) {
        SharedPreferences prefs = context.getSharedPreferences(
                MainActivity.WIDGET_PREFS, Context.MODE_PRIVATE);

        String navText = prefs.getString("nav_text", null);
        String changeText = prefs.getString("change_text", null);
        float vixValue = prefs.getFloat("vix_value", 20f);
        float vixChangePct = prefs.getFloat("vix_change_pct", 0f);
        float pctChange = prefs.getFloat("pct_change", 0f);
        String trendArrow = prefs.getString("trend_arrow", "▸");
        int trendColor = prefs.getInt("trend_color", 0xFF64748B);
        float totalReturnPct = prefs.getFloat("total_return_pct", Float.NaN);
        String pctsJson = prefs.getString("symbol_pcts", "{}");

        // Per-symbol pct
        float[] sidePcts = new float[6];
        boolean hasSideData = false;
        try {
            JSONObject obj = new JSONObject(pctsJson);
            for (int i = 0; i < SIDE_SYMBOLS.length; i++) {
                if (obj.has(SIDE_SYMBOLS[i])) {
                    sidePcts[i] = (float) obj.optDouble(SIDE_SYMBOLS[i], 0);
                    hasSideData = true;
                }
            }
        } catch (Exception e) { /* ignore */ }

        // Logolari yukle
        Bitmap[] sideLogos = new Bitmap[6];
        int[] logoRes = {
                R.drawable.logo_nvda, R.drawable.logo_ibit, R.drawable.logo_nvo,
                R.drawable.logo_qqq,  R.drawable.logo_iau,  R.drawable.logo_smci
        };
        for (int i = 0; i < 6; i++) {
            try {
                sideLogos[i] = BitmapFactory.decodeResource(context.getResources(), logoRes[i]);
            } catch (Exception e) { /* ignore */ }
        }

        // Gun ici seans grafigi serisi (yoksa grafik alani ayrilmaz)
        NavSeries.Data series = NavSeries.parse(prefs.getString("nav_series", null));
        float prevCloseNav = prefs.getFloat("prev_close_nav", Float.NaN);

        // Widget'in ANA EKRANDAKI gercek olculeri - kullanici buyuttugunde
        // fazla yukseklik bos kalmasin diye bitmap ayni orana cizilir.
        int wDp = DEFAULT_W_DP, hDp = 0;
        try {
            android.os.Bundle opts = manager.getAppWidgetOptions(widgetId);
            if (opts != null) {
                int w = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_WIDTH, 0);
                int h = opts.getInt(AppWidgetManager.OPTION_APPWIDGET_MAX_HEIGHT, 0);
                if (w > 0) wDp = w;
                if (h > 0) hDp = h;
            }
        } catch (Exception e) { /* varsayilan olculer */ }

        DisplayMetrics dm = context.getResources().getDisplayMetrics();
        float scale = dm.density;
        if (hDp > 0) {
            // Cok buyuk widget'ta piksel butcesini asma
            float maxScale = (float) Math.sqrt((double) MAX_PIXELS / (wDp * hDp));
            if (maxScale < scale) scale = maxScale;
        }

        int bmpW = (int) (wDp * scale);
        int gaugeH = Math.round(bmpW * GAUGE_ASPECT);
        int chartH = 0;
        if (series != null) {
            int minChart = Math.round(MIN_CHART_DP * scale);
            int maxChart = Math.round(gaugeH * MAX_CHART_RATIO);
            int avail = hDp > 0 ? (int) (hDp * scale) - gaugeH : minChart;
            chartH = Math.max(minChart, Math.min(maxChart, avail));
        }

        Bitmap gauge;
        if (navText != null && changeText != null) {
            gauge = GaugeDrawer.draw(bmpW, gaugeH, chartH, navText, changeText,
                    pctChange, vixValue, trendArrow, trendColor, totalReturnPct,
                    hasSideData ? sidePcts : null, sideLogos, vixChangePct, series, prevCloseNav);
        } else {
            gauge = GaugeDrawer.draw(bmpW, gaugeH, chartH, "$---,---", "app'i ac",
                    0f, 20f, "▸", 0xFF64748B, Float.NaN, null, sideLogos, 0f, series, prevCloseNav);
        }

        RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_layout);
        views.setImageViewBitmap(R.id.widget_gauge, gauge);

        // Tiklaninca app'i ac
        Intent launchIntent = new Intent(context, MainActivity.class);
        launchIntent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent pendingIntent = PendingIntent.getActivity(
                context, 0, launchIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        views.setOnClickPendingIntent(R.id.widget_root, pendingIntent);

        manager.updateAppWidget(widgetId, views);
    }
}
