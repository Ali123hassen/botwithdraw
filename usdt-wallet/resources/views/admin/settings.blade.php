@extends('admin.layout')

@section('title', 'Settings')

@section('content')
<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="fas fa-cog"></i> System Settings</h2>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <form action="{{ route('admin.settings.update') }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2">Fee Settings</h5>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Network Fee (USD)</label>
                                <input type="number" name="network_fee" class="form-control" value="{{ $settings['network_fee'] }}" step="0.01" min="0">
                                <small class="text-muted">Fixed fee for network (default: $1)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Deposit Fee (%)</label>
                                <input type="number" name="deposit_fee_percent" class="form-control" value="{{ $settings['deposit_fee_percent'] }}" step="0.1" min="0" max="100">
                                <small class="text-muted">Percentage fee on deposit (default: 4%)</small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4 mt-4">
                        <h5 class="border-bottom pb-2">Wallet & Bot Settings</h5>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">USDT Wallet Address (TRC20)</label>
                        <input type="text" name="wallet_address" class="form-control" value="{{ $settings['wallet_address'] }}">
                        <small class="text-muted">Your USDT TRC20 wallet address for receiving funds</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Telegram Bot Token</label>
                        <input type="text" name="bot_token" class="form-control" value="{{ $settings['bot_token'] }}">
                        <small class="text-muted">Get bot token from @BotFather on Telegram</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Allowed User ID</label>
                        <input type="text" name="allowed_user_id" class="form-control" value="{{ $settings['allowed_user_id'] }}">
                        <small class="text-muted">Telegram user ID allowed to use the bot</small>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="border-bottom pb-2">Fee Calculator</h5>
                <div class="mb-3">
                    <label class="form-label">Amount (USDT)</label>
                    <input type="number" id="calcAmount" class="form-control" placeholder="Enter amount" oninput="calculateFee()">
                </div>
                <div class="alert alert-info">
                    <h6>Calculation:</h6>
                    <p class="mb-1">Amount: <span id="calcAmountDisplay">0</span> USDT</p>
                    <p class="mb-1">Network Fee: ${{ $settings['network_fee'] }}</p>
                    <p class="mb-1">Deposit Fee ({{ $settings['deposit_fee_percent'] }}%): <span id="calcDepositFee">0</span> USDT</p>
                    <hr>
                    <p class="mb-0"><strong>Total Deducted: <span id="calcTotal">0</span> USDT</strong></p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function calculateFee() {
    const amount = parseFloat(document.getElementById('calcAmount').value) || 0;
    const networkFee = {{ $settings['network_fee'] }};
    const depositFeePercent = {{ $settings['deposit_fee_percent'] }};
    
    const depositFee = amount * (depositFeePercent / 100);
    const total = amount + networkFee + depositFee;
    
    document.getElementById('calcAmountDisplay').textContent = amount.toFixed(8);
    document.getElementById('calcDepositFee').textContent = depositFee.toFixed(8);
    document.getElementById('calcTotal').textContent = total.toFixed(8);
}
</script>
@endsection