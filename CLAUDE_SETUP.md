# Claude Integration Setup Guide

## Environment Variables

Add the following to your `.env` file:

```env
# Claude OAuth Configuration
CLAUDE_CLIENT_ID=9d1c250a-e61b-44d9-88ed-5944d1962f5e
CLAUDE_CLIENT_SECRET=your_client_secret_here
CLAUDE_REDIRECT_URI=http://localhost:8000/oauth/claude/callback

# Claude API Key (optional - can be set via OAuth)
CLAUDE_API_KEY=your_api_key_here
CLAUDE_API_URL=https://api.anthropic.com/v1
```

## Database Migration (Optional)

If you want to store Claude API keys per user, run this migration:

```sql
ALTER TABLE users ADD COLUMN claude_api_key VARCHAR(255) NULLABLE;
```

Or create a migration:
```bash
php artisan make:migration add_claude_api_key_to_users_table
```

## Usage

### 1. Initiate OAuth Flow
Create a button or link that redirects to:
```
/oauth/claude/initiate
```

This will redirect users to Claude's OAuth consent screen.

### 2. API Endpoint
After authentication, use the chat endpoint:

```javascript
// JavaScript example
const response = await fetch('/api/claude/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
        message: 'Your message here',
        history: [] // Optional: previous messages for context
    })
});

const data = await response.json();
console.log(data.response);
```

### 3. In Livewire Component
```php
<?php

use Livewire\Component;
use App\Services\ClaudeService;

class ChatComponent extends Component
{
    public string $message = '';
    public array $history = [];
    
    public function __construct(private ClaudeService $claudeService)
    {
    }
    
    public function chat()
    {
        $response = $this->claudeService->chat($this->message, $this->history);
        
        $this->history[] = ['role' => 'user', 'content' => $this->message];
        $this->history[] = ['role' => 'assistant', 'content' => $response];
        
        $this->message = '';
    }
    
    public function render()
    {
        return view('livewire.chat');
    }
}
```

## Files Created/Modified

- `app/Services/ClaudeService.php` - Claude API service
- `app/Http/Controllers/ClaudeOAuthController.php` - OAuth handler & chat endpoint
- `config/services.php` - Configuration added
- `routes/web.php` - Routes added:
  - `GET /oauth/claude/initiate` - Start OAuth
  - `GET /oauth/claude/callback` - OAuth callback
  - `POST /api/claude/chat` - Chat API (authenticated)

## Features

✅ OAuth 2.0 integration with Claude  
✅ Secure API key storage  
✅ Chat API endpoint  
✅ Conversation history support  
✅ Error handling  
✅ CSRF protection  

## Next Steps

1. Add your Claude OAuth credentials to `.env`
2. Add a "Connect Claude" button in your UI that links to `/oauth/claude/initiate`
3. Create a chat UI/component to use the `/api/claude/chat` endpoint
4. Test the integration in your app
