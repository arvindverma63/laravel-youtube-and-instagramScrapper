<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LinkedIn Profile Scraper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">LinkedIn Profile Scraper</h1>

        <!-- Form -->
        <form action="{{ route('linkedin.redirect') }}" method="GET" class="mb-4">
            <button type="submit" class="btn btn-primary">Sign In with LinkedIn</button>
            @if (session('profileData'))
                <a href="{{ route('linkedin.download') }}" class="btn btn-success">Download CSV</a>
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
                    <h2>{{ $profileData['name'] }}</h2>
                    <p class="text-muted">{{ $profileData['headline'] }}</p>
                </div>
                <div class="card-body">
                    <p><strong>Location:</strong> {{ $profileData['location'] }}</p>
                    <p><strong>Current Position:</strong> {{ $profileData['current_position'] }}</p>
                    <p><strong>Company:</strong> {{ $profileData['company'] }}</p>
                    <p><strong>Profile URL:</strong> <a href="{{ $profileData['profile_url'] }}" target="_blank">{{ $profileData['profile_url'] }}</a></p>
                </div>
            </div>

            <!-- Experiences -->
            @if (!empty($experiences))
                <h3>Experience</h3>
                @foreach ($experiences as $exp)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5>{{ $exp['title'] }}</h5>
                            <p><strong>Company:</strong> {{ $exp['company'] }}</p>
                            <p><strong>Duration:</strong> {{ $exp['duration'] }}</p>
                            <p><strong>Description:</strong> {{ $exp['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            @endif
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
