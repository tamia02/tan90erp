<?php

namespace App\Http\Controllers;

use App\Services\ClaudeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClaudeOAuthController extends Controller
{
    public function __construct(private ClaudeService $claudeService)
    {
    }

    /**
     * Handle the OAuth callback from Claude
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (!$code) {
            return redirect('/')->with('error', 'Missing authorization code from Claude');
        }

        // Verify state for CSRF protection (optional but recommended)
        $storedState = session('claude_oauth_state');
        if ($state && $storedState && $state !== $storedState) {
            return redirect('/')->with('error', 'Invalid state parameter');
        }

        // Exchange code for API key
        $apiKey = $this->claudeService->exchangeCodeForApiKey($code);

        if (!$apiKey) {
            return redirect('/')->with('error', 'Failed to authenticate with Claude');
        }

        // Store API key in user's session or database
        if (Auth::check()) {
            Auth::user()->update(['claude_api_key' => $apiKey]);
        } else {
            session(['claude_api_key' => $apiKey]);
        }

        return redirect('/chat')->with('success', 'Successfully connected to Claude!');
    }

    /**
     * Initiate OAuth flow
     */
    public function initiate()
    {
        $state = bin2hex(random_bytes(16));
        session(['claude_oauth_state' => $state]);

        $clientId = config('services.claude.client_id');
        $redirectUri = config('services.claude.redirect_uri');
        $scopes = 'user:profile user:inference user:sessions:claude_code';

        $authUrl = "https://claude.ai/oauth/authorize?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
        ]);

        return redirect($authUrl);
    }

    /**
     * Chat API endpoint
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'nullable|array',
        ]);

        try {
            $response = $this->claudeService->chat(
                $request->input('message'),
                $request->input('history', [])
            );

            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
