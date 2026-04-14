<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'network_fee', 'value' => '1'],
            ['key' => 'deposit_fee_percent', 'value' => '4'],
            ['key' => 'wallet_address', 'value' => ''],
            ['key' => 'bot_token', 'value' => ''],
            ['key' => 'allowed_user_id', 'value' => ''],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
