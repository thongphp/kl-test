<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $dealership = Dealership::factory()->create([
            'name' => 'Demo Dealership',
            'timezone' => 'UTC',
        ]);

        Technician::factory(3)->create(['dealership_id' => $dealership->id]);
        ServiceBay::factory(3)->create(['dealership_id' => $dealership->id]);
        ServiceType::factory(4)->create();

        Customer::factory()->has(Vehicle::factory(2))->create([
            'email' => 'customer@example.com',
            'password' => Hash::make('password'),
        ]);
    }
}
