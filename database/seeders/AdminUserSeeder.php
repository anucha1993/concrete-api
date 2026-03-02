<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'ADMIN')->first();

        User::updateOrCreate(
            ['email' => 'admin@stockconcrete.com'],
            [
                'name'     => 'System Admin',
                'password' => 'password', // Will be auto-hashed via model cast
                'role_id'  => $adminRole?->id,
                'status'   => 'ACTIVE',
            ]
        );
    }
}
