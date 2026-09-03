# Sigorta acentesi sitesi

Yerel bir sigorta acentesi için yaptığım tek sayfalık site. Framework yok, build adımı yok — `index.html`'i tarayıcıda açınca çalışıyor.

Proje WordPress temasına geçmeden önce müşteri tarafından iptal edildi. Burada duran şey prototipin son hâli.

İçerikteki firma adı, telefon, adres ve anlaşmalı şirket isimleri gerçek değil, hepsini yer tutucuyla değiştirdim.

## Neler var

Randevu takvimi, WhatsApp üzerinden çalışan teklif akışı, geri arama formu, Türkçe/İngilizce geçiş, aydınlık-karanlık tema ve 15 ürün kartı.

Sayfa gzip'li hâlde 43 KB. Tek dış bağımlılık Google Fonts, o da yayına çıkarken kendi sunucusuna alınacaktı.

Randevu takvimi geçmiş günleri ve pazarları kapatıyor, dolu saatleri gizlemek yerine devre dışı bırakıyor, açılışta ilk müsait günü seçiyor. Prototipte kayıtlar `localStorage`'da; gerçek kurulumda `wordpress/` klasöründeki eklenti devralıyor.

Teklif formu sunucuya bir şey göndermiyor. Kullanıcı bilgilerini bırakınca `wa.me` bağlantısı üretiliyor ve WhatsApp hazır mesajla açılıyor, tek yapması gereken gönder'e basmak. Acentenin zaten WhatsApp Business'ı vardı ve ayrıca bir panele bakmak istemiyordu.

## Dosyalar

```
index.html                            site (HTML + CSS + JS, hepsi burada)
kvkk.html, gizlilik.html, cerez.html  yasal metin taslakları
araclar/gorsel-hazirla.html           görsel kırpma/sıkıştırma aracı
wordpress/                            randevu ve geri arama eklentileri
testler/                              Playwright testleri
gorseller/                            boş, OKUBENI.md'de ne geleceği yazıyor
```

## Testler

```bash
npm i -D playwright && npx playwright install chromium
node testler/arayuz-testi.js
node testler/giris-testi.js
```

`arayuz-testi.js` sayfayı 1440, 1280, 834, 390 ve 320 pikselde açıp yatay taşma var mı diye bakıyor, mobilde 40 pikselden küçük dokunma hedeflerini listeliyor, sonra teklif modalını ve randevu takvimini gerçekten kullanıyor. `giris-testi.js` form alanlarına saçma girdiler yazıp ne kaldığına bakıyor.

320 piksel taşmasını üç kere ayrı sebepten kovaladım: önce başlıktaki `white-space: nowrap`, sonra `<select>` elementinin en uzun seçeneği kadar yer istemesi, en sonunda takvim günlerindeki `aspect-ratio`. Genel bir `min-width: 0` bloğu son ikisini birden çözdü.

## Uğraştıran birkaç şey

**Ürün kartlarındaki fotoğraflar hiç yüklenmiyordu.** Kartlar kapalı grupların içinde, yani `display: none`. Tarayıcı görünmeyen kapsayıcıdaki `loading="lazy"` görselini hiç istemiyor. Fotoğrafları `data-src`'de tutup görünür karta `IntersectionObserver` bağladım.

**Tema seçimi sayfa açılırken bir kare yanıp sönüyordu.** Tercihi okuyan betiği `<head>` içine, CSS'ten önceye aldım.

**İngilizce çeviri** için ayrı bir HTML tutmak istemedim. Sayfa yüklenince metin düğümlerini gezip anlık görüntü alıyorum, sözlük Türkçe metnin kendisiyle anahtarlanıyor. Bu anlık görüntüyü takvim ve seçim kutuları oluşmadan önce almak gerekiyor, yoksa dinamik içerik de sözlüğe giriyor ve dil değişince bozuluyor.

**Ad alanına `!!!....@@@` gibi şeyler girilebiliyordu.** Yazarken süzen bir filtre yazdım, imleç konumunu koruyor. İlk denemede `....` ve `+(` gibi kalıntılar geçiyordu; ikinci turda harf kalmadıysa alanı komple boşaltacak şekilde değiştirdim.

## Eksikler

WordPress teması hiç yazılmadı, proje o aşamaya gelmeden bitti. Yorumlar bölümündeki metinler uydurma ve arayüzde "örnek" diye işaretli. Yasal metinler taslak.
