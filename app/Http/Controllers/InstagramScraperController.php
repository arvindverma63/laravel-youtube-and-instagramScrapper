<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Goutte\Client;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class InstagramScraperController extends Controller
{
    protected $client;

    public function __construct()
    {
        // Initialize Goutte with Symfony HttpClient
        $this->client = new Client(HttpClient::create([
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            ],
            // Optional: Add proxy for anti-scraping
            // 'proxy' => 'http://your-proxy:port',
        ]));
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

        try {
            Log::info('Scraping Instagram profile: ' . $username);

            // Scrape profile page
            $crawler = $this->client->request('GET', "https://www.instagram.com/{$username}/");

            if ($crawler->filter('body')->count() === 0) {
                Log::warning('Empty response for Instagram profile: ' . $username);
                return back()->withErrors(['error' => 'Unable to load profile. It may be private or restricted.']);
            }

            // Extract static data
            $profileData = [
                'username' => $username,
                'full_name' => $this->extractText($crawler, 'h1.x1q0g3np', 'Not specified'),
                'bio' => $this->extractText($crawler, 'div.x7a06jk', 'No bio'),
                'followers' => 0,
                'following' => 0,
                'posts' => 0,
                'account_type' => 'Not specified',
                'profile_url' => "https://www.instagram.com/{$username}",
            ];

            Log::info('Extracted username: ' . $username);

            // Extract dynamic data from JSON in script tags
            $jsonData = null;
            $crawler->filter('script[type="application/json"]')->each(function (Crawler $node) use (&$jsonData) {
                if (Str::contains($node->text(), 'edge_followed_by')) {
                    try {
                        $jsonData = json_decode($node->text(), true);
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse JSON script tag', ['error' => $e->getMessage()]);
                    }
                }
            });

            if ($jsonData && isset($jsonData['props']['pageProps']['user'])) {
                $userData = $jsonData['props']['pageProps']['user'];
                $profileData['followers'] = $userData['edge_followed_by']['count'] ?? 0;
                $profileData['following'] = $userData['edge_follow']['count'] ?? 0;
                $profileData['posts'] = $userData['edge_owner_to_timeline_media']['count'] ?? 0;
                $profileData['account_type'] = $userData['is_business_account'] ? 'Business' : 'Personal';
            } else {
                Log::warning('Dynamic data not found in JSON for username: ' . $username);
            }

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

    protected function extractText(Crawler $crawler, string $selector, string $default = ''): string
    {
        try {
            return trim($crawler->filter($selector)->first()->text());
        } catch (\Exception $e) {
            return $default;
        }
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
