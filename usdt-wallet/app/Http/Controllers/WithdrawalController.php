<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Models\Setting;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index()
    {
        $withdrawals = Withdrawal::orderBy('created_at', 'desc')->get();
        $totalAmount = Withdrawal::sum('amount');
        $totalDeducted = Withdrawal::sum('total_deducted');
        
        return view('admin.withdrawals', compact('withdrawals', 'totalAmount', 'totalDeducted'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'telegram_user_id' => 'required',
            'wallet_address' => 'required',
            'amount' => 'required|numeric|min:0',
        ]);

        $networkFee = (float) Setting::get('network_fee', 1);
        $depositFeePercent = (float) Setting::get('deposit_fee_percent', 4);
        
        $amount = (float) $request->amount;
        $depositFee = $amount * ($depositFeePercent / 100);
        $totalDeducted = $amount + $networkFee + $depositFee;

        $withdrawal = Withdrawal::create([
            'telegram_user_id' => $request->telegram_user_id,
            'wallet_address' => $request->wallet_address,
            'amount' => $amount,
            'network_fee' => $networkFee,
            'deposit_fee_percent' => $depositFeePercent,
            'total_deducted' => $totalDeducted,
            'network' => 'TRC20',
            'currency' => 'USDT',
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'withdrawal' => $withdrawal
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,rejected',
            'tx_hash' => 'nullable|string',
        ]);

        $withdrawal = Withdrawal::findOrFail($id);
        $withdrawal->status = $request->status;
        
        if ($request->tx_hash) {
            $withdrawal->tx_hash = $request->tx_hash;
        }
        
        $withdrawal->save();

        return response()->json([
            'success' => true,
            'withdrawal' => $withdrawal
        ]);
    }

    public function show($id)
    {
        $withdrawal = Withdrawal::findOrFail($id);
        return response()->json($withdrawal);
    }

    public function destroy($id)
    {
        $withdrawal = Withdrawal::findOrFail($id);
        $withdrawal->delete();

        return response()->json(['success' => true]);
    }

    public function stats()
    {
        $stats = [
            'total_withdrawals' => Withdrawal::count(),
            'total_amount' => Withdrawal::sum('amount'),
            'total_deducted' => Withdrawal::sum('total_deducted'),
            'pending_count' => Withdrawal::where('status', 'pending')->count(),
            'completed_count' => Withdrawal::where('status', 'completed')->count(),
            'rejected_count' => Withdrawal::where('status', 'rejected')->count(),
        ];

        return response()->json($stats);
    }
}
