<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'wallet_address',
        'amount',
        'network_fee',
        'deposit_fee_percent',
        'total_deducted',
        'network',
        'currency',
        'status',
        'tx_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'network_fee' => 'decimal:8',
        'deposit_fee_percent' => 'decimal:2',
        'total_deducted' => 'decimal:8',
    ];
}
