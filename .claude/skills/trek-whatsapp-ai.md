# Trek WhatsApp AI Chatbot

## Proje Bilgileri

**Local Dosya:** `C:\Users\samik\Downloads\app.py`
**Hugging Face Space:** `SamiKoen/BF-WAB` (https://huggingface.co/spaces/SamiKoen/BF-WAB)
**Hugging Face Token:** `$HF_TOKEN`

## Mimari

### Hybrid Model Yapısı
- **GPT-4o:** Görsel içeren mesajlar için (Vision)
- **GPT-5.2:** Metin mesajları için (model: `gpt-5.2-chat-latest`)
- **Fallback:** GPT-4o

### Dosya Yapısı (Hugging Face Space)
```
BF-WAB/
├── app.py                          # Ana uygulama (app_hybrid_model.py'nin kopyası)
├── smart_warehouse_with_price.py   # Stok sorgusu ve fiyat bilgisi
├── intent_analyzer.py              # Müşteri niyet analizi
├── prompts.py                      # System promptları
├── customer_manager.py             # Müşteri profil yönetimi
├── follow_up_system.py             # Takip sistemi
├── store_notification.py           # Mağaza bildirimleri
├── whatsapp_renderer.py            # WhatsApp formatlaması
├── whatsapp_passive_profiler.py    # Pasif profil sistemi
├── media_queue_v2.py               # Medya kuyruğu
└── requirements.txt
```

## Mağaza Bilgileri

### Telefon Numaraları (store_notification.py)
```python
STORE_NUMBERS = {
    "caddebostan": "+905439340438",   # Caddebostan mağaza
    "sariyer": "+905421371080",       # Sarıyer/Bahçeköy mağaza
    "alsancak": "+905439362335",      # İzmir Alsancak - Mehmet Bey
    "merkez": "+905439362335"         # Merkez bildirimler
}
# NOT: Ortaköy mağazası 1 Aralık 2025'te kapandı - listeden kaldırıldı
```

### Depolar (BizimHesap)
- **Caddebostan:** Prof. Dr. Hulusi Behçet 18, Kadıköy
- **Sarıyer/Bahçeköy:** Mareşal Fevzi Çakmak Cad. No 54
- **Alsancak:** Sezer Doğan Sok. The Kar Suits 14A, İzmir
- ~~Ortaköy: KAPALI (1 Aralık 2025)~~

## Veri Kaynakları

### 1. IdeaSoft XML (Ürün Bilgileri)
- **URL:** `https://www.trekbisiklet.com.tr/output/8582384479`
- **İçerik:** Ürün adı, fiyat, stok, link, görsel, kategori, SKU

### 2. BizimHesap API (Depo Stokları)
- **URL:** `https://api.bizimhesap.com/api/warehouseproducts`
- **API Key:** `66d0c059a25847a3993ab611`
- **API Secret:** `c4d8bb29f26b409c8b9df0e22edbe05b`

## Ürün Eşleştirme Algoritması

### Akıllı Skor Hesaplama (`calculate_smart_match_score`)

```python
# Temel kelime eşleşmesi: +1 puan (her kelime için)
# Bisiklet aramasında bisiklet bulunca: +2 bonus
# Bisiklet aramasında aksesuar bulunca: -100 ceza
# Kritik varyant (AXS, Di2, eTap) uyuşmazlığı: -50 ceza
# Tam kelime eşleşmesi: +3 bonus
# Doğru model numarası: +2 bonus
# Yanlış model numarası: -20 ceza
```

### Kritik Varyantlar
Bu kelimeler FARKLI ÜRÜNLERİ temsil eder:
- `axs` - SRAM AXS kablosuz vites
- `etap` - SRAM eTap elektronik vites
- `di2` - Shimano Di2 elektronik vites
- `frameset` - Sadece kadro seti

### Kategori Bazlı Filtreleme
- Bisiklet aramasında aksesuar gösterilmez
- Kategori ağacından tip belirlenir: road_bike, mtb, ebike, hybrid, accessory

## Depo Keyword Mapping

```python
warehouse_keywords = {
    'caddebostan': 'Caddebostan',
    'alsancak': 'Alsancak',
    'izmir': 'Alsancak',
    'bahçeköy': 'Bahçeköy',
    'bahcekoy': 'Bahçeköy',
    'sarıyer': 'Bahçeköy',
    'sariyer': 'Bahçeköy'
}
# NOT: Ortaköy kaldırıldı - mağaza kapalı
```

## API Endpointleri

### Hugging Face Space
- `GET /` - Health check
- `POST /whatsapp` - Twilio webhook

### Twilio WhatsApp
- Messaging Service SID gerekli
- Media URL desteği (görsel gönderme)

## Yaygın Sorunlar ve Çözümleri

### 1. Yanlış Ürün Eşleşmesi
**Sorun:** "Madone SLR 9" sorulduğunda "Madone SLR 9 AXS" gösteriliyor
**Çözüm:** Kritik varyant kontrolü ile -50 puan cezası

### 2. Aksesuar Karışıklığı
**Sorun:** "Madone" aramasında "Madone Sele Borusu" gösteriliyor
**Çözüm:** Kategori bazlı filtreleme ile -100 puan cezası

### 3. Yanlış Depo Bilgisi
**Sorun:** "Sarıyer" denince farklı depo gösteriliyor
**Çözüm:** `warehouse_keywords` mapping ile Sarıyer -> Bahçeköy

### 4. Context Kaybı
**Sorun:** "Fiyat ne" denince önceki ürün unutuluyor
**Çözüm:** Intent Analyzer ve conversation context kullanımı

### 5. Duplicate Link Sorunu
**Sorun:** Yanıtta link 2 kere görünüyor
**Çözüm:** System message'a link eklenmemeli, sadece formatted_response'a eklenmeli

### 6. Görsel Her Seferinde Gösterilmesi
**Sorun:** Her yanıtta görsel gösterilmesi gerekiyor mu?
**Çözüm:** `is_specific_product_query = best_match_score >= 3` - Sadece spesifik ürün sorularında görsel

## Deploy Prosedürü

### Hugging Face'e Değişiklik Gönderme
```bash
# Repo clone
cd /tmp && rm -rf bf-wab-clone
git clone https://SamiKoen:$HF_TOKEN@huggingface.co/spaces/SamiKoen/BF-WAB bf-wab-clone

# Değişiklikleri yap
cd /tmp/bf-wab-clone
git config user.email "samikoen70@gmail.com"
git config user.name "SamiKoen"

# Local dosyayı kopyala (gerekirse)
cp "c:\Users\samik\Downloads\app.py" /tmp/bf-wab-clone/app.py

# Commit ve push
git add .
git commit -m "Açıklama"
git push
```

### Space Restart
```bash
curl -X POST "https://huggingface.co/api/spaces/SamiKoen/BF-WAB/restart" \
  -H "Authorization: Bearer $HF_TOKEN"
```

### Deploy Doğrulama
```bash
# SHA kontrolü
curl -s "https://huggingface.co/api/spaces/SamiKoen/BF-WAB" \
  -H "Authorization: Bearer $HF_TOKEN" | grep sha

# Health check
curl -s "https://samikoen-bf-wab.hf.space/"
```

## Tuple Yapısı (products listesi)

```python
# products = [(name, item_info, full_name), ...]

# item_info tuple indeksleri:
# [0] stock_amount - "stokta" veya "stokta degil"
# [1] price - Fiyat (formatlanmış)
# [2] product_link - Ürün linki
# [3] price_eft - Havale fiyatı
# [4] stock_number - Stok adedi (string)
# [5] picture_url - Ürün görseli URL
# [6] category_tree - Kategori ağacı
# [7] category_label - Kategori etiketi
# [8] stock_code - SKU
# [9] root_product_stock_code
# [10] is_option_of_product
# [11] is_optioned_product
```

## Test Senaryoları

| Sorgu | Beklenen Sonuç |
|-------|----------------|
| "Madone SLR 9 fiyatı" | Madone SLR 9 (AXS olmayan) |
| "Madone SLR 9 AXS fiyatı" | Sadece AXS versiyonu |
| "Sarıyer'de Madone var mı" | Bahçeköy deposundaki Madone'lar |
| "Fiyat ne" (context'te ürün var) | Önceki ürünün fiyatı |

## Son Yapılan Temizlik (Ocak 2026)

### Faz 1: Kritik Düzeltmeler
- ✅ Telefon numaraları düzeltildi (Caddebostan, Sarıyer)
- ✅ Ortaköy mağazası kaldırıldı (kapalı)
- ✅ intent_analyzer.py model adı düzeltildi (`gpt-5.2-chat-latest`)
- ✅ Duplicate link sorunu çözüldü
- ✅ Bold (*) ve emoji formatlaması devre dışı bırakıldı (prompts.py)

### Faz 2: Dosya Temizliği
Silinen dosyalar (20 dosya, ~400 KB):
- 5× `*_calismiyor_*` (başarısız denemeler)
- 10× `*_backup_*` (eski yedekler)
- 5× `debug_*/check_*` (debug araçları)

### Bilinen Sorunlar (Faz 3 - Beklemede)
- Fiyat yuvarlama fonksiyonu 3 yerde tekrar ediyor
- Turkish karakter normalizasyonu 3 farklı implementasyon
- Hardcoded değerler config'e taşınabilir

## 15 Ocak 2026 Düzeltmeleri

### 1. Gobik Marka Arama Sorunu (ÇÖZÜLDÜ)
**Sorun:** "Gobik var mı" sorulduğunda "mağazalarda stok bulunmuyor" yanıtı veriliyordu.

**Kök Neden:** `smart_warehouse_with_price.py` satır 336-339'da tek kelime filtresi vardı:
```python
if len(clean_message.split()) == 1 and len(clean_message) < 5:
    return None  # Kısa kelimeler ürün değil sayılıyordu
```

**Çözüm:** Marka keyword listesi eklendi (satır 336-340):
```python
brand_keywords = ['gobik', 'trek', 'bontrager', 'kask', 'shimano', 'sram', 'garmin', 'wahoo']
contains_brand = any(brand in clean_message for brand in brand_keywords)

# Marka içeren mesajlar filtreleri bypass eder
if not contains_brand and len(clean_message.split()) == 1 and len(clean_message) < 5:
    return None
```

**Dosya:** `smart_warehouse_with_price.py`

### 2. GPT-5 API Error 400 (ÇÖZÜLDÜ)
**Sorun:** Log'da `GPT-5 API error: 400` hatası görülüyordu.

**Kök Neden:** GPT-5.2 modeli `temperature` ve `max_tokens` parametrelerini desteklemiyor.

**Çözüm:**
- `intent_analyzer.py` satır 111-116: `temperature` ve `max_tokens` kaldırıldı
- `smart_warehouse_with_price.py` satır 545-552: `max_tokens` kaldırıldı

```python
# ÖNCE (hatalı):
payload = {
    "model": "gpt-5.2-chat-latest",
    "temperature": 0,
    "max_tokens": 300,
    ...
}

# SONRA (düzeltildi):
payload = {
    "model": "gpt-5.2-chat-latest",
    ...  # temperature ve max_tokens YOK
}
```

**Dosyalar:** `intent_analyzer.py`, `smart_warehouse_with_price.py`

### 3. "Linki ver" Yanlış Link Sorunu (ÇÖZÜLDÜ)
**Sorun:** "Gobik var mı" sonrası "Linki ver" denildiğinde GPT genel marka linki veriyordu (`trekbisiklet.com.tr/marka/gobik`) - spesifik ürün linki yerine.

**Kök Neden:** Stok sorgusunda bulunan link GPT context'ine kaydedilmiyordu.

**Çözüm:** Context'e yeni alanlar eklendi:

1. `get_conversation_context()` fonksiyonuna yeni alanlar (satır 731-737):
```python
conversation_memory[phone_number] = {
    "messages": [],
    "current_category": None,
    "current_product": None,         # YENİ
    "current_product_link": None,    # YENİ
    "current_product_price": None,   # YENİ
    "last_activity": None
}
```

2. Stok sorgusu sonrası link kaydediliyor (satır 1313-1324):
```python
context = get_conversation_context(phone_number)
link_match = re.search(r'Link: (https?://[^\s]+)', stock_msg)
if link_match:
    context["current_product_link"] = link_match.group(1)
```

3. `build_context_messages()` fonksiyonuna link eklendi (satır 814-821):
```python
if context.get("current_product"):
    product_context = f"Son konusulan urun: {context['current_product']}"
    if context.get("current_product_link"):
        product_context += f"\nUrun linki: {context['current_product_link']}"
    system_messages.append({"role": "system", "content": product_context})
```

**Dosya:** `app.py`

## Test Senaryoları (Güncellenmiş)

| Sorgu | Beklenen Sonuç |
|-------|----------------|
| "Madone SLR 9 fiyatı" | Madone SLR 9 (AXS olmayan) |
| "Madone SLR 9 AXS fiyatı" | Sadece AXS versiyonu |
| "Sarıyer'de Madone var mı" | Bahçeköy deposundaki Madone'lar |
| "Fiyat ne" (context'te ürün var) | Önceki ürünün fiyatı |
| **"Gobik var mı"** | **Gobik ürünleri + stok + fiyat** |
| **"Linki ver"** | **Önceki ürünün spesifik linki** |
| **"Marlin 4 var mı"** | **Marlin 4 bisiklet görseli (aksesuar değil)** |
| **MTB sonrası "Alsancakta hangi var"** | **Alsancak'taki MTB'ler (DS değil)** |
| **Herhangi bir soru** | **"siz" hitabı, soru ile bitmez** |

## Notlar

- Local dosya (`app.py`) ile HF (`app.py`) senkron tutulmalı
- Değişiklikler yapıldıktan sonra HF Space restart edilmeli
- Cache süresi 2 saat (CACHE_DURATION = 7200)
- Ortaköy mağazası 1 Aralık 2025'te kapandı - kullanılmamalı
- **GPT-5.2 modeli `temperature` ve `max_tokens` desteklemiyor - payload'a ekleme!**
- **Marka aramaları için `brand_keywords` listesi kullanılıyor**

### 4. Türkçe Resmi Dil Kuralları (ÇÖZÜLDÜ)
**Sorun:** Chatbot müşteriye "sen" diye hitap ediyordu ("istersen") ve cümleleri soru ile bitiriyordu ("ayırtayım mı?").

**Çözüm:**
1. `prompts.py` satır 14'e kurallar eklendi
2. `app.py`'de `turkish_reminder` değişkeni GPT çağrısından hemen önce eklendi (son system message olarak)

```python
# app.py satır 1382-1388 (turkish_reminder):
turkish_reminder = """KRITIK KURALLAR (HER YANIT ICIN GECERLI):
1. ASLA 'sen' kullanma, HER ZAMAN 'siz' kullan (istersen -> isterseniz, sana -> size)
2. ASLA soru ile bitirme (ayirtayim mi?, ister misiniz?, bakar misiniz? YASAK)
3. Bilgiyi ver ve sus, musteri karar versin
4. ONEMLI: Onceki mesajlarda bahsedilen urunleri UNUTMA! "Hangi model var" gibi sorular onceki konudan devam eder.
YANLIS: "Istersen beden ve magaza bazli stok bilgisini de netlestirebilirim."
DOGRU: "Beden ve magaza bazli stok bilgisi icin yazabilirsiniz." """
```

**Dosyalar:** `prompts.py`, `app.py`

### 5. Yanlış Görsel Sorunu - Aksesuar/Bisiklet Karışıklığı (ÇÖZÜLDÜ)
**Sorun:** "Marlin 4" sorulduğunda "Trek Domane SLR Disk Kadro Kulağı" görseli gösteriliyordu.

**Kök Neden:**
1. "Kadro kulağı" accessory keywords listesinde yoktu
2. XML'de kategori "YEDEK PARÇA" (ç harfi ile) ama kod "parca" (c harfi ile) arıyordu
3. Aksesuar kontrolü bisiklet kontrolünden sonra geliyordu

**Çözüm:**

1. Türkçe karakter düzeltmesi (`app.py` satır 1171):
```python
# ÖNCE:
elif 'aksesuar' in cat_lower or 'parca' in cat_lower or 'accessory' in cat_lower:
    return 'accessory'

# SONRA:
elif 'aksesuar' in cat_lower or 'parça' in cat_lower or 'parca' in cat_lower or 'accessory' in cat_lower or 'yedek' in cat_lower:
    return 'accessory'
```

2. Genişletilmiş aksesuar keywords listesi (`app.py` satır 1177-1181):
```python
accessory_keywords = ['sele', 'gidon', 'pedal', 'zincir', 'lastik', 'jant', 'fren', 'vites',
                     'kadro kulağı', 'kadro kulagi', 'kulak', 'kablo', 'kasnak', 'dişli',
                     'zil', 'far', 'lamba', 'pompa', 'kilit', 'çanta', 'canta', 'suluk',
                     'gözlük', 'gozluk', 'kask', 'eldiven', 'ayakkabı', 'ayakkabi',
                     'forma', 'tayt', 'şort', 'sort', 'mont', 'yağmurluk', 'yagmurluk']
```

**Dosya:** `app.py`

### 6. Context Kaybı Sorunu - MTB/DS Karışıklığı (ÇÖZÜLDÜ)
**Sorun:** MTB modelleri (Marlin 5, 6, 7) hakkında konuştuktan sonra "Alsancakta hangi model var" denildiğinde DS serisi gösteriliyordu.

**Kök Neden:** GPT konuşma bağlamını dikkate almıyordu. Önceki mesajlarda MTB'lerden bahsedilmesine rağmen genel cevap veriyordu.

**Çözüm:** `build_context_messages()` fonksiyonunda context mesajı güçlendirildi (`app.py` satır 813-817):

```python
# ÖNCE:
if context.get("current_category"):
    category_msg = f"Kullanici su anda {context['current_category'].upper()} kategorisi hakkinda konusuyor."

# SONRA:
if context.get("current_category"):
    cat = context['current_category'].upper()
    category_msg = f"""KRITIK BAGLAIM BILGISI:
Musteri su anda {cat} modelleri hakkinda konusuyor.
Butun sorulari bu baglamda cevapla.
"Hangi model var", "stok var mi", "fiyat ne" gibi sorular {cat} icin sorulmus demektir.
DS, FX, Verve gibi BASKA kategorilerden bahsetme - sadece {cat} hakkinda konusuyoruz!"""
```

**Dosya:** `app.py`

### 7. Vision Mesajlarinda Yanlis Stok Bilgisi (COZULDU)
**Sorun:** Musteri gorsel gonderince bot "hicbir magazamizda stokta bulunmuyor" diyordu - oysa stokta vardi!

**Kok Neden:** `process_whatsapp_message_with_media()` fonksiyonunda GPT-4o gorsel analizinden sonra GERCEK stok kontrolu yapilmiyordu. GPT kendi bilgisinden "stokta yok" cikariyor - bu YANLIS.

**Cozum:** Vision mesaj islemesine stok kontrolu eklendi:

1. Yeni fonksiyon eklendi (`app.py` satir 852):
```python
def extract_product_from_vision_response(response):
    """GPT Vision yanitindan urun adini cikarir"""
    # Trek model pattern'leri ile urun adini cikar
    # Ornek: "Trek Domane+ SLR 7 AXS" -> "Domane+ SLR 7 AXS"
```

2. Vision response'dan sonra stok kontrolu (`app.py` satir 1032):
```python
# KRITIK: Gorselden urun adini cikar ve GERCEK stok kontrolu yap
product_name = extract_product_from_vision_response(ai_response)
if product_name:
    stock_info = get_warehouse_stock(product_name)
    if stock_info:
        # GPT yanlis "stokta yok" dediyse duzelt
        if "stokta bulunmuyor" in ai_response.lower():
            # Yanlis bilgiyi kaldir
            ai_response = re.sub(r'[^.]*stok[^.]*bulunmuyor[^.]*', '', ai_response)
        # Gercek stok bilgisini ekle
        ai_response = ai_response + "\n\n" + stock_info
```

**Dosya:** `app.py`

### 8. Vision Mesajlarinda Kisa Soru Sorunu (COZULDU)
**Sorun:** Musteri gorsel ile birlikte "var mi" gibi kisa soru gonderdiginde, GPT-4o gorseldeki bisikleti tanimiyor ve yanlis urun (sele) gosteriyordu.

**Kok Neden:** Kisa sorularda ("var mi", "stok", "fiyat") GPT-4o'ya sadece metin gonderiliyordu, gorseli dikkate almasi icin yeterli talimat verilmiyordu.

**Cozum:** Vision mesaj islemesinde kisa sorular icin gelismis prompt eklendi (`app.py` satir 914-936):

```python
# Kisa soru tespiti
short_questions = ['var mi', 'var mı', 'stok', 'fiyat', 'kac', 'kaç', 'ne kadar', 'beden', 'renk']
is_short_question = len(user_message.strip()) < 20 and any(q in user_message.lower() for q in short_questions)

if is_short_question:
    # Gelismis prompt - gorseldeki bisikleti tanimla
    enhanced_text = f"Gorseldeki BISIKLETI dikkatlice incele ve model adini tespit et. Musteri bu bisiklet icin '{user_message}' soruyor. Gorseldeki bisikletin TAM MODEL ADINI (ornegin 'Trek Domane+ SLR 7 AXS') belirle ve buna gore cevap ver."
```

**Dosya:** `app.py`

### 9. Odeme ve Banka Bilgileri Eklendi (YENI)
**Eklenen:** Calisilan bankalar ve kart programlari prompts.py'ye eklendi.

**Dosya:** `prompts.py` (Bolum 15 - payment_info)

```python
{
    "role": "system",
    "category": "payment_info",
    "content": "ODEME VE TAKSIT SECENEKLERI:\nCalistigimiz bankalar ve kart programlari:\n- Axess (Akbank)\n- Bonus (Garanti BBVA)\n- Maximum (Is Bankasi)\n- World (Yapi Kredi)\n- CardFinans (QNB Finansbank)\n- Paraf (Halkbank)\n- Combo (Halkbank)\n\nTum bu kartlarla taksitli alisveris yapilabilir."
}
```

**Bankalar:**
| Kart Programi | Banka |
|---------------|-------|
| Axess | Akbank |
| Bonus | Garanti BBVA |
| Maximum | Is Bankasi |
| World | Yapi Kredi |
| CardFinans | QNB Finansbank |
| Paraf | Halkbank |
| Combo | Halkbank |

**Dosya:** `prompts.py`

### 10. DS Elektriksiz Modeller Uretimden Kaldirildi (YENI)
**Bilgi:** DS (Dual Sport) elektriksiz modellerin uretimi 2026 itibariyle durduruldu.

**Prompt:** `prompts.py` (Bolum 18 - discontinued_models)

**Musteri Yonlendirmesi:**
- DS elektriksiz arayan musteriye -> FX serisi oner (DS yerine gecen model)
- Alternatif olarak Verve serisi de onerilebilir
- Mevcut stoklar satilabilir, ancak yeni model GELMEYECEK

**Dosya:** `prompts.py`

### 11. Gereksiz Gorsel Gonderme Sorunu (COZULDU - 21 Ocak 2026)
**Sorun:** Musteri "56 kadro boyu hangi bedene karsilik geliyor, XL mi L mi?" gibi bilgi sorulari sorduğunda bot gereksiz yere bisiklet gorseli gonderiyordu.

**Kok Neden:** Bot her urun sorgusu icin gorsel gonderiyordu - oysa beden/size/garanti/taksit gibi BILGI sorularinda gorsele gerek yok.

**Cozum:** Gorsel gonderilmemesi gereken soru tipleri tanimlandi (`app.py` satir 1541-1552):

```python
# GORSEL GONDERILMEMESI GEREKEN SORU TIPLERI
no_image_keywords = [
    'beden', 'size', 'kadro', 'boy', 'kac cm', 'kaç cm',
    'hangi beden', 'xl mi', 'l mi', 'm mi', 's mi',
    'geometri', 'stack', 'reach', 'standover',
    'kilo', 'agirlik', 'ağırlık', 'weight',
    'garanti', 'warranty', 'teslimat', 'kargo',
    'taksit', 'odeme', 'ödeme', 'kredi', 'havale'
]
user_msg_lower = user_message.lower()
is_info_only_query = any(keyword in user_msg_lower for keyword in no_image_keywords)

# Gorsel sadece spesifik urun sorgusu VE bilgi sorgusu DEGILSE gonder
if found_product_image and is_specific_product_query and not is_info_only_query:
    return (formatted_response, found_product_image)
```

**Engellenen soru tipleri:**
- Beden/size sorulari: "hangi beden", "XL mi L mi", "56 kadro"
- Geometri sorulari: "stack", "reach", "standover"
- Odeme sorulari: "taksit", "garanti", "teslimat"

**Dosya:** `app.py`

## GitHub Entegrasyonu (21 Ocak 2026)

### Otomatik Deploy
- **GitHub Repo:** https://github.com/samikoen/calude-projects
- **Klasor:** `trek-whatsapp-ai/`
- **Workflow:** `.github/workflows/deploy-huggingface.yml`

### Nasil Calisir
1. GitHub'da `trek-whatsapp-ai/` klasorunde degisiklik yap
2. Commit et
3. GitHub Actions otomatik HuggingFace'e deploy eder

### Mobilden Kullanim
- GitHub web veya claude.ai/code uzerinden dosya duzenle
- PC'ye bagli kalmadan calisabilirsin

## 24 Ocak 2026 Guncellemeleri

### 1. Desktop Versiyonu (BF Space) Senkronizasyonu
**Sorun:** Desktop versiyonu WhatsApp'tan farkli cevaplar veriyordu (ornegin "Fuel LX" icin yanlis bilgi).

**Yapilan Degisiklikler:**
1. `smart_warehouse_with_price.py` WhatsApp versiyonuyla senkronize edildi
2. `prompts.py` WhatsApp versiyonuyla senkronize edildi
3. GPT direktifi guclendirildi ("KRITIK - GERCEK STOK VERISI")

**Dosyalar:** BF Space - `smart_warehouse_with_price.py`, `prompts.py`, `app.py`

### 2. Fuel+ LX "+" Karakter Sorunu (COZULDU)
**Sorun:** "fuel + lx" veya "fuel lx" yazildiginda urun bulunamiyordu cunku XML'de "FUEL+ LX" var.

**Cozum:** `smart_warehouse_with_price.py`'de normalize eklendi:
```python
plus_models = ['fuel', 'domane', 'fx', 'ds', 'verve', 'townie', 'allant']
for model in plus_models:
    clean_message = clean_message.replace(f'{model} + ', f'{model}+ ')
    if f'{model} lx' in clean_message:
        clean_message = clean_message.replace(f'{model} lx', f'{model}+ lx')
```

**Dosya:** `smart_warehouse_with_price.py` (her iki Space)

### 3. Fuel+ LX Motor/Pil Bilgisi Eklendi
**Sorun:** Bot yanlis motor bilgisi veriyordu.

**Eklenen Bilgiler (prompts.py):**
```
FUEL+ LX MODEL BILGILERI:
- Motor: TQ HPR50 (60 Nm tork)
- Pil: 580 Wh kapasiteli dahili batarya
- Vites: Shimano XT Di2 elektronik vites (12 vites)
- Suspansiyon: On 150mm / Arka 140mm
- Kadro: Karbon (OCLV Mountain Carbon)
- Kategori: E-MTB / Elektrikli Trail

ONEMLI FARKLAR:
- Fuel+ LX = Elektrikli (TQ motor, 580Wh pil)
- Fuel EX = Elektriksiz (klasik trail bisiklet)
- Fuel EXe = Elektrikli (daha hafif e-MTB)
```

**Dosya:** `prompts.py` (her iki Space)

### 4. Desktop Tutarsiz Stok Yaniti Sorunu (COZULDU)
**Sorun:** Desktop versiyonu ayni soru icin bazen "stokta var" bazen "stokta yok" diyordu.

**Kok Neden:** `improved_bot` ve `warehouse_stock_data` iki farkli cache kullaniyordu.

**Cozum:** `improved_bot` devre disi birakildi, sadece `warehouse_stock_data` kullaniliyor:
```python
# SADECE warehouse_stock_data kullan - improved_bot devre disi (tutarsizlik yaratiyor)
product_found_improved = False
```

**Dosya:** BF Space - `app.py`

## Desktop (BF) vs WhatsApp (BF-WAB) Farklari

| Ozellik | BF (Desktop) | BF-WAB (WhatsApp) |
|---------|--------------|-------------------|
| SDK | Gradio | Docker (Flask) |
| Arayuz | Web chat | Twilio webhook |
| Gorsel | Gradio gallery | WhatsApp media |
| improved_bot | DEVRE DISI | Yok |

## Senkronize Edilmesi Gereken Dosyalar

Her iki Space'te de ayni olmasi gereken dosyalar:
1. `prompts.py` - Sistem promptlari
2. `smart_warehouse_with_price.py` - Stok sorgu algoritmasi

Farkli olmasi gereken dosyalar:
1. `app.py` - Farkli SDK ve arayuz
2. `requirements.txt` - Farkli bagimliliklar
