<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class LinkedInScraperController extends Controller
{
    public function index()
    {
        return view('linkedin.index');
    }

    public function redirectToLinkedIn()
    {
        $clientId = config('services.linkedin.client_id');
        $redirectUri = config('services.linkedin.redirect_uri');
        $scopes = 'r_liteprofile r_emailaddress'; // Basic profile and email
        $state = Str::random(16);

        session(['linkedin_state' => $state]);

        $authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $state,
        ]);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        $state = $request->query('state');
        $code = $request->query('code');
        $storedState = session('linkedin_state');

        if ($state !== $storedState) {
            Log::error('LinkedIn OAuth state mismatch', ['state' => $state, 'stored_state' => $storedState]);
            return redirect()->route('linkedin.index')->withErrors(['error' => 'State mismatch. Please try again.']);
        }

        try {
            // Exchange code for access token
            $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.linkedin.redirect_uri'),
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
            ]);

            if ($response->failed()) {
                Log::error('LinkedIn OAuth token request failed', ['response' => $response->json()]);
                return redirect()->route('linkedin.index')->withErrors(['error' => 'Failed to obtain access token.']);
            }

            $accessToken = $response->json()['access_token'];

            // Fetch profile data
            $profileResponse = Http::withHeaders([
                'Authorization' => "Bearer $accessToken",
                'LinkedIn-Version' => '20220401', // Required for v2 API
            ])->get('https://api.linkedin.com/v2/me?projection=(id,firstName,lastName,headline,profilePicture(displayImage~:playableStreams),positions,location(name))');

            if ($profileResponse->failed()) {
                Log::error('LinkedIn API profile request failed', ['response' => $profileResponse->json()]);
                return redirect()->route('linkedin.index')->withErrors(['error' => 'Failed to fetch profile data.']);
            }

            $profileData = $profileResponse->json();

            // Process profile data
            $profile = [
                'name' => $profileData['firstName']['localized']['en_US'] . ' ' . $profileData['lastName']['localized']['en_US'],
                'headline' => $profileData['headline']['localized']['en_US'] ?? 'No headline',
                'location' => $profileData['location']['name']['value'] ?? 'Not specified',
                'current_position' => $profileData['positions']['elements'][0]['title'] ?? 'Not specified',
                'company' => $profileData['positions']['elements'][0]['companyName'] ?? 'Not specified',
                'profile_url' => 'https://www.linkedin.com/in/' . ($profileData['vanityName'] ?? $profileData['id']),
            ];

            // Process experiences (limited to top 2)
            $experiences = [];
            foreach (array_slice($profileData['positions']['elements'] ?? [], 0, 2) as $position) {
                $experiences[] = [
                    'title' => $position['title'] ?? 'Untitled',
                    'company' => $position['companyName'] ?? 'Unknown',
                    'duration' => ($position['startDate']['month'] ?? 'Unknown') . '/' . ($position['startDate']['year'] ?? 'Unknown') . ' - ' . ($position['endDate'] ? ($position['endDate']['month'] . '/' . $position['endDate']['year']) : 'Present'),
                    'description' => $position['description'] ?? 'No description',
                ];
            }

            // Store data in session for CSV download
            session(['profileData' => $profile, 'experiences' => $experiences]);

            return view('linkedin.index', compact('profileData', 'experiences'));
        } catch (\Exception $e) {
            Log::error('LinkedIn API error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->route('linkedin.index')->withErrors(['error' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function download(Request $request)
    {
        $profileData = session('profileData');
        $experiences = session('experiences');

        if (!$profileData || !$experiences) {
            return redirect()->route('linkedin.index')->withErrors(['error' => 'No data available for download. Please perform a search first.']);
        }

        $csvContent = [];
        $csvContent[] = ['Type', 'Name', 'Headline', 'Location', 'Current Position', 'Company', 'Profile URL', 'Experience Title', 'Experience Company', 'Experience Duration', 'Experience Description'];

        // Add profile data
        $csvContent[] = [
            'Profile',
            $profileData['name'],
            $profileData['headline'],
            $profileData['location'],
            $profileData['current_position'],
            $profileData['company'],
            $profileData['profile_url'],
            '', '', '', ''
        ];

        // Add experience data
        foreach ($experiences as $exp) {
            $csvContent[] = [
                'Experience',
                $profileData['name'],
                '', '', '', '',
                $profileData['profile_url'],
                $exp['title'],
                $exp['company'],
                $exp['duration'],
                Str::limit($exp['description'], 200),
            ];
        }

        $csvFile = fopen('php://temp', 'w');
        foreach ($csvContent as $row) {
            fputcsv($csvFile, $row);
        }
        rewind($csvFile);
        $csvOutput = stream_get_contents($csvFile);
        fclose($csvFile);

        $filename = Str::slug($profileData['name']) . '_linkedin_data_' . now()->format('Ymd_His') . '.csv';

        return Response::make($csvOutput, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
