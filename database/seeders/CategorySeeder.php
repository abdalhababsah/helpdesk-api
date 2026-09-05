<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /** @var array<string, string> */
    private const CATEGORIES = [
        'it-hardware' => 'IT - Hardware',
        'it-access' => 'IT - Access & VPN',
        'hr-payroll' => 'HR - Payroll',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $slug => $name) {
            Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }
    }
}
