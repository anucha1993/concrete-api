<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Products (สินค้า, แพ, หมวดหมู่)
            ['name' => 'view_products',   'display_name' => 'ดูสินค้า',     'description' => 'ดูรายการสินค้า แพ หมวดหมู่',               'group' => 'products'],
            ['name' => 'manage_products', 'display_name' => 'จัดการสินค้า', 'description' => 'สร้าง แก้ไข ลบ สินค้า แพ หมวดหมู่',      'group' => 'products'],

            // Locations (ตำแหน่งคลัง)
            ['name' => 'view_locations',   'display_name' => 'ดูตำแหน่งคลัง',     'description' => 'ดูรายการตำแหน่งคลัง',             'group' => 'locations'],
            ['name' => 'manage_locations', 'display_name' => 'จัดการตำแหน่งคลัง', 'description' => 'สร้าง แก้ไข ลบ ตำแหน่งคลัง',      'group' => 'locations'],

            // Inventory (คลังสินค้า, แจ้งเตือน)
            ['name' => 'view_inventory',   'display_name' => 'ดูคลังสินค้า',     'description' => 'ดูสต็อก แจ้งเตือน',                  'group' => 'inventory'],
            ['name' => 'manage_inventory', 'display_name' => 'จัดการคลังสินค้า', 'description' => 'แก้ไขสถานะสินค้าคงคลัง',             'group' => 'inventory'],

            // Production (ใบสั่งผลิต, ปริ้น Label, คำขอปริ้นซ้ำ)
            ['name' => 'view_production',   'display_name' => 'ดูใบสั่งผลิต',     'description' => 'ดูใบสั่งผลิต ปริ้น Label คำขอปริ้นซ้ำ',   'group' => 'production'],
            ['name' => 'manage_production', 'display_name' => 'จัดการใบสั่งผลิต', 'description' => 'สร้าง/จัดการใบผลิต ปริ้น Label อนุมัติปริ้นซ้ำ', 'group' => 'production'],

            // Operations (ตรวจนับสต๊อก, ตัดสต๊อก, เคลม, PDA Token)
            ['name' => 'view_operations',   'display_name' => 'ดูปฏิบัติการคลัง',     'description' => 'ดูตรวจนับ ตัดสต๊อก เคลม',                    'group' => 'operations'],
            ['name' => 'manage_operations', 'display_name' => 'จัดการปฏิบัติการคลัง', 'description' => 'สร้าง/จัดการ ตรวจนับ ตัดสต๊อก เคลม PDA Token', 'group' => 'operations'],

            // Reports (รายงาน)
            ['name' => 'view_reports', 'display_name' => 'ดูรายงาน', 'description' => 'ดูรายงานและ Export Excel', 'group' => 'reports'],

            // Users (ผู้ใช้งาน)
            ['name' => 'view_users',   'display_name' => 'ดูผู้ใช้',     'description' => 'ดูรายการผู้ใช้',          'group' => 'users'],
            ['name' => 'manage_users', 'display_name' => 'จัดการผู้ใช้', 'description' => 'สร้าง แก้ไข ลบ ผู้ใช้',  'group' => 'users'],

            // Roles (บทบาท & สิทธิ์)
            ['name' => 'manage_roles', 'display_name' => 'จัดการสิทธิ์', 'description' => 'สร้าง แก้ไข ลบ Role และกำหนดสิทธิ์', 'group' => 'roles'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }
    }
}
