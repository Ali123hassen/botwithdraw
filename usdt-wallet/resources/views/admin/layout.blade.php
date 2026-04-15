<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'USDT Wallet Admin')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            min-height: 100vh;
            background: #2c3e50;
        }
        .sidebar a {
            color: #ecf0f1;
            padding: 12px 20px;
            display: block;
            text-decoration: none;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #34495e;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .status-pending { color: #f39c12; }
        .status-completed { color: #27ae60; }
        .status-rejected { color: #e74c3c; }
        .logout-btn {
            background: #e74c3c;
            border: none;
            width: 100%;
            text-align: right;
            padding: 12px 20px;
            color: #ecf0f1 !important;
            cursor: pointer;
        }
        .logout-btn:hover {
            background: #c0392b !important;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-0">
                <div class="text-center py-4 text-white">
                    <h4><i class="fas fa-wallet"></i> USDT Admin</h4>
                </div>
                <nav>
                    <a href="{{ route('admin.withdrawals') }}" class="{{ Request::routeIs('admin.withdrawals') ? 'active' : '' }}">
                        <i class="fas fa-list me-2"></i> Withdrawals
                    </a>
                    <a href="{{ route('admin.settings') }}" class="{{ Request::routeIs('admin.settings') ? 'active' : '' }}">
                        <i class="fas fa-cog me-2"></i> Settings
                    </a>
                    <form action="{{ route('logout') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="logout-btn">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </button>
                    </form>
                </nav>
            </div>
            <div class="col-md-10 p-4">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @yield('scripts')
</body>
</html>
