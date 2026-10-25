<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el administrador inicial con ADMIN_EMAIL / ADMIN_PASSWORD del .env.
     * No existe un usuario ni una contrasena por defecto: si falta el .env, no se crea nada.
     */
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL');
        $password = (string) env('ADMIN_PASSWORD');

        if ($email === '' || strlen($password) < 12) {
            $this->command?->warn('Define ADMIN_EMAIL y ADMIN_PASSWORD (minimo 12 caracteres) en .env y vuelve a ejecutar db:seed.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => (string) env('ADMIN_NAME', 'Administrador'), 'password' => Hash::make($password)]
        );

        $this->command?->info("Administrador listo: {$email}");
    }
}
