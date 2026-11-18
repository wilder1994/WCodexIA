<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Superadmin',
                'email' => env('WCODEX_SUPERADMIN_EMAIL'),
                'password' => env('WCODEX_SUPERADMIN_PASSWORD'),
                'role' => 'superadmin',
            ],
            [
                'name' => 'Admin',
                'email' => env('WCODEX_ADMIN_EMAIL'),
                'password' => env('WCODEX_ADMIN_PASSWORD'),
                'role' => 'admin',
            ],
        ];

        foreach ($accounts as $account) {
            if (! is_string($account['email']) || $account['email'] === '') {
                continue;
            }
            if (! is_string($account['password']) || $account['password'] === '') {
                continue;
            }

            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $account['password'],
                    'role' => $account['role'],
                ],
            );
        }
    }
}
