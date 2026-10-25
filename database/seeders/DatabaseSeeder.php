<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * No default login accounts: factories belong in isolated tests.
     * Future provisioning must use an explicitly authorized account workflow.
     */
    public function run(): void
    {
        // Reference data can be added here when a domain model requires it.
    }
}
