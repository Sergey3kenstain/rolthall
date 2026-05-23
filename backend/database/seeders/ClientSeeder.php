<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $halls = Hall::all();
        if ($halls->isEmpty()) {
            $this->command->warn('Нет залов — запусти сначала HallSeeder');
            return;
        }

        $data = [
            [
                'name'     => 'Иванова Анна Сергеевна',
                'phone'    => '+79161234567',
                'email'    => 'anna.ivanova@example.com',
                'telegram' => 'anna_dance',
                'note'     => 'Постоянный клиент, предпочитает зал А по утрам.',
                'bookings' => [
                    ['days' => -30, 'hall' => 0, 'h_start' => 9,  'h_end' => 11, 'fmt' => 'single',   'status' => 'completed', 'total' => 7000,  'pre' => 3500],
                    ['days' => -15, 'hall' => 0, 'h_start' => 10, 'h_end' => 12, 'fmt' => 'single',   'status' => 'completed', 'total' => 7000,  'pre' => 3500],
                    ['days' =>   5, 'hall' => 0, 'h_start' => 9,  'h_end' => 11, 'fmt' => 'single',   'status' => 'confirmed', 'total' => 7000,  'pre' => 3500],
                ],
            ],
            [
                'name'     => 'Петров Михаил Андреевич',
                'phone'    => '+79037654321',
                'email'    => 'misha.petrov@gmail.com',
                'telegram' => 'misha_p',
                'note'     => null,
                'bookings' => [
                    ['days' => -60, 'hall' => 1, 'h_start' => 14, 'h_end' => 18, 'fmt' => 'event',    'status' => 'completed', 'total' => 40000, 'pre' => 20000],
                    ['days' =>  10, 'hall' => 0, 'h_start' => 18, 'h_end' => 22, 'fmt' => 'event',    'status' => 'paid',      'total' => 40000, 'pre' => 20000],
                ],
            ],
            [
                'name'     => 'Смирнова Екатерина',
                'phone'    => '+79261112233',
                'email'    => 'kate.smirnova@mail.ru',
                'telegram' => 'kate_smir',
                'note'     => 'Попросила скидку, обсудить при следующей брони.',
                'bookings' => [
                    ['days' => -7,  'hall' => 0, 'h_start' => 11, 'h_end' => 13, 'fmt' => 'single',   'status' => 'completed', 'total' => 7000,  'pre' => 3500],
                    ['days' =>  3,  'hall' => 0, 'h_start' => 11, 'h_end' => 13, 'fmt' => 'single',   'status' => 'pending_payment', 'total' => 7000, 'pre' => 3500],
                ],
            ],
            [
                'name'     => 'Козлов Дмитрий Владимирович',
                'phone'    => '+79851234567',
                'email'    => 'dima.kozlov@yandex.ru',
                'telegram' => null,
                'note'     => null,
                'bookings' => [
                    ['days' => -45, 'hall' => 2, 'h_start' => 16, 'h_end' => 20, 'fmt' => 'event',    'status' => 'completed', 'total' => 40000, 'pre' => 20000],
                ],
            ],
            [
                'name'     => 'Новикова Дарья',
                'phone'    => '+79119876543',
                'email'    => 'dasha.novikova@example.com',
                'telegram' => 'dashanova',
                'note'     => 'Пришла по рекомендации Ивановой.',
                'bookings' => [
                    ['days' => -3,  'hall' => 0, 'h_start' => 9,  'h_end' => 10, 'fmt' => 'single',   'status' => 'completed', 'total' => 3500,  'pre' => 1750],
                    ['days' =>  7,  'hall' => 0, 'h_start' => 9,  'h_end' => 11, 'fmt' => 'single',   'status' => 'confirmed', 'total' => 7000,  'pre' => 3500],
                    ['days' => 14,  'hall' => 0, 'h_start' => 9,  'h_end' => 11, 'fmt' => 'single',   'status' => 'hold',      'total' => 7000,  'pre' => 3500],
                ],
            ],
            [
                'name'     => 'Фёдоров Алексей Игоревич',
                'phone'    => '+79384445566',
                'email'    => 'alex.fedorov@gmail.com',
                'telegram' => 'alex_fed',
                'note'     => 'Организатор мероприятий, может приводить групповые заявки.',
                'bookings' => [
                    ['days' => -90, 'hall' => 0, 'h_start' => 13, 'h_end' => 22, 'fmt' => 'event',    'status' => 'completed', 'total' => 90000, 'pre' => 45000],
                    ['days' => -20, 'hall' => 1, 'h_start' => 10, 'h_end' => 22, 'fmt' => 'event',    'status' => 'completed', 'total' => 90000, 'pre' => 45000],
                    ['days' =>  20, 'hall' => 0, 'h_start' => 13, 'h_end' => 22, 'fmt' => 'event',    'status' => 'confirmed', 'total' => 90000, 'pre' => 45000],
                ],
            ],
            [
                'name'     => 'Морозова Юлия',
                'phone'    => '+79672223344',
                'email'    => 'yulia.morozova@inbox.ru',
                'telegram' => 'yuliya_dance',
                'note'     => null,
                'bookings' => [
                    ['days' => -5,  'hall' => 0, 'h_start' => 17, 'h_end' => 19, 'fmt' => 'single',   'status' => 'cancelled', 'total' => 7000,  'pre' => 3500],
                    ['days' =>  2,  'hall' => 0, 'h_start' => 17, 'h_end' => 19, 'fmt' => 'single',   'status' => 'paid',      'total' => 7000,  'pre' => 3500],
                ],
            ],
        ];

        foreach ($data as $d) {
            // Пропускаем если клиент с таким телефоном уже есть
            if (Client::where('phone', $d['phone'])->exists()) continue;

            $user = User::firstOrCreate(
                ['email' => $d['email']],
                [
                    'name'     => $d['name'],
                    'password' => Hash::make('password'),
                    'phone'    => $d['phone'],
                ]
            );

            $totalPaid = 0;
            $completedBookings = 0;

            $client = Client::create([
                'user_id'          => $user->id,
                'name'             => $d['name'],
                'phone'            => $d['phone'],
                'email'            => $d['email'],
                'telegram_username'=> $d['telegram'],
                'admin_note'       => $d['note'],
                'bookings_count'   => 0,
                'total_paid'       => 0,
            ]);

            foreach ($d['bookings'] as $b) {
                $hall = $halls[$b['hall'] % $halls->count()];
                $date = now()->addDays($b['days'])->format('Y-m-d');

                Booking::create([
                    'client_id'        => $client->id,
                    'hall_id'          => $hall->id,
                    'date'             => $date,
                    'time_start'       => sprintf('%02d:00:00', $b['h_start']),
                    'time_end'         => sprintf('%02d:00:00', $b['h_end']),
                    'duration_hours'   => $b['h_end'] - $b['h_start'],
                    'format'           => $b['fmt'],
                    'status'           => $b['status'],
                    'total_amount'     => $b['total'],
                    'prepayment_amount'=> $b['pre'],
                    'consent_offer'    => true,
                    'consent_policy'   => true,
                    'consent_refund'   => true,
                ]);

                if (in_array($b['status'], ['paid', 'confirmed', 'completed'])) {
                    $totalPaid += $b['pre'];
                    $completedBookings++;
                }
            }

            $client->bookings_count = count($d['bookings']);
            $client->total_paid     = $totalPaid;
            $client->save();
        }

        $this->command->info('ClientSeeder: создано ' . count($data) . ' клиентов');
    }
}
