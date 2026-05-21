<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/booking/success', function () {
    return response(<<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Оплата прошла — RoltHall</title>
  <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #3D3B3F; color: #fff; font-family: 'Unbounded', sans-serif;
           min-height: 100vh; display: flex; align-items: center; justify-content: center;
           text-align: center; padding: 24px; }
    .icon { font-size: 4rem; margin-bottom: 24px; }
    h1 { font-size: 1.5rem; font-weight: 800; margin-bottom: 12px; }
    p  { font-size: .875rem; color: rgba(255,255,255,.6); line-height: 1.7; margin-bottom: 32px; }
    a  { display: inline-block; background: linear-gradient(135deg,#376FFF,#5B8FFF);
         color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 12px;
         font-size: .875rem; font-weight: 700; }
  </style>
</head>
<body>
  <div>
    <div class="icon">✅</div>
    <h1>Оплата прошла!</h1>
    <p>Мы получили вашу предоплату.<br>Уведомление отправлено в Telegram.<br>До встречи в RoltHall!</p>
    <a href="/">На главную</a>
  </div>
</body>
</html>
HTML);
});

Route::get('/booking/fail', function () {
    return response(<<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ошибка оплаты — RoltHall</title>
  <link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@400;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #3D3B3F; color: #fff; font-family: 'Unbounded', sans-serif;
           min-height: 100vh; display: flex; align-items: center; justify-content: center;
           text-align: center; padding: 24px; }
    .icon { font-size: 4rem; margin-bottom: 24px; }
    h1 { font-size: 1.5rem; font-weight: 800; margin-bottom: 12px; }
    p  { font-size: .875rem; color: rgba(255,255,255,.6); line-height: 1.7; margin-bottom: 32px; }
    .btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    a  { display: inline-block; background: linear-gradient(135deg,#376FFF,#5B8FFF);
         color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 12px;
         font-size: .875rem; font-weight: 700; }
    a.ghost { background: rgba(255,255,255,.08); }
  </style>
</head>
<body>
  <div>
    <div class="icon">❌</div>
    <h1>Оплата не прошла</h1>
    <p>Что-то пошло не так при оплате.<br>Попробуйте ещё раз или свяжитесь с нами.</p>
    <div class="btns">
      <a href="/">Попробовать снова</a>
      <a href="/" class="ghost">На главную</a>
    </div>
  </div>
</body>
</html>
HTML);
});
