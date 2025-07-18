<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;


/**
 * @OA\Info(
 *     title="YouTube API Scraper",
 *     version="1.0.0",
 *     description="This API scrapes YouTube channel and video data using the Google YouTube Data API.",
 *     @OA\Contact(
 *         email="support@yourdomain.com",
 *         name="API BackendCoders Team"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 *
 */

class YouTubeScraperController extends Controller
{
    protected $youtube;

    public function __construct()
    {
        $client = new Client();
        $client->setDeveloperKey(env('YOUTUBE_API_KEY')); // Use standard env key
        $this->youtube = new YouTube($client);
    }

    public function index()
    {
        return view('youtube.index');
    }

    public function search(Request $request)
    {
        $request->validate([
            'channel' => 'required|string',
        ]);

        $channelInput = trim($request->input('channel'));
        $maxVideos = 10;

        // Extract identifier from full URL if needed
        $parsed = parse_url($channelInput);
        if (isset($parsed['host']) && str_contains($parsed['host'], 'youtube.com')) {
            $path = $parsed['path'] ?? '';
            $pathSegments = explode('/', trim($path, '/'));

            if (count($pathSegments)) {
                if ($pathSegments[0] === 'channel' && isset($pathSegments[1])) {
                    $channelQuery = $pathSegments[1]; // ID
                } elseif ($pathSegments[0] === 'c' && isset($pathSegments[1])) {
                    $channelQuery = $pathSegments[1]; // Legacy custom username
                } elseif (str_starts_with($pathSegments[0], '@')) {
                    $channelQuery = ltrim($pathSegments[0], '@'); // Handle like @example
                } else {
                    $channelQuery = $channelInput; // fallback
                }
            } else {
                $channelQuery = $channelInput; // fallback
            }
        } else {
            $channelQuery = $channelInput; // plain ID or username
        }

        try {
            // First try fetching channel by ID
            $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                'id' => $channelQuery,
            ]);

            // If no results, try by username (or handle)
            if (empty($channelResponse['items'])) {
                $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                    'forUsername' => $channelQuery,
                ]);
            }

            if (empty($channelResponse['items'])) {
                Log::warning('Channel not found for query: ' . $channelQuery);
                return back()->withErrors(['error' => 'Channel not found. Please check the input.']);
            }

            $channel = $channelResponse['items'][0];
            $channelData = [
                'title' => $channel['snippet']['title'] ?? 'Unknown',
                'description' => $channel['snippet']['description'] ?? 'No description',
                'subscribers' => $channel['statistics']['subscriberCount'] ?? 'Hidden',
                'videoCount' => $channel['statistics']['videoCount'] ?? 0,
                'viewCount' => $channel['statistics']['viewCount'] ?? 0,
                'category' => $channel['brandingSettings']['channel']['keywords'] ?? 'Not specified',
                'banner' => $channel['brandingSettings']['image']['bannerExternalUrl'] ?? null,
                'thumbnail' => $channel['snippet']['thumbnails']['high']['url'] ?? null,
                'publishedAt' => $channel['snippet']['publishedAt'] ?? now(),
                'customUrl' => $channel['snippet']['customUrl'] ?? 'Not set',
            ];

            // Fetch top 10 videos
            $searchResponse = $this->youtube->search->listSearch(['id'], [
                'channelId' => $channel['id'],
                'maxResults' => $maxVideos,
                'order' => 'viewCount',
                'type' => 'video',
            ]);

            $videoIds = array_map(fn($item) => $item['id']['videoId'], $searchResponse['items']);
            $videosResponse = $this->youtube->videos->listVideos(['snippet', 'statistics'], [
                'id' => implode(',', $videoIds),
            ]);

            $videos = [];
            foreach ($videosResponse['items'] as $video) {
                $videos[] = [
                    'title' => $video['snippet']['title'] ?? 'Untitled',
                    'videoId' => $video['id'],
                    'thumbnail' => $video['snippet']['thumbnails']['medium']['url'] ?? null,
                    'views' => $video['statistics']['viewCount'] ?? 0,
                    'publishedAt' => $video['snippet']['publishedAt'] ?? now(),
                    'description' => Str::limit($video['snippet']['description'] ?? '', 100),
                    'likes' => $video['statistics']['likeCount'] ?? 0,
                    'comments' => $video['statistics']['commentCount'] ?? 0,
                ];
            }

            usort($videos, fn($a, $b) => $b['views'] <=> $a['views']);

            session(['channelData' => $channelData, 'videos' => $videos]);

            return view('youtube.index', compact('channelData', 'videos', 'channelQuery'));
        } catch (\Exception $e) {
            Log::error('YouTube API error for query: ' . $channelQuery, [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to fetch channel data: ' . $e->getMessage()]);
        }
    }


    public function download(Request $request)
    {
        $channelData = session('channelData');
        $videos = session('videos');

        if (!$channelData || !$videos) {
            return redirect()->route('youtube.index')->withErrors(['error' => 'No data available for download. Please perform a search first.']);
        }

        $csvContent = [];
        $csvContent[] = ['Type', 'Title', 'Description', 'Subscribers', 'Total Videos', 'Total Views', 'Category/Keywords', 'Custom URL', 'Created', 'Views', 'Likes', 'Comments', 'Video URL'];

        // Add channel data
        $csvContent[] = [
            'Channel',
            $channelData['title'],
            Str::limit($channelData['description'], 200),
            is_numeric($channelData['subscribers']) ? number_format($channelData['subscribers']) : $channelData['subscribers'],
            number_format($channelData['videoCount']),
            number_format($channelData['viewCount']),
            $channelData['category'],
            $channelData['customUrl'],
            \Carbon\Carbon::parse($channelData['publishedAt'])->format('M d, Y'),
            '',
            '',
            '',
            ''
        ];

        // Add video data
        foreach ($videos as $video) {
            $csvContent[] = [
                'Video',
                $video['title'],
                $video['description'],
                '',
                '',
                '',
                '',
                '',
                number_format($video['views']),
                number_format($video['likes']),
                number_format($video['comments']),
                "https://www.youtube.com/watch?v={$video['videoId']}"
            ];
        }

        $csvFile = fopen('php://temp', 'w');
        foreach ($csvContent as $row) {
            fputcsv($csvFile, $row);
        }
        rewind($csvFile);
        $csvOutput = stream_get_contents($csvFile);
        fclose($csvFile);

        $filename = Str::slug($channelData['title']) . '_youtube_data_' . now()->format('Ymd_His') . '.csv';


        return Response::make($csvOutput, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }


    /**
     * @OA\Post(
     *     path="/api/youtube/search",
     *     summary="Search YouTube channel by ID or username and return channel data with top videos",
     *     tags={"YouTube"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"channel"},
     *             @OA\Property(property="channel", type="string", example="UC_x5XG1OV2P6uZZ5FSM9Ttw", description="YouTube channel ID or username")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success response with channel data and top videos",
     *         @OA\JsonContent(
     *             @OA\Property(property="channelData", type="object",
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="description", type="string"),
     *                 @OA\Property(property="subscribers", type="string"),
     *                 @OA\Property(property="videoCount", type="integer"),
     *                 @OA\Property(property="viewCount", type="integer"),
     *                 @OA\Property(property="category", type="string"),
     *                 @OA\Property(property="banner", type="string", format="url"),
     *                 @OA\Property(property="thumbnail", type="string", format="url"),
     *                 @OA\Property(property="publishedAt", type="string", format="date-time"),
     *                 @OA\Property(property="customUrl", type="string"),
     *             ),
     *             @OA\Property(property="videos", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="title", type="string"),
     *                     @OA\Property(property="videoId", type="string"),
     *                     @OA\Property(property="thumbnail", type="string", format="url"),
     *                     @OA\Property(property="views", type="integer"),
     *                     @OA\Property(property="publishedAt", type="string", format="date-time"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="likes", type="integer"),
     *                     @OA\Property(property="comments", type="integer"),
     *                 )
     *             ),
     *             @OA\Property(property="channelQuery", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or channel not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="YouTube API failure"
     *     )
     * )
     */

    public function searchYoutubeApi(Request $request)
    {
        $request->validate([
            'channel' => 'required|string',
        ]);

        $channelInput = trim($request->input('channel'));
        $maxVideos = 10;

        // Extract identifier from full URL if needed
        $parsed = parse_url($channelInput);
        if (isset($parsed['host']) && str_contains($parsed['host'], 'youtube.com')) {
            $path = $parsed['path'] ?? '';
            $pathSegments = explode('/', trim($path, '/'));

            if (count($pathSegments)) {
                if ($pathSegments[0] === 'channel' && isset($pathSegments[1])) {
                    $channelQuery = $pathSegments[1]; // ID
                } elseif ($pathSegments[0] === 'c' && isset($pathSegments[1])) {
                    $channelQuery = $pathSegments[1]; // Legacy custom username
                } elseif (str_starts_with($pathSegments[0], '@')) {
                    $channelQuery = ltrim($pathSegments[0], '@'); // Handle like @example
                } else {
                    $channelQuery = $channelInput; // fallback
                }
            } else {
                $channelQuery = $channelInput; // fallback
            }
        } else {
            $channelQuery = $channelInput; // plain ID or username
        }

        try {
            // First try fetching channel by ID
            $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                'id' => $channelQuery,
            ]);

            // If no results, try by username (or handle)
            if (empty($channelResponse['items'])) {
                $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                    'forUsername' => $channelQuery,
                ]);
            }

            if (empty($channelResponse['items'])) {
                Log::warning('Channel not found for query: ' . $channelQuery);
                return back()->withErrors(['error' => 'Channel not found. Please check the input.']);
            }

            $channel = $channelResponse['items'][0];
            $channelData = [
                'title' => $channel['snippet']['title'] ?? 'Unknown',
                'description' => $channel['snippet']['description'] ?? 'No description',
                'subscribers' => $channel['statistics']['subscriberCount'] ?? 'Hidden',
                'videoCount' => $channel['statistics']['videoCount'] ?? 0,
                'viewCount' => $channel['statistics']['viewCount'] ?? 0,
                'category' => $channel['brandingSettings']['channel']['keywords'] ?? 'Not specified',
                'banner' => $channel['brandingSettings']['image']['bannerExternalUrl'] ?? null,
                'thumbnail' => $channel['snippet']['thumbnails']['high']['url'] ?? null,
                'publishedAt' => $channel['snippet']['publishedAt'] ?? now(),
                'customUrl' => $channel['snippet']['customUrl'] ?? 'Not set',
            ];

            // Fetch top 10 videos
            $searchResponse = $this->youtube->search->listSearch(['id'], [
                'channelId' => $channel['id'],
                'maxResults' => $maxVideos,
                'order' => 'viewCount',
                'type' => 'video',
            ]);

            $videoIds = array_map(fn($item) => $item['id']['videoId'], $searchResponse['items']);
            $videosResponse = $this->youtube->videos->listVideos(['snippet', 'statistics'], [
                'id' => implode(',', $videoIds),
            ]);

            $videos = [];
            foreach ($videosResponse['items'] as $video) {
                $videos[] = [
                    'title' => $video['snippet']['title'] ?? 'Untitled',
                    'videoId' => $video['id'],
                    'thumbnail' => $video['snippet']['thumbnails']['medium']['url'] ?? null,
                    'views' => $video['statistics']['viewCount'] ?? 0,
                    'publishedAt' => $video['snippet']['publishedAt'] ?? now(),
                    'description' => Str::limit($video['snippet']['description'] ?? '', 100),
                    'likes' => $video['statistics']['likeCount'] ?? 0,
                    'comments' => $video['statistics']['commentCount'] ?? 0,
                ];
            }

            usort($videos, fn($a, $b) => $b['views'] <=> $a['views']);

            session(['channelData' => $channelData, 'videos' => $videos]);
            return response()->json([
                'channelData' => $channelData,
                'videos' => $videos,
                'channelQuery' => $channelQuery
            ], 200);

        } catch (\Exception $e) {
            Log::error('YouTube API error for query: ' . $channelQuery, [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to fetch channel data: ' . $e->getMessage()]);
        }
    }
}


