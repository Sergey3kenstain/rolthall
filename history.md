# История разработки — RoltHall

## Инфраструктура и деплой

### SSH подключение к Beget
```
Host sergey7z
    HostName localhost
    Port 222
    User sergey7z_z
    IdentityFile ~/.ssh/id_rsa
    ProxyJump sergey7z_z@sergey7z.beget.tech
    StrictHostKeyChecking no
    UserKnownHostsFile NUL
```
Подключение: `ssh sergey7z`
Домашняя папка на сервере: `/home/s/sergey7z/`

### Структура на Beget
```
/home/s/sergey7z/
├── hall.roltworld.com/
│   ├── backend/        ← Laravel 13
│   ├── public/         ← лендинг (копируется в public_html при деплое)
│   ├── mvp/            ← HTML-прототипы (не деплоятся)
│   └── public_html/    ← веб-корень домена
├── repos/
│   └── rolthall.git/   ← bare git repo с хуком автодеплоя
└── composer            ← Composer (~/.composer)
```

### Git автодеплой
Bare репо: `~/repos/rolthall.git`
Хук: `~/repos/rolthall.git/hooks/post-receive`

```bash
#!/bin/bash
export HOME=/home/s/sergey7z
GIT_WORK_TREE=/home/s/sergey7z/hall.roltworld.com git checkout -f main
cd /home/s/sergey7z/hall.roltworld.com/backend
/usr/local/bin/php8.3 /home/s/sergey7z/composer install --no-dev --optimize-autoloader --quiet
/usr/local/bin/php8.3 artisan config:cache
/usr/local/bin/php8.3 artisan route:cache
cp -r /home/s/sergey7z/hall.roltworld.com/public/. /home/s/sergey7z/hall.roltworld.com/public_html/
echo "Deploy OK: $(date)"
```

Деплой с Mac: `git push beget main`
Remote: `git remote add beget sergey7z:/home/s/sergey7z/repos/rolthall.git`
Алиас для быстрого пуша: `git rh "комментарий"` (пушит и на Beget и на GitHub)

### PHP на сервере
PHP доступен только как: `/usr/local/bin/php8.3`
Composer: `~/composer` (установлен в домашнюю папку)
Запуск artisan: `/usr/local/bin/php8.3 artisan ...`

### .htaccess (public_html)
```apache
<IfModule mod_rewrite.c>
    Options -Indexes
    RewriteEngine On
    RewriteRule ^$ index.html [L]
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]
    RewriteRule ^ index.php [L]
</IfModule>
AddHandler application/x-httpd-php83 .php
```

### public_html/index.php (точка входа Laravel)
```php
<?php
define('LARAVEL_START', microtime(true));
require __DIR__.'/../backend/vendor/autoload.php';
$app = require_once __DIR__.'/../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture())->send();
$kernel->terminate($request, $response);
```

---

## Стек и версии

| Компонент | Версия |
|---|---|
| Laravel | 13.11.x |
| PHP (сервер) | 8.3 (`/usr/local/bin/php8.3`) |
| PHP (Mac) | 8.5.5 |
| MySQL | 5.7 (Beget Great) |
| Composer | 2.9.8 |

### Ключевые пакеты
- `spatie/laravel-permission` 7.4 — роли (developer, landlord, admin, client)
- `irazasyed/telegram-bot-sdk` 3.16 — Telegram Bot API
- `laravel/sanctum` 4.3 — API-аутентификация

---

## MySQL 5.7 — важные фиксы

`config/database.php` — секция mysql:
```php
'strict' => false,
'engine' => 'InnoDB ROW_FORMAT=DYNAMIC',
```

`AppServiceProvider::boot()`:
```php
Schema::defaultStringLength(191);
```

---

## Переменные окружения (.env на сервере)

`.env` НЕ в git — редактировать только на сервере вручную:
```bash
ssh sergey7z
nano ~/hall.roltworld.com/backend/.env
cd ~/hall.roltworld.com/backend && /usr/local/bin/php8.3 artisan config:cache
```

Ключевые переменные:
```env
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=sergey7z_rhall
DB_USERNAME=sergey7z_rhall
QUEUE_CONNECTION=database
TELEGRAM_BOT_TOKEN=...
TELEGRAM_ADMIN_CHAT_ID=...
TELEGRAM_ADMIN_THREAD_ID=...
TBANK_TERMINAL_KEY=...
TBANK_SECRET_KEY=...
TBANK_TEST_MODE=true
```

---

## T-Bank интеграция

- API URL (работает): `https://securepay.tinkoff.ru/v2/` (и для теста DEMO-терминала)
- Тестовый терминал: ключ заканчивается на `DEMO`
- Тестовая карта: `4300 0000 0000 0777`
- Webhook URL: `https://hall.roltworld.com/api/payment/webhook`
- Token = SHA256(sorted values + Password)

---

## Telegram Bot

- Бот: @RoltHallBot
- Webhook зарегистрирован: `https://hall.roltworld.com/api/telegram/webhook`
- Уведомления идут в группу с темой (thread_id в .env)
- Команды бота: `/start`, `/mychat` (возвращает chat_id и thread_id)

---

## Что сделано (хронология)

| Дата | Что |
|---|---|
| май 2026 | MVP HTML-прототипы всех страниц (6 шт) |
| май 2026 | Laravel 13 + миграции + пакеты |
| май 2026 | Деплой на Beget + автодеплой через git |
| май 2026 | Telegram Bot webhook + NotificationService |
| май 2026 | Лендинг для T-Bank на hall.roltworld.com |
| май 2026 | T-Bank интеграция (TBankService + PaymentController) |
| май 2026 | Форма лендинга → backend API → Telegram |
| май 2026 | Страницы success/fail в стиле лендинга |
| 23 май 2026 | test_new.html — новый прототип Admin+Landlord панелей |
| 23 май 2026 | Admin: система табов (admSwitch) + расписание тайм-грид (день/неделя) |

---

## test_new.html — текущий прототип (май 2026)

Файл: `/Volumes/Workspace/Work's/IT/sites/hall.roltworld/test_new.html`
Открывать: прямо в браузере через `file://...` или Finder → двойной клик.
Навигация по ролям: кнопка аккаунта (⊙) в углу nav → выбрать роль.

Хэш-роутинг: `#landing`, `#admin`, `#landlord`

### Структура Admin (5 табов)

| Таб | ID | Статус |
|---|---|---|
| Дашборд | `adm-dashboard` | готов (stats + heatmap + log) |
| Расписание | `adm-schedule` | готов (день/неделя, 09–23) |
| Все брони | `adm-bookings` | готов (таблица, 2 фильтра) |
| Клиенты | `adm-clients` | заглушка — следующий этап |
| Лог действий | `adm-log` | готов |

Переключение: `admSwitch('id', triggerEl)` — обновляет таб, заголовок topbar, active в сайдбаре и mob-nav.

### Тайм-грид (Расписание)

- JS функции: `tgRender()`, `tgRenderDay()`, `tgRenderWeek()`
- Константы: `TG_START=9`, `TG_END=23`, `TG_ROW_H=52px`
- Данные: `MOCK_B` — объект `{дата: [брони]}`, уже используется и у Landlord
- Цвета блоков: `.st-ok` зелёный / `.st-pend` жёлтый / `.st-hold` синий
- День-вид: залы А/Б/В/Г колонками
- Неделя-вид: select зала + 7 дней колонками

### Структура Landlord (4 таба)

| Таб | ID | Статус |
|---|---|---|
| Аналитика | `ll-dashboard` | готов (выручка, загрузка, mini-chart) |
| Брони | `ll-bookings` | готов (date-strip + список по дню) |
| Мои залы | `ll-halls` | готов (карточки, toggle вкл/выкл) |
| Профиль | `ll-settings` | готов (форма, тарифы, TG-бот) |

Переключение: `llSwitch('id', triggerEl)`

### Что ещё нужно сделать в test_new.html

1. **Клиенты** — список с историей, поиском, блэклист (Admin)
2. **Developer** — доп. вкладки в Settings (системные настройки, TG конфиг, API ключи)
3. **Фото залов CRUD** — загрузка/сортировка/удаление (Landlord → Мои залы)
4. **Блокировка дат** — Landlord закрывает дату для бронирования
5. **Убрать эмодзи** из stat-карточек (📅💰⏳📊)
