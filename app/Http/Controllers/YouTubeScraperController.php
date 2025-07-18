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

        $channelQuery = $request->input('channel');
        $maxVideos = 10;

        try {
            // First try fetching channel by ID
            $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                'id' => $channelQuery,
            ]);

            // If no results, try by username
            if (empty($channelResponse['items'])) {
                $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                    'forUsername' => ltrim($channelQuery, '@'),
                ]);
            }

            // Check if channel is found
            if (empty($channelResponse['items'])) {
                Log::warning('Channel not found for query: ' . $channelQuery);
                return back()->withErrors(['error' => 'Channel not found. Please check the channel ID or username.']);
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

            // Sort videos by view count (descending)
            usort($videos, fn($a, $b) => $b['views'] <=> $a['views']);

            // Store data in session for CSV download
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
     *     path="/api/search-youtube",
     *     summary="Search YouTube channel data by username or URL",
     *     description="Fetches YouTube channel details and top videos based on a provided username or channel URL.",
     *     operationId="searchYoutubeApi",
     *     tags={"YouTube"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"channel"},
     *             @OA\Property(
     *                 property="channel",
     *                 type="string",
     *                 example="@natgeo or https://www.youtube.com/@natgeo",
     *                 description="YouTube channel username (e.g., @natgeo) or URL (e.g., https://www.youtube.com/@natgeo or https://www.youtube.com/channel/UCpI...)"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="YouTube channel data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="channelQuery", type="string", example="@natgeo"),
     *             @OA\Property(property="channelData", type="object",
     *                 @OA\Property(property="title", type="string", example="National Geographic"),
     *                 @OA\Property(property="description", type="string", example="Exploring the world..."),
     *                 @OA\Property(property="subscribers", type="string", example="1000000"),
     *                 @OA\Property(property="videoCount", type="integer", example=500),
     *                 @OA\Property(property="viewCount", type="integer", example=100000000),
     *                 @OA\Property(property="category", type="string", example="Education"),
     *                 @OA\Property(property="banner", type="string", example="https://..."),
     *                 @OA\Property(property="thumbnail", type="string", example="https://..."),
     *                 @OA\Property(property="publishedAt", type="string", example="2005-11-15T12:00:00Z"),
     *                 @OA\Property(property="customUrl", type="string", example="@natgeo")
     *             ),
     *             @OA\Property(property="videos", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="title", type="string", example="Amazing Wildlife"),
     *                     @OA\Property(property="videoId", type="string", example="dQw4w9WgXcQ"),
     *                     @OA\Property(property="thumbnail", type="string", example="https://..."),
     *                     @OA\Property(property="views", type="integer", example=100000),
     *                     @OA\Property(property="publishedAt", type="string", example="2023-01-01T12:00:00Z"),
     *                     @OA\Property(property="description", type="string", example="A documentary..."),
     *                     @OA\Property(property="likes", type="integer", example=5000),
     *                     @OA\Property(property="comments", type="integer", example=200)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="YouTube API error"
     *     )
     * )
     */
    public function searchYoutubeApi(Request $request)
    {
        $request->validate([
            'channel' => 'required|string|regex:/^(@[a-zA-Z0-9._]+|https:\/\/www\.youtube\.com\/(channel\/[a-zA-Z0-9_-]+|@[a-zA-Z0-9._]+))$/', // Updated regex to allow username or URL
        ]);

        $channelQuery = $request->input('channel');
        $maxVideos = 10;

        try {
            // Extract channel ID or username from input
            $channelId = null;
            $username = null;

            if (preg_match('/https:\/\/www\.youtube\.com\/channel\/([a-zA-Z0-9_-]+)/', $channelQuery, $matches)) {
                $channelId = $matches[1]; // Extract channel ID from URL
            } elseif (preg_match('/https:\/\/www\.youtube\.com\/@([a-zA-Z0-9._]+)/', $channelQuery, $matches)) {
                $username = $matches[1]; // Extract username from URL
            } else {
                $username = ltrim($channelQuery, '@'); // Handle direct username input
            }

            // Fetch channel data
            $channelResponse = null;
            if ($channelId) {
                // Try fetching by channel ID
                $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                    'id' => $channelId,
                ]);
            } else {
                // Try fetching by username
                $channelResponse = $this->youtube->channels->listChannels(['snippet', 'statistics', 'brandingSettings'], [
                    'forUsername' => $username,
                ]);
            }

            // Check if channel is found
            if (empty($channelResponse['items'])) {
                Log::warning('Channel not found for query: ' . $channelQuery);
                return response()->json(['error' => 'Channel not found. Please check the channel ID or username.'], 404);
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

            // Sort videos by view count (descending)
            usort($videos, fn($a, $b) => $b['views'] <=> $a['views']);

            return response()->json([
                'channelData' => $channelData,
                'videos' => $videos,
                'channelQuery' => $channelQuery,
            ], 200);

        } catch (\Exception $e) {
            Log::error('YouTube API error for query: ' . $channelQuery, [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to fetch channel data: ' . $e->getMessage()], 500);
        }
    }
}


