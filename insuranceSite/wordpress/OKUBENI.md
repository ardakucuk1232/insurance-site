# WordPress eklentileri

Prototipteki randevu takvimi ve geri arama formu tarayıcıda `localStorage` ile çalışıyor. Gerçek kurulumda bu iki eklenti devreye girer.

## Kurulum

| Eklenti | Klasör |
|---|---|
| `randevu-yonetimi.php` | `wp-content/plugins/acente-randevu/` |
| `geri-arama-talepleri.php` | `wp-content/plugins/acente-geri-arama/` |

Dosyayı klasörüne koy, panelden etkinleştir. Tablolar ilk etkinleştirmede `dbDelta` ile kurulur.

Randevu ayarları (çalışma saatleri, kapalı günler, en erken randevu süresi) **Randevular → Ayarlar** ekranından değişir.

## Tema tarafı bağlantısı

### Randevu — müsait saatleri getir

```js
fetch(acenteRandevu.url + "?action=acente_rnd_saatler&nonce=" + acenteRandevu.nonce + "&gun=" + gunAnahtari)
  .then(r => r.json())
  .then(j => saatleriCiz(j.data.saatler));
```

Dönen dizi: `[{saat: "09:00", musait: true}, ...]`

### Randevu — kaydet

```js
fetch(acenteRandevu.url, {
  method: "POST",
  body: new URLSearchParams({
    action: "acente_rnd_kaydet",
    nonce: acenteRandevu.nonce,
    gun: gunAnahtari,
    saat: secilenSaat,
    ad_soyad: ad.value,
    telefon: tel.value,
    konu: tur.value,
    gorusme_sekli: yer.value,
    web_sitesi: ""
  })
})
.then(r => r.json())
.then(j => {
  if (j.success) onayGoster();
  else if (j.data.kod === "dolu") { saatleriYenile(); uyar(j.data.mesaj); }
  else uyar(j.data.mesaj);
});
```

### Geri arama

```js
fetch(acenteGA.url, {
  method: "POST",
  body: new URLSearchParams({
    action: "acente_geri_arama",
    nonce: acenteGA.nonce,
    ad_soyad: name.value.trim(),
    telefon: phone.value,
    web_sitesi: ""
  })
})
.then(r => r.json())
.then(j => { j.success ? basariGoster() : hataGoster(j.data.mesaj); });
```

`web_sitesi` alanı bal küpüdür — ekranda görünmez, boş kalmalıdır. Doluysa istek sessizce reddedilir.

## Çakışma kontrolü

Randevu tablosunda `randevu_gun` + `randevu_saat` üzerinde `UNIQUE KEY` var. Aynı saate iki kişi aynı anda başvurursa ikincisi veritabanı seviyesinde reddedilir ve kullanıcıya "bu saat az önce alındı" mesajı döner.

Sadece PHP tarafında "bu saat dolu mu" diye bakmak yarış durumunu engellemez. İki istek aynı milisaniyede gelirse ikisi de boş görür ve ikisi de yazar.
