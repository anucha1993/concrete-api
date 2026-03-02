<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name'         => 'ADMIN',
                'display_name' => 'ผู้ดูแลระบบ',
                'description'  => 'สิทธิ์เต็มทั้งหมด',
                'permissions'  => Permission::all()->pluck('id')->toArray(), // All permissions
            ],
            [
                'name'         => 'WAREHOUSE',
                'display_name' => 'คลังสินค้า',
                'description'  => 'จัดการคลังสินค้า ใบสั่งผลิต ปริ้น Label ตรวจนับ ตัดสต๊อก เคลม',
                'permissions'  => [
                    'view_products', 'view_locations', 'manage_locations',
                    'view_inventory', 'manage_inventory',
                    'view_production', 'manage_production',
                    'view_operations', 'manage_operations',
                ],
            ],
            [
                'name'         => 'PRODUCTION',
                'display_name' => 'ฝ่ายผลิต',
                'description'  => 'จัดการสินค้าและใบสั่งผลิต ปริ้น Label',
                'permissions'  => [
                    'view_products', 'manage_products', 'view_locations',
                    'view_inventory',
                    'view_production', 'manage_production',
                ],
            ],
            [
                'name'         => 'SALE',
                'display_name' => 'ฝ่ายขาย',
                'description'  => 'ดูสินค้า คลัง และรายงาน',
                'permissions'  => [
                    'view_products', 'view_locations',
                    'view_inventory',
                    'view_production',
                    'view_reports',
                ],
            ],
            [
                'name'         => 'AUDITOR',
                'display_name' => 'ผู้ตรวจสอบ',
                'description'  => 'ดูข้อมูลทั้งหมดและรายงาน',
                'permissions'  => [
                    'view_products', 'view_locations',
                    'view_inventory',
                    'view_production',
                    'view_operations',
                    'view_reports',
                    'view_users',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionNames = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::updateOrCreate(
                ['name' => $roleData['name']],
                $roleData
            );

            // For ADMIN, permissions are already IDs
            if ($roleData['name'] === 'ADMIN') {
                $role->permissions()->sync($permissionNames);
            } else {
                $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');
                $role->permissions()->sync($permissionIds);
            }
        }
    }
}
