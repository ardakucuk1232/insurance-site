const { chromium } = require('playwright');
const path = require('path');
const URL = 'file://' + path.resolve(__dirname, '..', 'index.html');

(async () => {
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: 1440, height: 950 }, locale: 'tr-TR' });
  const p = await c.newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(URL, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  await p.click('[data-cookie="all"]').catch(() => {});

  const sonuc = [];
  const yaz = async (sel, metin) => {
    await p.fill(sel, '');
    await p.type(sel, metin, { delay: 1 });
    return p.inputValue(sel);
  };

  sonuc.push(['AD sembol yigini', await yaz('#h-name', '!!!!!!@@@....,,,:::::"!^+%/(256815150')]);
  sonuc.push(['AD rakam karisik', await yaz('#h-name', 'Ahmet123 Yilmaz456')]);
  sonuc.push(['AD turkce gecerli', await yaz('#h-name', 'Sukru Gokce Inanc Ozturk')]);
  sonuc.push(['AD kesme ve tire', await yaz('#h-name', "D'Artagnan Ali-Riza")]);

  await p.fill('#h-name', '');
  await p.evaluate(() => {
    const el = document.getElementById('h-name');
    el.value = '<' + 'script>alert(1)</' + 'script> Mehmet 42';
    el.dispatchEvent(new Event('input', { bubbles: true }));
  });
  sonuc.push(['AD yapistirma script', await p.inputValue('#h-name')]);

  sonuc.push(['TEL harf ve sembol', await yaz('#h-tel', 'ewq!!!!!!@@@....,,,:::::"!^+%/(256815150')]);
  sonuc.push(['TEL harfli', await yaz('#h-tel', 'abc555def123ghi4567')]);

  await p.fill('#h-tel', '+90 555 000 00 00');
  await p.locator('#h-tel').blur();
  await p.waitForTimeout(150);
  sonuc.push(['TEL +90 bicimlendi', await p.inputValue('#h-tel')]);

  await p.fill('#h-tel', '05550000000');
  await p.locator('#h-tel').blur();
  await p.waitForTimeout(150);
  sonuc.push(['TEL duz bicimlendi', await p.inputValue('#h-tel')]);

  await p.fill('#h-msg', '');
  await p.evaluate(() => {
    const el = document.getElementById('h-msg');
    el.value = 'Merhaba' + String.fromCharCode(7) + String.fromCharCode(27) + ' kontrol';
    el.dispatchEvent(new Event('input', { bubbles: true }));
  });
  sonuc.push(['MSJ kontrol karakteri', JSON.stringify(await p.inputValue('#h-msg'))]);

  console.log('===== SUZGEC SONUCLARI =====');
  sonuc.forEach(([n, v]) => console.log('  ' + n.padEnd(24) + ' -> "' + v + '"'));

  console.log('');
  console.log('===== DOGRULAMA =====');
  await p.click('button[data-product="Kasko"]');
  await p.waitForTimeout(350);
  await p.fill('#m-name', '...');
  await p.fill('#m-tel', '0555 000 00 00');
  await p.check('#modalForm input[name="kvkk"]');
  await p.evaluate(() => { window.__wa = null; window.open = (u) => { window.__wa = u; return null; }; });
  await p.click('#modalForm button[type="submit"]');
  await p.waitForTimeout(250);

  const adHata = await p.locator('#m-name').evaluate(el => el.closest('.field').classList.contains('has-error'));
  console.log('  gecersiz ad reddedildi :', adHata ? 'OK' : 'HATA');
  console.log('  WhatsApp acilmadi      :', (await p.evaluate(() => window.__wa)) === null ? 'OK' : 'HATA');

  await p.fill('#m-name', 'Ali Veli');
  await p.click('#modalForm button[type="submit"]');
  await p.waitForTimeout(300);
  const u = await p.evaluate(() => window.__wa);
  console.log('  gecerli ad ile gonderim:', u ? 'OK' : 'HATA');
  if (u) {
    const satirlar = decodeURIComponent(u).split('text=')[1].split(String.fromCharCode(10)).filter(Boolean);
    console.log('  mesajdaki ad satiri    :', satirlar[1]);
  }

  console.log('');
  console.log('JS hatalari:', errs.length ? errs : 'yok');
  await b.close();
})();
