<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'แผ่นพื้น',  'slug' => 'floor-slab',     'description' => 'แผ่นพื้นคอนกรีตสำเร็จรูป'],
            ['name' => 'เสาเข็ม',   'slug' => 'pile',            'description' => 'เสาเข็มคอนกรีตอัดแรง'],
            ['name' => 'เสารั้ว',   'slug' => 'fence-post',      'description' => 'เสารั้วคอนกรีต'],
            ['name' => 'คาน',       'slug' => 'beam',            'description' => 'คานคอนกรีตสำเร็จรูป'],
            ['name' => 'ท่อระบาย', 'slug' => 'drainage-pipe',   'description' => 'ท่อระบายน้ำคอนกรีต'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
