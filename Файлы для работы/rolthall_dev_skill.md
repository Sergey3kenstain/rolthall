# RoltHall — Developer Skill Guide

Гайд по разработке проекта. Читать перед началом работы над любым модулем.

---

## 1. Бренд и дизайн-система

### Цветовая палитра

```css
--color-bg:         #3d3b3f;   /* Pantone 19-3916 TCX Charcoal Art — основной фон */
--color-surface:    #2e2c30;   /* Поверхности карточек, сайдбаров */
--color-surface-2:  #252328;   /* Глубокий фон, модалки */
--color-accent:     #376FFF;   /* Основной акцент */
--color-accent-2:   #5B8FFF;   /* Светлый акцент, hover */
--color-accent-grad: linear-gradient(135deg, #376FFF 0%, #5B8FFF 100%);
--color-text:       #FFFFFF;
--color-text-muted: #A0A0A8;
--color-border:     rgba(255,255,255,0.08);
--color-success:    #2ECC71;
--color-warning:    #F39C12;
--color-danger:     #E74C3C;
```

### Логотип
- Файл: `logo_rolthall.svg` (белый горизонтальный)
- В тёмном интерфейсе — белый логотип
- Минимальный размер: высота 24px

---

## 2. Архитектура проекта

```
rolthall/
├── frontend/                  # Next.js 14 (App Router)
│   ├── app/
│   │   ├── (public)/          # Публичные страницы
│   │   │   ├── page.tsx       # Главная
│   │   │   ├── halls/         # Каталог залов
│   │   │   └── booking/       # Оформление брони
│   │   ├── (auth)/            # Авторизация
│   │   │   ├── login/
│   │   │   └── register/
│   │   ├── cabinet/           # Личный кабинет клиента
│   │   └── admin/             # Проброс на FilamentPHP (или встроенная панель)
│   ├── components/
│   │   ├── ui/                # Базовые компоненты
│   │   ├── halls/             # Карточка зала, карусель
│   │   ├── booking/           # Календарь, тарифная таблица, форма
│   │   └── cabinet/           # Личный кабинет компоненты
│   ├── lib/
│   │   ├── api.ts             # API клиент (axios/fetch)
│   │   ├── pricing.ts         # Клиентский расчёт стоимости
│   │   └── types.ts           # TypeScript типы
│   └── stores/
│       └── booking.ts         # Zustand: состояние бронирования
│
└── backend/                   # Laravel 11
    ├── app/
    │   ├── Http/Controllers/
    │   │   ├── API/
    │   │   │   ├── HallController.php
    │   │   │   ├── BookingController.php
    │   │   │   ├── PricingController.php
    │   │   │   └── ProfileController.php
    │   ├── Models/
    │   │   ├── Hall.php
    │   │   ├── Booking.php
    │   │   ├── Client.php
    │   │   ├── PricingRule.php
    │   │   └── ActionLog.php
    │   ├── Services/
    │   │   ├── BookingService.php     # Логика броней, hold, override
    │   │   ├── PricingService.php     # Расчёт стоимости
    │   │   ├── PaymentService.php     # CloudPayments интеграция
    │   │   └── NotificationService.php # Telegram + Email
    │   └── Jobs/
    │       ├── ReleaseHoldJob.php
    │       ├── SendReminderJob.php
    │       └── CompleteBookingJob.php
    ├── database/
    │   └── migrations/
    └── routes/
        └── api.php
```

---

## 3. База данных — ключевые таблицы

### halls
```sql
id, name, description, area_m2, capacity,
equipment (json), photos (json), videos (json),
is_active, sort_order, created_at, updated_at
```

### pricing_rules
```sql
id, hall_id,
day_type (enum: weekday|weekend),
min_hours, max_hours (null = без ограничения),
price_per_hour,
is_active,
created_at, updated_at
```

### bookings
```sql
id, client_id, hall_id,
date, time_start, time_end, duration_hours,
format (enum: event|single|monthly),
status (enum: draft|hold|pending_payment|paid|confirmed|completed|cancelled),
total_amount, prepayment_amount,
payment_token, transaction_id,
hold_expires_at,
notes, admin_notes,
created_at, updated_at
```

### clients
```sql
id, user_id,
name, phone, email, telegram_username,
is_blacklisted, blacklist_reason,
total_paid, bookings_count,
created_at, updated_at
```

### action_logs
```sql
id, user_id, role, action, target_type, target_id,
payload (json), ip, user_agent,
created_at  -- immutable, без updated_at
```

---

## 4. API роуты

### Публичные
```
GET  /api/halls                    # Список залов
GET  /api/halls/{id}               # Детали зала
GET  /api/halls/{id}/availability  # Доступность слотов (дата + зал)
GET  /api/halls/{id}/pricing       # Тарифы зала
POST /api/bookings/hold            # Создать hold
POST /api/bookings/{id}/pay        # Инициировать оплату
GET  /api/bookings/{id}/status     # Статус брони
```

### Авторизованные (client)
```
GET  /api/cabinet/profile          # Профиль
PUT  /api/cabinet/profile          # Обновить профиль
GET  /api/cabinet/bookings         # Мои брони
POST /api/cabinet/bookings/{id}/cancel
POST /api/cabinet/bookings/{id}/reschedule-request
```

### Admin/Landlord
```
GET  /api/admin/bookings           # Все брони
POST /api/admin/bookings           # Ручная бронь
PUT  /api/admin/bookings/{id}      # Редактировать
POST /api/admin/bookings/{id}/override
GET  /api/admin/clients            # CRM
PUT  /api/admin/clients/{id}/blacklist
GET  /api/admin/analytics/dashboard
GET  /api/admin/logs               # Action logs
```

---

## 5. Pricing Service — логика расчёта

```php
// PricingService::calculate(hall_id, date, duration_hours)

1. Определить день недели → weekday | weekend
2. Найти подходящий PricingRule:
   WHERE hall_id = ? AND day_type = ?
   AND min_hours <= duration AND (max_hours IS NULL OR max_hours >= duration)
3. total = duration_hours * price_per_hour
4. prepayment = total * 0.5
5. Вернуть: { price_per_hour, total, prepayment, rule_id }
```

**Клиентский preview (TypeScript):**
```typescript
// lib/pricing.ts
export function calculatePrice(rules: PricingRule[], date: Date, hours: number) {
  const isWeekend = [0, 6].includes(date.getDay());
  const dayType = isWeekend ? 'weekend' : 'weekday';

  const rule = rules
    .filter(r => r.day_type === dayType)
    .find(r => r.min_hours <= hours && (r.max_hours === null || r.max_hours >= hours));

  if (!rule) return null;

  const total = rule.price_per_hour * hours;
  return { pricePerHour: rule.price_per_hour, total, prepayment: total * 0.5 };
}
```

---

## 6. Hold логика (Redis)

```php
// BookingService::createHold()

$key = "hold:hall:{$hallId}:slot:{$date}:{$timeStart}";
Redis::setex($key, 600, $bookingId); // 10 минут TTL

// Booking статус → hold
// hold_expires_at = now() + 10 min

// Scheduler: каждую минуту
// WHERE status = 'hold' AND hold_expires_at < now()
// → status = 'cancelled', Redis::del($key)
```

---

## 7. Telegram уведомления

```php
// NotificationService::sendBookingNotification(Booking $booking)

$text = "🏠 Новая бронь — RoltHall\n\n"
    . "👤 ФИО: {$client->name}\n"
    . "📋 Формат: {$booking->format_label}\n"
    . "📅 Дата, время: {$booking->date_formatted}\n"
    . "🏛 Зал: {$hall->name}\n"
    . "📞 Телефон: {$client->phone}\n"
    . "💬 Телеграм: @{$client->telegram_username}\n\n"
    . "✅ Положение принято: Да\n\n"
    . "💰 Оплачено: {$booking->prepayment_amount} RUB\n"
    . "🔑 ИД транзакции CP: {$booking->transaction_id}";

TelegramBot::sendMessage(config('telegram.admin_chat_id'), $text);
```

Бот: `TELEGRAM_BOT_TOKEN` в `.env`
Чат администраторов: `TELEGRAM_ADMIN_CHAT_ID`

---

## 8. Деплой на Beget

### Требования к VPS
- Ubuntu 22.04 LTS
- PHP 8.3 + php-fpm, php-pgsql, php-redis, php-curl
- Node.js 20 LTS
- PostgreSQL 16
- Redis (локальный)
- Nginx
- Supervisor
- PM2 (глобально через npm)
- Composer 2

### Nginx конфиг (схема)
```nginx
server {
    listen 80;
    server_name rolthall.ru;

    # Next.js
    location / {
        proxy_pass http://127.0.0.1:3000;
    }

    # Laravel API
    location /api {
        root /var/www/rolthall/backend/public;
        try_files $uri $uri/ /index.php?$query_string;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Медиафайлы
    location /storage {
        root /var/www/rolthall/backend/public;
    }
}
```

### Supervisor (воркеры)
```ini
[program:rolthall-worker]
command=php /var/www/rolthall/backend/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=2
```

### PM2 (Next.js)
```bash
pm2 start npm --name "rolthall-frontend" -- start
pm2 save && pm2 startup
```

### Cron (Laravel Scheduler)
```cron
* * * * * cd /var/www/rolthall/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. .env переменные (обязательные)

```env
# App
APP_NAME=RoltHall
APP_URL=https://rolthall.ru
APP_ENV=production

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rolthall
DB_USERNAME=rolthall_user
DB_PASSWORD=

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# CloudPayments
CP_PUBLIC_ID=
CP_API_SECRET=

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_ADMIN_CHAT_ID=

# Mail
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@rolthall.ru
```

---

## 10. Соглашения по коду

### Laravel
- Весь бизнес-код — в `Services/`, не в контроллерах
- Контроллеры: валидация + вызов сервиса + ответ
- Все действия пишутся в `action_logs` через `ActionLogger::log()`
- API ответы — Resource классы (`HallResource`, `BookingResource`)
- Исключения — кастомные Handler в `app/Exceptions/`

### Next.js
- Страницы — Server Components по умолчанию
- Клиентские компоненты — `'use client'` только там где нужно
- API запросы — через `lib/api.ts` (единая точка)
- Состояние бронирования — Zustand store `stores/booking.ts`
- Стили — только TailwindCSS + CSS переменные

### Именование
- API: snake_case в JSON
- TypeScript: camelCase
- Компоненты: PascalCase
- Файлы компонентов: `kebab-case.tsx`
