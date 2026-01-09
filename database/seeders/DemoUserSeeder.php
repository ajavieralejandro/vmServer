<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'demo@villamitre.com'],
            [
                'name' => 'Demo User',
                'email' => 'demo@villamitre.com',
                'password' => Hash::make('Demo1234!'),

                // ✅ Campos obligatorios / típicos
                'dni' => '99999999',
                'nombre' => 'Demo',
                'apellido' => 'User',

                // opcionales pero útiles si en tu DB son NOT NULL
                'telefono' => '0000000000',
                'celular' => '0000000000',
                'categoria' => 'DEMO',
                'estado_socio' => 'ACTIVO',
            ]
        );
    }
}
