const { chromium } = require('playwright');
const path = require('path');
const URL = 'file://' + path.resolve(__dirname, '..', 'index.html');

const VIEWS = [
  { name: 'masaustu', w: 1440, h: 900, scale: 1 },
  { name: 'laptop', w: 1280, h: 820, scale: 1 },
  { name: 'tablet', w: 834, h: 1112, scale: 2 },
  { name: 'mobil', w: 390, h: 844, scale: 3 },
  { name: 'mobil-kucuk', w: 320, h: 700, scale: 2 },
];

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const report = [];

  for (const v of VIEWS) {
    const ctx = await browser.newContext({
      viewport: { width: v.w, height: v.h },
      deviceScaleFactor: v.scale,
      isMobile: v.w < 900, hasTouch: v.w < 900, locale: 'tr-TR',
    });
    const page = await ctx.newPage();
    page.on('pageerror', e => errors.push('[' + v.name + '] JS: ' + e.message));
    page.on('console', m => {
      if (m.type() === 'error' && !/favicon|ERR_|net::/.test(m.text())) errors.push('[' + v.name + '] console: ' + m.text());
    });

    await page.goto(URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1600);

    const overflow = await page.evaluate(() => {
      const de = document.documentElement;
      const bad = [];
      document.querySelectorAll('body *').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.width <= 0) return;
        if (el.classList.contains('skip')) return;
        if (el.closest('.partners')) return;
        if (el.closest('.bcard__media')) return;
        if (el.closest('.media')) return;
        const cs = getComputedStyle(el);
        if (cs.position === 'fixed') return;
        if (r.right > de.clientWidth + 2 || r.left < -2) {
          bad.push(el.tagName.toLowerCase() + '.' + (el.className.toString().split(' ')[0] || '') + ' -> ' + Math.round(r.right));
        }
      });
      return { scrollW: de.scrollWidth, clientW: de.clientWidth, bad: bad.slice(0, 8) };
    });
    report.push(v.name + ' (' + v.w + 'px): scrollWidth=' + overflow.scrollW + ' clientWidth=' + overflow.clientW +
      (overflow.bad.length ? '\n   TASMA: ' + overflow.bad.join(' | ') : '  tasma yok'));

    if (v.w < 900) {
      const small = await page.evaluate(() => {
        const out = [];
        document.querySelectorAll('a, button, input, select').forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.width === 0 || r.height === 0) return;
          if (el.closest('.footer') || el.closest('.faq') || el.closest('.consent')) return;
          if (el.classList.contains('cal__day') || el.classList.contains('slot')) return;
          if (r.height < 40 || r.width < 40) out.push(el.tagName.toLowerCase() + '.' + (el.className.toString().split(' ')[0] || '') + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
        });
        return out.slice(0, 8);
      });
      if (small.length) report.push('   kucuk dokunma hedefi: ' + small.join(', '));
    }
    await ctx.close();
  }

  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 }, locale: 'tr-TR' });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push('[islev] JS: ' + e.message));
  await page.goto(URL, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.click('[data-cookie="all"]').catch(() => {});

  report.push('');
  report.push('===== TEKLIF AKISI =====');
  await page.click('button[data-product="Kasko"]');
  await page.waitForTimeout(400);
  const pre = await page.inputValue('#m-type');
  report.push('  modal urun on secimi: "' + pre + '" ' + (pre === 'Kasko' ? 'OK' : 'HATA'));

  await page.click('#modalForm button[type="submit"]');
  await page.waitForTimeout(250);
  const errCount = await page.locator('#modalForm .has-error').count();
  report.push('  bos form dogrulama: ' + errCount + ' alan ' + (errCount >= 3 ? 'OK' : 'HATA'));

  await page.fill('#m-name', '!!!@@@123');
  const suz = await page.inputValue('#m-name');
  report.push('  ad suzgeci ("!!!@@@123"): "' + suz + '" ' + (suz === '' ? 'OK' : 'HATA'));

  await page.fill('#m-name', 'Ahmet Yılmaz');
  await page.fill('#m-tel', '+90 555 000 00 00');
  await page.locator('#m-tel').blur();
  await page.waitForTimeout(120);
  report.push('  tel bicimleme: "' + (await page.inputValue('#m-tel')) + '"');
  await page.check('#modalForm input[name="kvkk"]');
  await page.evaluate(() => { window.__wa = null; window.open = (u) => { window.__wa = u; return null; }; });
  await page.click('#modalForm button[type="submit"]');
  await page.waitForTimeout(300);
  const wa = await page.evaluate(() => window.__wa);
  report.push('  wa.me baglantisi: ' + (wa && wa.includes('905550000000') && decodeURIComponent(wa).includes('Kasko') ? 'OK' : 'HATA'));

  report.push('');
  report.push('===== RANDEVU TAKVIMI =====');
  await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; document.getElementById('randevu').scrollIntoView(); });
  await page.waitForTimeout(400);

  const ay = await page.textContent('#calMonth');
  report.push('  gosterilen ay: ' + ay);
  const prevDisabled = await page.locator('#calPrev').isDisabled();
  report.push('  gecmis aya gidilemiyor: ' + (prevDisabled ? 'OK' : 'HATA'));

  const gecmisKapali = await page.evaluate(() => {
    const bugun = new Date().getDate();
    const gunler = [...document.querySelectorAll('.cal__day:not(.is-empty)')];
    const oncekiler = gunler.slice(0, Math.max(0, bugun - 1));
    return oncekiler.length === 0 || oncekiler.every(b => b.disabled);
  });
  report.push('  gecmis gunler kapali: ' + (gecmisKapali ? 'OK' : 'HATA'));

  const pazarKapali = await page.evaluate(() => {
    const d = [...document.querySelectorAll('.cal__day:not(.is-empty)')];
    const tumu = [...document.querySelectorAll('.cal__day')];
    let hepsi = true;
    tumu.forEach((el, i) => {
      if (i % 7 === 6 && !el.classList.contains('is-empty') && !el.disabled) hepsi = false;
    });
    return hepsi;
  });
  report.push('  pazar gunleri kapali: ' + (pazarKapali ? 'OK' : 'HATA'));

  const secildi = await page.evaluate(() => {
    const s = document.querySelector('.cal__day.is-sel');
    return s ? s.textContent : null;
  });
  report.push('  acilista otomatik secili gun: ' + (secildi ? 'OK (' + secildi + ')' : 'HATA'));
  await page.waitForTimeout(350);
  const slotSayi = await page.locator('.slot').count();
  const doluSayi = await page.locator('.slot:disabled').count();
  report.push('  saat sayisi: ' + slotSayi + ' (dolu: ' + doluSayi + ') ' + (slotSayi > 0 ? 'OK' : 'HATA'));

  const formGizli = await page.locator('#apptForm').isHidden();
  report.push('  saat secilmeden form gizli: ' + (formGizli ? 'OK' : 'HATA'));

  await page.evaluate(() => { [...document.querySelectorAll('.slot')].find(s => !s.disabled).click(); });
  await page.waitForTimeout(300);
  report.push('  saat secilince form acildi: ' + ((await page.locator('#apptForm').isVisible()) ? 'OK' : 'HATA'));
  const ozet = (await page.textContent('#apptSum')).replace(/\s+/g, ' ').trim();
  report.push('  ozet: ' + ozet);

  await page.click('#apptForm button[type="submit"]');
  await page.waitForTimeout(250);
  const apptErr = await page.locator('#apptForm .has-error').count();
  report.push('  bos randevu formu dogrulama: ' + apptErr + ' alan ' + (apptErr >= 3 ? 'OK' : 'HATA'));

  await page.fill('#a-name', 'Ayşe Demir');
  await page.fill('#a-tel', '0552 111 22 33');
  await page.selectOption('#a-type', 'Kasko');
  await page.check('#apptForm input[name="kvkk"]');
  await page.click('#apptForm button[type="submit"]');
  await page.waitForTimeout(400);
  const done = await page.locator('#apptDone').isVisible();
  report.push('  randevu olusturuldu: ' + (done ? 'OK' : 'HATA'));
  if (done) report.push('  onay metni: ' + (await page.textContent('#apptDoneTxt')).replace(/\s+/g, ' ').trim());
  const kayit = await page.evaluate(() => localStorage.getItem('osa_appts'));
  report.push('  kayit: ' + kayit);

  await page.click('#apptReset');
  await page.waitForTimeout(300);
  const tekrarDolu = await page.evaluate(() => {
    const kayit = JSON.parse(localStorage.getItem('osa_appts') || '[]');
    if (!kayit.length) return 'kayit yok';
    return kayit[kayit.length - 1].s;
  });
  await page.waitForTimeout(300);
  const oSaatKapali = await page.evaluate((saat) => {
    const s = [...document.querySelectorAll('.slot')].find(x => x.textContent === saat);
    return s ? s.disabled : 'bulunamadi';
  }, tekrarDolu);
  report.push('  alinan saat tekrar secilemez: ' + (oSaatKapali === true ? 'OK' : 'HATA (' + oSaatKapali + ')'));

  const weight = await page.evaluate(() => ({ dom: document.querySelectorAll('*').length }));
  report.push('');
  report.push('PERFORMANS — DOM dugumu: ' + weight.dom);

  await ctx.close();
  await browser.close();

  console.log('===== RAPOR =====');
  console.log(report.join('\n'));
  console.log('');
  console.log('===== JS HATALARI =====');
  console.log(errors.length ? errors.join('\n') : 'yok');
})();
