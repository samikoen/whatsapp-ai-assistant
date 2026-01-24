# Claude Oturum Notlari

Bu dosya her oturumda okunmalidir!

## FTP DEPLOY BILGILERI (ZORUNLU)

GitHub commit sonrasi MUTLAKA sunucuya da FTP ile deploy et!

### FTP Bilgileri
| Bilgi | Deger |
|-------|-------|
| **Host** | `77.245.148.150` |
| **Port** | `21` |
| **Protocol** | FTPS (Explicit TLS) |
| **Username** | `trektur` |
| **Password** | `FzEPxusbo1?2$z0n` |

### Proje Deploy Hedefleri
| Proje | Lokal | Sunucu | GitHub Repo |
|-------|-------|--------|-------------|
| SOLD | `Downloads/sold/` | `/partner.trek-turkey.com/sold/` | `samikoen/sold` |
| Tavsiye | `Downloads/tavsiye/` | `/partner.trek-turkey.com/tavsiye/` | - |
| BizimHesap | `Downloads/bizimhesap/` | `/partner.trek-turkey.com/bizimhesap/` | - |
| B2B | `Downloads/b2b/` | `/httpdocs/b2b/` | - |

### Curl ile Deploy Komutu
```bash
# Dosya yukle (ornek SOLD)
curl --ftp-ssl --insecure -u 'trektur:FzEPxusbo1?2$z0n' -T "LOKAL_DOSYA_YOLU" "ftp://77.245.148.150/SUNUCU_YOLU"

# Klasor listele
curl --ftp-ssl --insecure -u 'trektur:FzEPxusbo1?2$z0n' "ftp://77.245.148.150/"
```

## DEPLOY KURALI

Her degisiklikten sonra:
1. GitHub'a commit/push
2. FTP ile sunucuya deploy
3. Sayfayi yenileyerek test et

KULLANICIYA SORMADAN DEPLOY ET!

---

## HUGGING FACE DEPLOY BILGILERI

### Credentials
| Bilgi | Deger |
|-------|-------|
| **Username** | `SamiKoen` |
| **Token** | `$HF_TOKEN` |
| **Email** | `samikoen70@gmail.com` |

### Space'ler
| Space | URL | Aciklama |
|-------|-----|----------|
| **BF-WAB** | `SamiKoen/BF-WAB` | WhatsApp chatbot (Docker/Flask) |
| **BF** | `SamiKoen/BF` | Desktop chatbot (Gradio) |

### Deploy Komutlari
```bash
# Clone (WhatsApp)
git clone https://SamiKoen:$HF_TOKEN@huggingface.co/spaces/SamiKoen/BF-WAB bf-wab-clone

# Clone (Desktop)
git clone https://SamiKoen:$HF_TOKEN@huggingface.co/spaces/SamiKoen/BF bf-desktop-clone

# Restart
curl -X POST "https://huggingface.co/api/spaces/SamiKoen/BF-WAB/restart" -H "Authorization: Bearer $HF_TOKEN"

# SHA kontrol
curl -s "https://huggingface.co/api/spaces/SamiKoen/BF-WAB" -H "Authorization: Bearer $HF_TOKEN" | grep sha
```

### Senkronize Dosyalar
Her iki Space'te AYNI olmali:
- `prompts.py`
- `smart_warehouse_with_price.py`

---
Son Guncelleme: 2026-01-24
