@extends('admin.layout')

@section('title', 'Withdrawals')

@section('content')
<div class="row mb-4">
    <div class="col-md-12">
        <h2><i class="fas fa-list"></i> Withdrawal Requests</h2>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card bg-primary text-white">
            <div class="card-body">
                <h5>Total Withdrawals</h5>
                <h3>{{ $withdrawals->count() }}</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-success text-white">
            <div class="card-body">
                <h5>Total Amount</h5>
                <h3>{{ number_format($totalAmount, 2) }} USDT</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-warning text-white">
            <div class="card-body">
                <h5>Pending</h5>
                <h3>{{ $withdrawals->where('status', 'pending')->count() }}</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card bg-info text-white">
            <div class="card-body">
                <h5>Total Deducted</h5>
                <h3>{{ number_format($totalDeducted, 2) }} USDT</h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User ID</th>
                        <th>Wallet Address</th>
                        <th>Amount</th>
                        <th>Network Fee</th>
                        <th>Deposit Fee %</th>
                        <th>Total Deducted</th>
                        <th>Network</th>
                        <th>Status</th>
                        <th>TX Hash</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($withdrawals as $withdrawal)
                    <tr>
                        <td>{{ $withdrawal->id }}</td>
                        <td>{{ $withdrawal->telegram_user_id }}</td>
                        <td class="text-truncate" style="max-width: 150px;" title="{{ $withdrawal->wallet_address }}">
                            {{ $withdrawal->wallet_address }}
                        </td>
                        <td>{{ number_format($withdrawal->amount, 8) }}</td>
                        <td>{{ number_format($withdrawal->network_fee, 2) }}</td>
                        <td>{{ $withdrawal->deposit_fee_percent }}%</td>
                        <td>{{ number_format($withdrawal->total_deducted, 8) }}</td>
                        <td>{{ $withdrawal->network }}</td>
                        <td>
                            <span class="badge bg-{{ $withdrawal->status == 'completed' ? 'success' : ($withdrawal->status == 'rejected' ? 'danger' : 'warning') }}">
                                {{ ucfirst($withdrawal->status) }}
                            </span>
                        </td>
                        <td class="text-truncate" style="max-width: 100px;">
                            @if($withdrawal->tx_hash)
                                <a href="https://tronscan.org/#/transaction/{{ $withdrawal->tx_hash }}" target="_blank" title="{{ $withdrawal->tx_hash }}">
                                    {{ substr($withdrawal->tx_hash, 0, 10) }}...
                                </a>
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $withdrawal->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#updateModal{{ $withdrawal->id }}">
                                <i class="fas fa-edit"></i>
                            </button>
                            
                            <div class="modal fade" id="updateModal{{ $withdrawal->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Update Withdrawal #{{ $withdrawal->id }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('admin.withdrawals.update', $withdrawal->id) }}" method="POST">
                                            @csrf
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Status</label>
                                                    <select name="status" class="form-select">
                                                        <option value="pending" {{ $withdrawal->status == 'pending' ? 'selected' : '' }}>Pending</option>
                                                        <option value="completed" {{ $withdrawal->status == 'completed' ? 'selected' : '' }}>Completed</option>
                                                        <option value="rejected" {{ $withdrawal->status == 'rejected' ? 'selected' : '' }}>Rejected</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">TX Hash (optional)</label>
                                                    <input type="text" name="tx_hash" class="form-control" value="{{ $withdrawal->tx_hash }}">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-primary">Update</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="12" class="text-center">No withdrawals found</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection