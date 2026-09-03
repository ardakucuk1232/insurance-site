# Görsel klasörü

Klasör bilerek boş. Site fotoğrafsız da bozulmuyor, her fotoğraf kutusunda tasarlanmış bir yer tutucu görünüyor.

Fotoğraf koyacaksan dosya adları aynen böyle olmalı:

| Dosya | Nerede | En az boyut |
|---|---|---|
| `hero.jpg` | giriş ekranı arka planı | 1920 × 1080 |
| `ofis.jpg` | "Farkımız" bölümü | 1000 × 1400 (dikey) |
| `dis-cephe.jpg` | Hakkımızda, büyük kutu | 1000 × 1400 (dikey) |
| `tabela.jpg` | Hakkımızda, küçük kutu | 1200 × 800 |
| `ofis-ici.jpg` | Hakkımızda, küçük kutu | 1200 × 800 |

Ürün kartları için `urun/` klasörü. Hangi kartın hangi dosyayı beklediği `index.html` içindeki `data-src` özniteliklerinde yazılı.

Ürün kartlarında yazı fotoğrafın üstüne biniyor. Alt yarısı sakin, orta-koyu tonlu fotoğraf seç; parlak fotoğrafta beyaz yazı okunmuyor.

Hazırlamak için `araclar/gorsel-hazirla.html` dosyasını tarayıcıda aç, fotoğrafları sürükle. Kendin yapacaksan giriş görselini 250 KB, diğerlerini 150 KB altında tut. Optimize edilmemiş tek bir fotoğraf 4 MB olabiliyor, o da sayfanın tamamının yaklaşık yüz katı.
