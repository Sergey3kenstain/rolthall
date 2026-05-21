<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

$statusPageLayout = fn(string $icon, string $title, string $text, string $extra = '') => response(<<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title} — ROLTHALL</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@200;400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--charcoal:#2E2B35;--charcoal-deep:#1e1c24;--charcoal-mid:#26232e;--light:#D9D9D9;--accent:#fff;--border:rgba(255,255,255,0.12);--font:'Unbounded',sans-serif}
    body{font-family:var(--font);background:var(--charcoal-deep);color:var(--light);min-height:100vh;display:flex;flex-direction:column}
    /* NAV */
    nav{padding:20px 48px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
    .nav-logo svg{height:16px;width:auto;display:block}
    .nav-back{font-size:9px;font-weight:600;letter-spacing:.15em;text-transform:uppercase;color:rgba(217,217,217,.5);text-decoration:none;transition:color .2s}
    .nav-back:hover{color:var(--accent)}
    /* CONTENT */
    .page{flex:1;display:flex;align-items:center;justify-content:center;padding:80px 48px;text-align:center}
    .page-inner{max-width:480px}
    .status-icon{font-size:64px;line-height:1;margin-bottom:40px;display:block}
    .status-label{font-size:8px;font-weight:600;letter-spacing:.3em;text-transform:uppercase;color:rgba(217,217,217,.35);margin-bottom:20px}
    h1{font-size:clamp(28px,5vw,48px);font-weight:800;color:var(--accent);line-height:1.1;letter-spacing:-.02em;margin-bottom:20px}
    p{font-size:12px;font-weight:300;line-height:1.9;color:rgba(217,217,217,.55);margin-bottom:40px}
    .btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
    .btn{font-family:var(--font);font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;text-decoration:none;display:inline-block;padding:18px 40px;transition:opacity .2s,transform .2s;cursor:pointer;border:none}
    .btn:hover{opacity:.85;transform:translateY(-2px)}
    .btn-primary{color:var(--charcoal);background:var(--accent)}
    .btn-secondary{color:var(--light);background:transparent;border:1px solid rgba(217,217,217,.3)}
    /* FOOTER */
    footer{padding:24px 48px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
    .footer-copy{font-size:9px;font-weight:300;letter-spacing:.1em;color:rgba(217,217,217,.2)}
    @media(max-width:600px){nav,footer{padding:16px 24px}.page{padding:60px 24px}.btns{flex-direction:column}}
  </style>
</head>
<body>
<nav>
  <a href="/" class="nav-logo">
    <svg width="1280" height="71" viewBox="0 0 1280 71" fill="none" xmlns="http://www.w3.org/2000/svg">
      <g clip-path="url(#c)">
        <path d="M0 70V1h103.5C120.7 1 131 14.8 131 28.6c0 13.8-10.3 27.6-27.5 27.6H31.1V70H0zm31.1-45.3v7.9h64.5c2.76 0 4.34-1.48 4.34-3.94 0-2.47-1.57-3.95-4.34-3.95H31.1v-.01z" fill="#fff"/>
        <path d="M242.5 0c19 0 34.5 16.5 34.5 35.5S261.5 71 242.5 71h-72c-19 0-34.5-16.5-34.5-35.5S151.5 0 170.5 0h72zm-65.6 25.6c-5.42 0-9.86 4.44-9.86 9.9 0 5.42 4.44 9.86 9.86 9.86h59.14c5.42 0 9.86-4.44 9.86-9.86 0-5.46-4.44-9.9-9.86-9.9h-59.14z" fill="#fff"/>
        <path d="M303.2 70L268.1 1H359l55.9 69H379.9L341.5 22.7 303.2 70h.01z" fill="#fff"/>
        <path d="M443.2 24.7H393.3V1h131.1v23.7h-50.1V70h-31.1V24.7z" fill="#fff"/>
        <path d="M663.4 70H632.2V30.6L563.4 70h-31.1V1h31.1v39.4L632.2 1h31.1V70h.1z" fill="#fff"/>
        <path d="M765.9 1l25.4 18.2L816.7 1h44l-47.4 34 48.8 35h-44l-26.8-19.2L764.5 70h-44l48.8-35L721.9 1h44z" fill="#fff"/>
        <path d="M958.7 0c19 0 34.5 16.5 34.5 35.5S977.7 71 958.7 71h-72c-19 0-34.5-16.5-34.5-35.5S867.7 0 886.7 0h72zm-65.6 25.6c-5.42 0-9.86 4.44-9.86 9.9 0 5.42 4.44 9.86 9.86 9.86h59.14c5.42 0 9.86-4.44 9.86-9.86 0-5.46-4.44-9.9-9.86-9.9h-59.14z" fill="#fff"/>
        <path d="M1019.4 70l-35.1-69h91.1L1131.2 70h-35.1l-38.4-47.3L1019.4 70zM1168.2 70l-35.1-69h91.1L1280 70h-35.1l-38.3-47.3L1168.2 70z" fill="#fff"/>
      </g>
      <defs><clipPath id="c"><rect width="1280" height="71" fill="#fff"/></clipPath></defs>
    </svg>
  </a>
  <a href="/" class="nav-back">← На главную</a>
</nav>

<div class="page">
  <div class="page-inner">
    <span class="status-icon">{$icon}</span>
    <div class="status-label">Статус оплаты</div>
    <h1>{$title}</h1>
    <p>{$text}</p>
    <div class="btns">{$extra}</div>
  </div>
</div>

<footer>
  <div class="footer-copy">© 2025 ROLTHALL. Все права защищены.</div>
  <div class="footer-copy">г. Краснодар, ул. Коммунаров, 278/1</div>
</footer>
</body>
</html>
HTML);

Route::get('/booking/success', function () use ($statusPageLayout) {
    return $statusPageLayout(
        '✓',
        'Оплата прошла',
        'Мы получили вашу предоплату.<br>Уведомление отправлено вам и администратору в Telegram.<br>Ждём вас в ROLTHALL!',
        '<a href="/" class="btn btn-primary">На главную</a>'
    );
});

Route::get('/booking/fail', function () use ($statusPageLayout) {
    return $statusPageLayout(
        '×',
        'Оплата не прошла',
        'Что-то пошло не так при оплате.<br>Попробуйте ещё раз или свяжитесь с нами напрямую.',
        '<a href="/" class="btn btn-primary">Попробовать снова</a>
         <a href="https://t.me/iam5491" class="btn btn-secondary" target="_blank">Написать в Telegram</a>'
    );
});
