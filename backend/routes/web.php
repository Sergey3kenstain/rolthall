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
    nav{padding:20px 48px;display:flex;align-items:center;border-bottom:1px solid var(--border)}
    .nav-logo svg{height:16px;width:auto;display:block}
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
    <svg width="1280" height="71" viewBox="0 0 1280 71" fill="none" xmlns="http://www.w3.org/2000/svg" style="height:16px;width:auto">
      <g clip-path="url(#clip0_2466_470)">
        <path d="M0 70.0138V0.986328H103.497C120.746 0.986328 131.095 14.7922 131.095 28.5971C131.095 42.402 120.746 56.2079 103.497 56.2079H31.1472V70.0138H0ZM31.1472 24.653V32.5422H95.6108C98.3707 32.5422 99.9473 31.0628 99.9473 28.5981C99.9473 26.1334 98.3697 24.654 95.6108 24.654H31.1472V24.653Z" fill="white"/>
        <path d="M242.477 0C261.501 0 276.976 16.4682 276.976 35.5C276.976 54.5318 261.501 71 242.477 71H170.522C151.498 71 136.023 54.5318 136.023 35.5C136.023 16.4682 151.498 0 170.522 0H242.477ZM176.929 25.6392C171.508 25.6392 167.072 30.0765 167.072 35.5C167.072 40.9235 171.508 45.3608 176.929 45.3608H236.07C241.491 45.3608 245.927 40.9235 245.927 35.5C245.927 30.0765 241.491 25.6392 236.07 25.6392H176.929Z" fill="white"/>
        <path d="M303.194 70.0138H268.103L323.991 0.986328H359.082L414.97 70.0138H379.88L341.536 22.6804L303.193 70.0138H303.194Z" fill="white"/>
        <path d="M443.161 24.653H393.285V0.986328H524.381V24.653H474.308V70.0138H443.161V24.653Z" fill="white"/>
        <path d="M663.361 70.0138H632.214V30.5697L563.414 70.0138H532.267V0.986328H563.414V40.4304L632.213 0.986328H663.36V70.0138H663.361Z" fill="white"/>
        <path d="M765.87 0.986328L791.301 19.2295L816.731 0.986328H860.692L813.281 35.0069L862.073 70.0138H818.111L791.301 50.7844L764.49 70.0138H720.529L769.321 35.0069L721.909 0.986328H765.87Z" fill="white"/>
        <path d="M958.669 0C977.692 0 993.168 16.4682 993.168 35.5C993.168 54.5318 977.692 71 958.669 71H886.715C867.691 71 852.216 54.5318 852.216 35.5C852.216 16.4682 867.69 0 886.715 0H958.669ZM893.121 25.6392C887.7 25.6392 883.264 30.0765 883.264 35.5C883.264 40.9235 887.7 45.3608 893.121 45.3608H952.262C957.683 45.3608 962.118 40.9235 962.118 35.5C962.118 30.0765 957.683 25.6392 952.262 25.6392H893.121Z" fill="white"/>
        <path d="M1019.39 70.0138H984.296L1040.18 0.986328H1075.27L1131.16 70.0138H1096.07L1057.73 22.6804L1019.39 70.0138H1019.39Z" fill="white"/>
        <path d="M1168.22 70.0138H1133.13L1189.02 0.986328H1224.11L1280 70.0138H1244.91L1206.57 22.6804L1168.22 70.0138H1168.22Z" fill="white"/>
      </g>
      <defs><clipPath id="clip0_2466_470"><rect width="1280" height="71" fill="white"/></clipPath></defs>
    </svg>
  </a>
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
