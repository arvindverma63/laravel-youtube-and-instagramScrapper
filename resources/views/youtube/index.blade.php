<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Channel Scraper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="display-5 fw-bold mb-4">YouTube Channel Scraper</h1>

        <form action="{{ route('youtube.search') }}" method="POST" class="mb-5">
            @csrf
            <div class="input-group">
                <input type="text" name="channel" value="{{ $channelQuery ?? '' }}" class="form-control" placeholder="Enter channel ID or username (e.g., @MrBeast or UCX6OQ3DkcsbYNE6H8uQQuVA)" aria-label="Channel search">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
            @error('error')
                <div class="alert alert-danger mt-3" role="alert">
                    {{ $message }}
                </div>
            @enderror
        </form>

        @if(isset($channelData))
            <div class="card mb-5 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title h4 mb-3">{{ $channelData['title'] }}</h2>
                    @if($channelData['banner'])
                        <img src="{{ $channelData['banner'] }}" alt="Channel Banner" class="img-fluid rounded mb-3" style="max-height: 200px; width: 100%; object-fit: cover;">
                    @endif
                    <div class="d-flex align-items-center mb-3">
                        @if($channelData['thumbnail'])
                            <img src="{{ $channelData['thumbnail'] }}" alt="Channel Thumbnail" class="rounded-circle me-3" style="width: 80px; height: 80px;">
                        @endif
                        <div>
                            <p class="mb-1"><strong>Subscribers:</strong> {{ is_numeric($channelData['subscribers']) ? number_format($channelData['subscribers']) : $channelData['subscribers'] }}</p>
                            <p class="mb-1"><strong>Total Videos:</strong> {{ number_format($channelData['videoCount']) }}</p>
                            <p class="mb-1"><strong>Total Views:</strong> {{ number_format($channelData['viewCount']) }}</p>
                            <p class="mb-1"><strong>Custom URL:</strong> {{ $channelData['customUrl'] }}</p>
                        </div>
                    </div>
                    <p class="mb-1"><strong>Category/Keywords:</strong> {{ $channelData['category'] }}</p>
                    <p class="mb-1"><strong>Description:</strong> {{ \Illuminate\Support\Str::limit($channelData['description'], 200) }}</p>
                    <p><strong>Created:</strong> {{ \Carbon\Carbon::parse($channelData['publishedAt'])->format('M d, Y') }}</p>
                </div>
            </div>

            <h3 class="h5 fw-bold mb-4">Top 10 Videos (by Views)</h3>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                @foreach($videos as $video)
                    <div class="col">
                        <div class="card h-100 shadow-sm">
                            <img src="{{ $video['thumbnail'] }}" alt="{{ $video['title'] }}" class="card-img-top" style="height: 200px; object-fit: cover;">
                            <div class="card-body">
                                <h5 class="card-title" style="font-size: 1rem;">{{ \Illuminate\Support\Str::limit($video['title'], 50) }}</h5>
                                <p class="card-text text-muted" style="font-size: 0.9rem;">{{ $video['description'] }}</p>
                                <p class="card-text text-muted small">Views: {{ number_format($video['views']) }}</p>
                                <p class="card-text text-muted small">Likes: {{ number_format($video['likes']) }}</p>
                                <p class="card-text text-muted small">Comments: {{ number_format($video['comments']) }}</p>
                                <p class="card-text text-muted small">Published: {{ \Carbon\Carbon::parse($video['publishedAt'])->format('M d, Y') }}</p>
                                <a href="https://www.youtube.com/watch?v={{ $video['videoId'] }}" target="_blank" class="btn btn-outline-primary btn-sm">Watch Video</a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
