<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Goutte\Client;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;

class InstagramScraperController extends Controller
{
    protected $client;

    public function __construct()
    {
        // Initialize Goutte with Symfony HttpClient
        $this->client = new Client(HttpClient::create([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => $this->getRandomUserAgent(),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]));
    }

    protected function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:90.0) Gecko/20100101 Firefox/90.0',
        ];
        return $userAgents[array_rand($userAgents)];
    }

    public function index()
    {
        return view('instagram.index');
    }

    public function search(Request $request)
    {
        $request->validate([
            'username' => 'required|string|regex:/^[a-zA-Z0-9._]+$/',
        ]);

        $username = $request->input('username');
        $maxRetries = 3;
        $retryDelay = 2; // seconds

        try {
            Log::info('Scraping Instagram profile: ' . $username);

            // Scrape profile page with retry logic
            $crawler = null;
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $crawler = $this->client->request('GET', "https://www.instagram.com/{$username}/");
                    break; // Exit loop on success
                } catch (TransportException $e) {
                    if ($attempt === $maxRetries) {
                        Log::error('Max retries reached for username: ' . $username, ['error' => $e->getMessage()]);
                        return back()->withErrors(['error' => 'Failed to fetch profile data after multiple attempts.']);
                    }
                    sleep($retryDelay);
                }
            }

            if ($crawler->filter('body')->count() === 0) {
                Log::warning('Empty response for Instagram profile: ' . $username);
                return back()->withErrors(['error' => 'Unable to load profile. It may be private or restricted.']);
            }

            // Initialize profile data
            $profileData = [
                'username' => $username,
                'full_name' => 'Not specified',
                'bio' => 'No bio',
                'followers' => 0,
                'following' => 0,
                'posts' => 0,
                'account_type' => 'Not specified',
                'profile_url' => "https://www.instagram.com/{$username}",
            ];

            // Extract data from meta tag: name="description"
            $description = $this->extractMetaContent($crawler, 'meta[name="description"]', '');
            if ($description) {
                // Handle meta formats:
                // 1. "150K Followers, 119 Following, 183 Posts - @apixelart on Instagram: "🎨 Amazing pixel art..."
                // 2. "21 Followers, 63 Following, 0 Posts - Arvind (@av36102003) on Instagram: """
                preg_match('/(\d+\.?\d*[kKmM]?)\s*Followers,\s*(\d+\.?\d*[kKmM]?)\s*Following,\s*(\d+\.?\d*[kKmM]?)\s*Posts\s*-\s*(?:([^@]+)\s*\(@([^)]+)\)|@([^)]+))\s*on Instagram:\s*"(.*?)"/ius', $description, $matches);
                if (count($matches) >= 5) {
                    $profileData['followers'] = $this->parseNumber($matches[1]);
                    $profileData['following'] = $this->parseNumber($matches[2]);
                    $profileData['posts'] = $this->parseNumber($matches[3]);

                    // Check if full name is present (matches[4] and matches[5] for format with full name, matches[6] for format without)
                    if (!empty($matches[4]) && !empty($matches[5])) {
                        $profileData['full_name'] = trim($matches[4]);
                        $profileData['username'] = trim($matches[5]);
                    } elseif (!empty($matches[6])) {
                        $profileData['full_name'] = trim($matches[6]); // Use username as full name if no full name
                        $profileData['username'] = trim($matches[6]);
                    }

                    $profileData['bio'] = !empty($matches[7]) ? trim($matches[7]) : 'No bio';
                } else {
                    Log::warning('Failed to parse meta description for username: ' . $username, ['description' => $description]);
                    $profileData['bio'] = $description;
                }
            }

            // Verify username matches input
            if ($profileData['username'] !== $username) {
                Log::warning('Username mismatch', ['input' => $username, 'extracted' => $profileData['username']]);
                $profileData['username'] = $username; // Revert to input username
            }

            // Fallback for account type from JSON
            $jsonData = null;
            $crawler->filter('script[type="application/json"]')->each(function (Crawler $node) use (&$jsonData) {
                if (Str::contains($node->text(), 'is_business_account')) {
                    try {
                        $jsonData = json_decode($node->text(), true);
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse JSON script tag', ['error' => $e->getMessage()]);
                    }
                }
            });
            if ($jsonData && isset($jsonData['props']['pageProps']['user']['is_business_account'])) {
                $profileData['account_type'] = $jsonData['props']['pageProps']['user']['is_business_account'] ? 'Business' : 'Personal';
            }

            Log::info('Extracted profile data for username: ' . $username, $profileData);

            // Store data in session for CSV download
            session(['profileData' => $profileData, 'posts' => []]);

            // Add delay to avoid rate limiting
            sleep(2);

            return view('instagram.index', compact('profileData', 'username'));
        } catch (\Exception $e) {
            Log::error('Instagram scraping error for username: ' . $username, [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to fetch profile data: ' . $e->getMessage()]);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/search-instagram",
     *     summary="Search and scrape public Instagram profile data by username",
     *     description="Scrapes Instagram profile details like name, bio, followers, following, posts, etc. based on the provided username.",
     *     operationId="searchInstagramApi",
     *     tags={"Instagram"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username"},
     *             @OA\Property(
     *                 property="username",
     *                 type="string",
     *                 example="natgeo",
     *                 description="Instagram username to be searched"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Instagram profile data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="username", type="string", example="natgeo"),
     *             @OA\Property(property="profileData", type="object",
     *                 @OA\Property(property="username", type="string", example="natgeo"),
     *                 @OA\Property(property="full_name", type="string", example="National Geographic"),
     *                 @OA\Property(property="bio", type="string", example="Experience the world through the eyes of National Geographic photographers."),
     *                 @OA\Property(property="followers", type="integer", example=28300000),
     *                 @OA\Property(property="following", type="integer", example=134),
     *                 @OA\Property(property="posts", type="integer", example=25000),
     *                 @OA\Property(property="account_type", type="string", example="Business"),
     *                 @OA\Property(property="profile_url", type="string", example="https://www.instagram.com/natgeo")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Instagram scraping failed"
     *     )
     * )
     */

    public function searchInstagramApi(Request $request)
    {
        $request->validate([
            'username' => 'required|string|regex:/^[a-zA-Z0-9._]+$/',
        ]);

        $username = $request->input('username');
        $maxRetries = 3;
        $retryDelay = 2; // seconds

        try {
            Log::info('Scraping Instagram profile: ' . $username);

            // Scrape profile page with retry logic
            $crawler = null;
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $crawler = $this->client->request('GET', "https://www.instagram.com/{$username}/");
                    break; // Exit loop on success
                } catch (TransportException $e) {
                    if ($attempt === $maxRetries) {
                        Log::error('Max retries reached for username: ' . $username, ['error' => $e->getMessage()]);
                        return back()->withErrors(['error' => 'Failed to fetch profile data after multiple attempts.']);
                    }
                    sleep($retryDelay);
                }
            }

            if ($crawler->filter('body')->count() === 0) {
                Log::warning('Empty response for Instagram profile: ' . $username);
                return back()->withErrors(['error' => 'Unable to load profile. It may be private or restricted.']);
            }

            // Initialize profile data
            $profileData = [
                'username' => $username,
                'full_name' => 'Not specified',
                'bio' => 'No bio',
                'followers' => 0,
                'following' => 0,
                'posts' => 0,
                'account_type' => 'Not specified',
                'profile_url' => "https://www.instagram.com/{$username}",
            ];

            // Extract data from meta tag: name="description"
            $description = $this->extractMetaContent($crawler, 'meta[name="description"]', '');
            if ($description) {
                // Handle meta formats:
                // 1. "150K Followers, 119 Following, 183 Posts - @apixelart on Instagram: "🎨 Amazing pixel art..."
                // 2. "21 Followers, 63 Following, 0 Posts - Arvind (@av36102003) on Instagram: """
                preg_match('/(\d+\.?\d*[kKmM]?)\s*Followers,\s*(\d+\.?\d*[kKmM]?)\s*Following,\s*(\d+\.?\d*[kKmM]?)\s*Posts\s*-\s*(?:([^@]+)\s*\(@([^)]+)\)|@([^)]+))\s*on Instagram:\s*"(.*?)"/ius', $description, $matches);
                if (count($matches) >= 5) {
                    $profileData['followers'] = $this->parseNumber($matches[1]);
                    $profileData['following'] = $this->parseNumber($matches[2]);
                    $profileData['posts'] = $this->parseNumber($matches[3]);

                    // Check if full name is present (matches[4] and matches[5] for format with full name, matches[6] for format without)
                    if (!empty($matches[4]) && !empty($matches[5])) {
                        $profileData['full_name'] = trim($matches[4]);
                        $profileData['username'] = trim($matches[5]);
                    } elseif (!empty($matches[6])) {
                        $profileData['full_name'] = trim($matches[6]); // Use username as full name if no full name
                        $profileData['username'] = trim($matches[6]);
                    }

                    $profileData['bio'] = !empty($matches[7]) ? trim($matches[7]) : 'No bio';
                } else {
                    Log::warning('Failed to parse meta description for username: ' . $username, ['description' => $description]);
                    $profileData['bio'] = $description;
                }
            }

            // Verify username matches input
            if ($profileData['username'] !== $username) {
                Log::warning('Username mismatch', ['input' => $username, 'extracted' => $profileData['username']]);
                $profileData['username'] = $username; // Revert to input username
            }

            // Fallback for account type from JSON
            $jsonData = null;
            $crawler->filter('script[type="application/json"]')->each(function (Crawler $node) use (&$jsonData) {
                if (Str::contains($node->text(), 'is_business_account')) {
                    try {
                        $jsonData = json_decode($node->text(), true);
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse JSON script tag', ['error' => $e->getMessage()]);
                    }
                }
            });
            if ($jsonData && isset($jsonData['props']['pageProps']['user']['is_business_account'])) {
                $profileData['account_type'] = $jsonData['props']['pageProps']['user']['is_business_account'] ? 'Business' : 'Personal';
            }

            Log::info('Extracted profile data for username: ' . $username, $profileData);

            // Store data in session for CSV download
            session(['profileData' => $profileData, 'posts' => []]);

            // Add delay to avoid rate limiting
            sleep(2);

            return response()->json(['profileData' => $profileData, 'username' => $username]);
        } catch (\Exception $e) {
            Log::error('Instagram scraping error for username: ' . $username, [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to fetch profile data: ' . $e->getMessage()]);
        }
    }

    protected function extractMetaContent(Crawler $crawler, string $selector, string $default = ''): string
    {
        try {
            return trim($crawler->filter($selector)->first()->attr('content'));
        } catch (\Exception $e) {
            return $default;
        }
    }

    protected function parseNumber(string $number): int
    {
        $number = strtoupper($number);
        if (Str::endsWith($number, 'K')) {
            return (int) (floatval(str_replace('K', '', $number)) * 1000);
        } elseif (Str::endsWith($number, 'M')) {
            return (int) (floatval(str_replace('M', '', $number)) * 1000000);
        }
        return (int) $number;
    }

    public function download(Request $request)
    {
        $profileData = session('profileData');

        if (!$profileData) {
            return redirect()->route('instagram.index')->withErrors(['error' => 'No data available for download. Please perform a search first.']);
        }

        $csvContent = [];
        $csvContent[] = ['Type', 'Username', 'Full Name', 'Bio', 'Followers', 'Following', 'Posts', 'Account Type', 'Profile URL'];

        // Add profile data
        $csvContent[] = [
            'Profile',
            $profileData['username'],
            $profileData['full_name'],
            Str::limit($profileData['bio'], 200),
            number_format($profileData['followers']),
            number_format($profileData['following']),
            number_format($profileData['posts']),
            $profileData['account_type'],
            $profileData['profile_url'],
        ];

        $csvFile = fopen('php://temp', 'w');
        foreach ($csvContent as $row) {
            fputcsv($csvFile, $row);
        }
        rewind($csvFile);
        $csvOutput = stream_get_contents($csvFile);
        fclose($csvFile);

        $filename = Str::slug($profileData['username']) . '_instagram_data_' . now()->format('Ymd_His') . '.csv';

        return Response::make($csvOutput, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
