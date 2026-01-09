<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'demo@villamitre.com';

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo User',
                'password' => Hash::make('Demo1234!'),
                // Si tu tabla tiene campos obligatorios, completalos acá:
                // 'telefono' => '0000000000',
                // 'dni' => '00000000',
                // 'estado' => 'activo',
            ]
        );

        // Si manejás roles/perfiles, asignalo acá (ejemplo):
        // $user->role = 'socio';
        // $user->save();
    }
}
