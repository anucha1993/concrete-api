<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'คลังสินค้า A', 'code' => 'WH-A', 'description' => 'คลังสินค้าหลัก โซน A'],
            ['name' => 'คลังสินค้า B', 'code' => 'WH-B', 'description' => 'คลังสินค้าหลัก โซน B'],
            ['name' => 'คลังสินค้า C', 'code' => 'WH-C', 'description' => 'คลังสินค้าสำรอง โซน C'],
            ['name' => 'ลานจัดเก็บ',   'code' => 'YARD',  'description' => 'ลานจัดเก็บกลางแจ้ง'],
        ];

        foreach ($locations as $location) {
            Location::updateOrCreate(
                ['code' => $location['code']],
                $location
            );
        }
    }
}
