<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'network_fee' => Setting::get('network_fee', 1),
            'deposit_fee_percent' => Setting::get('deposit_fee_percent', 4),
            'wallet_address' => Setting::get('wallet_address', ''),
            'bot_token' => Setting::get('bot_token', ''),
            'allowed_user_id' => Setting::get('allowed_user_id', ''),
        ];
        
        return view('admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'network_fee' => 'required|numeric|min:0',
            'deposit_fee_percent' => 'required|numeric|min:0|max:100',
            'wallet_address' => 'nullable|string',
            'bot_token' => 'nullable|string',
            'allowed_user_id' => 'nullable|string',
        ]);

        Setting::set('network_fee', $request->network_fee);
        Setting::set('deposit_fee_percent', $request->deposit_fee_percent);
        
        if ($request->wallet_address) {
            Setting::set('wallet_address', $request->wallet_address);
        }
        
        if ($request->bot_token) {
            Setting::set('bot_token', $request->bot_token);
        }
        
        if ($request->allowed_user_id) {
            Setting::set('allowed_user_id', $request->allowed_user_id);
        }

        return redirect()->back()->with('success', 'Settings updated successfully!');
    }

    public function get()
    {
        return response()->json([
            'network_fee' => Setting::get('network_fee', 1),
            'deposit_fee_percent' => Setting::get('deposit_fee_percent', 4),
            'wallet_address' => Setting::get('wallet_address', ''),
        ]);
    }
}
