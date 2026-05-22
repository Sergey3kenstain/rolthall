/* layout.js — shared header, mobile nav, footer for all app pages */

/* ── Frontend error collector ─────────────────────────────────────────────
   Перехватывает JS ошибки и упавшие fetch — отправляет на /api/debug/frontend
   Я проверяю /api/admin/debug/log после твоих тестов чтобы видеть ошибки.
*/
(function () {
  function send(data) {
    try {
      navigator.sendBeacon('/api/debug/frontend', JSON.stringify(
        Object.assign({ url: location.href, ts: new Date().toISOString() }, data)
      ));
    } catch (e) {}
  }

  // JS ошибки
  window.addEventListener('error', function (e) {
    send({ type: 'js', msg: e.message, src: (e.filename || '') + ':' + e.lineno });
  });

  // Unhandled promise rejections
  window.addEventListener('unhandledrejection', function (e) {
    send({ type: 'promise', msg: String(e.reason && e.reason.message || e.reason) });
  });

  // Перехват fetch — логируем не-2xx ответы
  var _fetch = window.fetch;
  window.fetch = function (url, opts) {
    return _fetch.apply(this, arguments).then(function (res) {
      if (!res.ok) send({ type: 'fetch', url: String(url).split('?')[0], status: res.status });
      return res;
    }).catch(function (err) {
      send({ type: 'fetch_err', url: String(url).split('?')[0], msg: err.message });
      throw err;
    });
  };
})();

(function () {
  const PATH = window.location.pathname;
  const active = (href) => !href.startsWith('/#') && PATH === href ? ' active' : '';

  const LOGO = (id) => `<svg width="1280" height="71" viewBox="0 0 1280 71" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#${id})"><path d="M0 70.0138V0.986328H103.497C120.746 0.986328 131.095 14.7922 131.095 28.5971C131.095 42.402 120.746 56.2079 103.497 56.2079H31.1472V70.0138H0ZM31.1472 24.653V32.5422H95.6108C98.3707 32.5422 99.9473 31.0628 99.9473 28.5981C99.9473 26.1334 98.3697 24.654 95.6108 24.654H31.1472V24.653Z" fill="white"/><path d="M242.477 0C261.501 0 276.976 16.4682 276.976 35.5C276.976 54.5318 261.501 71 242.477 71H170.522C151.498 71 136.023 54.5318 136.023 35.5C136.023 16.4682 151.498 0 170.522 0H242.477ZM176.929 25.6392C171.508 25.6392 167.072 30.0765 167.072 35.5C167.072 40.9235 171.508 45.3608 176.929 45.3608H236.07C241.491 45.3608 245.927 40.9235 245.927 35.5C245.927 30.0765 241.491 25.6392 236.07 25.6392H176.929Z" fill="white"/><path d="M303.194 70.0138H268.103L323.991 0.986328H359.082L414.97 70.0138H379.88L341.536 22.6804L303.193 70.0138H303.194Z" fill="white"/><path d="M443.161 24.653H393.285V0.986328H524.381V24.653H474.308V70.0138H443.161V24.653Z" fill="white"/><path d="M663.361 70.0138H632.214V30.5697L563.414 70.0138H532.267V0.986328H563.414V40.4304L632.213 0.986328H663.36V70.0138H663.361Z" fill="white"/><path d="M765.87 0.986328L791.301 19.2295L816.731 0.986328H860.692L813.281 35.0069L862.073 70.0138H818.111L791.301 50.7844L764.49 70.0138H720.529L769.321 35.0069L721.909 0.986328H765.87Z" fill="white"/><path d="M958.669 0C977.692 0 993.168 16.4682 993.168 35.5C993.168 54.5318 977.692 71 958.669 71H886.715C867.691 71 852.216 54.5318 852.216 35.5C852.216 16.4682 867.69 0 886.715 0H958.669ZM893.121 25.6392C887.7 25.6392 883.264 30.0765 883.264 35.5C883.264 40.9235 887.7 45.3608 893.121 45.3608H952.262C957.683 45.3608 962.118 40.9235 962.118 35.5C962.118 30.0765 957.683 25.6392 952.262 25.6392H893.121Z" fill="white"/><path d="M1019.39 70.0138H984.296L1040.18 0.986328H1075.27L1131.16 70.0138H1096.07L1057.73 22.6804L1019.39 70.0138H1019.39Z" fill="white"/><path d="M1168.22 70.0138H1133.13L1189.02 0.986328H1224.11L1280 70.0138H1244.91L1206.57 22.6804L1168.22 70.0138H1168.22Z" fill="white"/></g><defs><clipPath id="${id}"><rect width="1280" height="71" fill="white"/></clipPath></defs></svg>`;

  const ICON_USER  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`;
  const ICON_HOME  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`;
  const ICON_CAL   = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>`;
  const ICON_PRICE = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`;

  // ── Replace mobile top bar ─────────────────────────────────────────────
  const mobTop = document.querySelector('.mob-top');
  if (mobTop) {
    mobTop.innerHTML = `
      <a href="/" style="display:flex;align-items:center;text-decoration:none;">
        ${LOGO('lx_mob')}
      </a>
      <a href="/profile" class="d-nav-account">${ICON_USER}</a>`;
    // Fix logo size
    const svg = mobTop.querySelector('svg');
    if (svg) { svg.style.height = '10px'; svg.style.width = 'auto'; }
  }

  // ── Replace desktop nav links active state ─────────────────────────────
  const dNavLinks = document.querySelectorAll('.d-nav-links a:not(.d-nav-account)');
  dNavLinks.forEach(a => {
    a.classList.remove('active');
    if (active(a.getAttribute('href'))) a.classList.add('active');
  });

  // ── Replace mobile bottom nav ──────────────────────────────────────────
  const mobNav = document.querySelector('nav.mob-nav');
  if (mobNav) {
    mobNav.innerHTML = `
      <a href="/" class="mob-nav-item${active('/')}">
        ${ICON_HOME}<span class="mob-nav-label">Главная</span>
      </a>
      <a href="/calendar" class="mob-nav-item${active('/calendar')}">
        ${ICON_CAL}<span class="mob-nav-label">Расписание</span>
      </a>
      <a href="/#pricing" class="mob-nav-item">
        ${ICON_PRICE}<span class="mob-nav-label">Тарифы</span>
      </a>
      <a href="/profile" class="mob-nav-item${active('/profile')}">
        ${ICON_USER}<span class="mob-nav-label">Кабинет</span>
      </a>`;
  }

  // ── Inject footer ──────────────────────────────────────────────────────
  const footerEl = document.getElementById('app-footer');
  if (footerEl) {
    footerEl.innerHTML = `<footer>
  <div class="footer-top">
    <div class="footer-brand">
      <div class="footer-logo">${LOGO('lx_ft')}</div>
      <div class="footer-tagline">Профессиональное танцевальное пространство · Краснодар</div>
    </div>
    <div class="footer-nav-col">
      <a href="/">Главная</a>
      <a href="/#about">Пространство</a>
      <a href="/#pricing">Цены</a>
      <a href="/#rules">Правила</a>
      <a href="/#contacts">Контакты</a>
    </div>
    <div class="footer-legal-col">
      <a href="/cttp_opd" target="_blank">Согласие на обработку персональных данных</a>
      <a href="/user_agreement" target="_blank">Пользовательское соглашение</a>
      <a href="/privacy_policy" target="_blank">Политика конфиденциальности</a>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-copy">© 2025 ROLTHALL. Все права защищены.</div>
    <div class="footer-dev">dev by <a href="https://t.me/Sergey_3kenstain" target="_blank">Sergey 3kenstain</a></div>
  </div>
</footer>`;
  }
})();
