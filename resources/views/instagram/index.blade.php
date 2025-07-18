<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instagram Profile Scraper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">Instagram Profile Scraper</h1>

        <!-- Form -->
        <form action="{{ route('instagram.search') }}" method="POST" class="mb-4">
            @csrf
            <div class="mb-3">
                <label for="username" class="form-label">Enter Instagram Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="e.g., av36102003" value="{{ $username ?? '' }}" required>
            </div>
            <button type="submit" class="btn btn-primary">Scrape Profile</button>
            @if (session('profileData'))
                {{-- <a href="{{ route('instagram.download') }}" class="btn btn-success">Download CSV</a> --}}
            @endif
        </form>

        <!-- Error Messages -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Profile Data -->
        @if (isset($profileData))
            <div class="card mb-4">
                <div class="card-header">
                    <h2>{{ $profileData['username'] }}</h2>
                    <p class="text-muted">{{ $profileData['full_name'] }}</p>
                </div>
                <div class="card-body">
                    <p><strong>Bio:</strong> {{ $profileData['bio'] }}</p>
                    <p><strong>Followers:</strong> {{ number_format($profileData['followers']) }}</p>
                    <p><strong>Following:</strong> {{ number_format($profileData['following']) }}</p>
                    <p><strong>Posts:</strong> {{ number_format($profileData['posts']) }}</p>
                    <p><strong>Account Type:</strong> {{ $profileData['account_type'] }}</p>
                    <p><strong>Profile URL:</strong> <a href="{{ $profileData['profile_url'] }}" target="_blank">{{ $profileData['profile_url'] }}</a></p>
                </div>
            </div>
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
