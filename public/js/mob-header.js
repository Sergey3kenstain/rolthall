/* ── Shared mobile header (mob-top + mob-nav) ─────────────────────────
   Inject via <script src="/js/mob-header.js"></script> before </body>.
   body[data-no-mob-header]   → skip entirely (admin panel)
   body[data-mob-nav="page"]  → inject mob-top only, keep page's own mob-nav
──────────────────────────────────────────────────────────────────────── */
(function () {
  if (document.body.hasAttribute('data-no-mob-header')) return;

  const skipNav = document.body.dataset.mobNav === 'page';
  const TOP_H   = 56;
  const NAV_H   = 72;

  /* ── CSS ── */
  if (!document.getElementById('rh-mob-css')) {
    const s = document.createElement('style');
    s.id = 'rh-mob-css';
    s.textContent = `
:root{--mob-top-h:${TOP_H}px;--mob-nav-h:${NAV_H}px;}
.rh-mob-top{
  display:none;position:fixed;top:0;left:0;right:0;z-index:400;
  height:var(--mob-top-h);padding:0 20px;
  background:rgba(30,28,36,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-bottom:1px solid rgba(255,255,255,.12);
  align-items:center;justify-content:space-between;
}
.rh-mob-top-logo{display:flex;align-items:center;text-decoration:none;}
.rh-mob-top-logo svg{height:14px;width:auto;max-width:210px;display:block;}
.rh-mob-top-btn{
  width:32px;height:32px;border:1px solid rgba(255,255,255,.15);border-radius:50%;
  display:flex;align-items:center;justify-content:center;cursor:pointer;
  text-decoration:none;color:rgba(217,217,217,.7);
  transition:border-color .2s,background .2s;
}
.rh-mob-top-btn:hover{border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.06);}
.rh-mob-top-btn svg{width:15px;height:15px;}
.rh-mob-nav{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:400;
  height:calc(var(--mob-nav-h) + env(safe-area-inset-bottom,0px));
  background:rgba(30,28,36,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-top:1px solid rgba(255,255,255,.12);
  padding:0 4px env(safe-area-inset-bottom,0px);
  align-items:stretch;justify-content:space-around;
}
.rh-mob-nav-item{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  flex:1;gap:6px;padding:8px 4px;
  color:rgba(217,217,217,.4);text-decoration:none;cursor:pointer;
  background:transparent;border:none;border-top:2px solid transparent;
  font-family:'Unbounded',sans-serif;transition:color .2s,border-color .2s;
  white-space:nowrap;
}
.rh-mob-nav-item:hover,.rh-mob-nav-item.active{color:#fff;}
.rh-mob-nav-item.active{border-top-color:#fff;}
.rh-mob-nav-item svg{width:20px;height:20px;flex-shrink:0;}
.rh-mob-nav-label{font-size:7.5px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;line-height:1;}
@media(max-width:1023px){
  .rh-mob-top{display:flex;}
  .rh-mob-nav{display:flex;}
}`;
    document.head.appendChild(s);
  }

  /* ── Logo SVG ── */
  const LOGO = `<svg width="1280" height="71" viewBox="0 0 1280 71" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="ROLTHALL"><g clip-path="url(#rh-lc)"><path d="M0 70.0138V0.986328H103.497C120.746 0.986328 131.095 14.7922 131.095 28.5971C131.095 42.402 120.746 56.2079 103.497 56.2079H31.1472V70.0138H0ZM31.1472 24.653V32.5422H95.6108C98.3707 32.5422 99.9473 31.0628 99.9473 28.5981C99.9473 26.1334 98.3697 24.654 95.6108 24.654H31.1472V24.653Z" fill="white"/><path d="M242.477 0C261.501 0 276.976 16.4682 276.976 35.5C276.976 54.5318 261.501 71 242.477 71H170.522C151.498 71 136.023 54.5318 136.023 35.5C136.023 16.4682 151.498 0 170.522 0H242.477ZM176.929 25.6392C171.508 25.6392 167.072 30.0765 167.072 35.5C167.072 40.9235 171.508 45.3608 176.929 45.3608H236.07C241.491 45.3608 245.927 40.9235 245.927 35.5C245.927 30.0765 241.491 25.6392 236.07 25.6392H176.929Z" fill="white"/><path d="M303.194 70.0138H268.103L323.991 0.986328H359.082L414.97 70.0138H379.88L341.536 22.6804L303.193 70.0138H303.194Z" fill="white"/><path d="M443.161 24.653H393.285V0.986328H524.381V24.653H474.308V70.0138H443.161V24.653Z" fill="white"/><path d="M663.361 70.0138H632.214V30.5697L563.414 70.0138H532.267V0.986328H563.414V40.4304L632.213 0.986328H663.36V70.0138H663.361Z" fill="white"/><path d="M765.87 0.986328L791.301 19.2295L816.731 0.986328H860.692L813.281 35.0069L862.073 70.0138H818.111L791.301 50.7844L764.49 70.0138H720.529L769.321 35.0069L721.909 0.986328H765.87Z" fill="white"/><path d="M958.669 0C977.692 0 993.168 16.4682 993.168 35.5C993.168 54.5318 977.692 71 958.669 71H886.715C867.691 71 852.216 54.5318 852.216 35.5C852.216 16.4682 867.69 0 886.715 0H958.669ZM893.121 25.6392C887.7 25.6392 883.264 30.0765 883.264 35.5C883.264 40.9235 887.7 45.3608 893.121 45.3608H952.262C957.683 45.3608 962.118 40.9235 962.118 35.5C962.118 30.0765 957.683 25.6392 952.262 25.6392H893.121Z" fill="white"/><path d="M1019.39 70.0138H984.296L1040.18 0.986328H1075.27L1131.16 70.0138H1096.07L1057.73 22.6804L1019.39 70.0138H1019.39Z" fill="white"/><path d="M1168.22 70.0138H1133.13L1189.02 0.986328H1224.11L1280 70.0138H1244.91L1206.57 22.6804L1168.22 70.0138H1168.22Z" fill="white"/></g><defs><clipPath id="rh-lc"><rect width="1280" height="71" fill="white"/></clipPath></defs></svg>`;

  /* ── Right button: back arrow (data-mob-back="/url") or account ── */
  const backHref = document.body.dataset.mobBack;
  let rightBtn;
  if (backHref) {
    rightBtn = `<a href="${backHref}" class="rh-mob-top-btn" aria-label="Назад"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></a>`;
  } else {
    const acctHref = localStorage.getItem('rh_token') ? '/admin'
      : sessionStorage.getItem('rh_profile_token') ? '/profile'
      : '/login';
    rightBtn = `<a href="${acctHref}" class="rh-mob-top-btn" aria-label="Кабинет"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></a>`;
  }

  /* ── mob-top ── */
  const top = document.createElement('div');
  top.className = 'rh-mob-top';
  top.innerHTML = `
    <a href="/" class="rh-mob-top-logo">${LOGO}</a>
    ${rightBtn}`;
  document.body.insertBefore(top, document.body.firstChild);

  /* ── mob-nav (universal, unless page provides its own) ── */
  if (!skipNav) {
    const p = location.pathname;
    const active = href => (href === '/' ? p === '/' : p.startsWith(href)) ? ' active' : '';

    const nav = document.createElement('nav');
    nav.className = 'rh-mob-nav';
    nav.setAttribute('aria-label', 'Мобильная навигация');
    nav.innerHTML = `
      <a href="/" class="rh-mob-nav-item${active('/')}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span class="rh-mob-nav-label">Главная</span>
      </a>
      <a href="/calendar" class="rh-mob-nav-item${active('/calendar')}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <span class="rh-mob-nav-label">Расписание</span>
      </a>
      <a href="/#pricing" class="rh-mob-nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <span class="rh-mob-nav-label">Тарифы</span>
      </a>
      <a href="/booking" class="rh-mob-nav-item${active('/booking')}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h8M8 18h5"/></svg>
        <span class="rh-mob-nav-label">Бронь</span>
      </a>
      <a href="${acctHref}" class="rh-mob-nav-item${p==='/profile'||p==='/login'?' active':''}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span class="rh-mob-nav-label">Кабинет</span>
      </a>`;
    document.body.appendChild(nav);
  }
})();
