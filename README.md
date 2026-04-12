# DnD Extreme

AI-Driven Dungeon Master - a Dungeons & Dragons companion app.

## Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12 (API-only) |
| Frontend | React + TypeScript (Vite) |
| Database | MySQL |
| Auth | Laravel Sanctum |
| AI | Claude API / Gemini (swappable) |
| Real-time | Laravel Reverb (planned) |

## Project Structure

```
dndextreme/
├── backend/          # Laravel API
│   ├── app/Services/AI/   # AI provider abstraction
│   ├── config/ai.php      # AI configuration
│   └── routes/api.php     # API routes
├── frontend/         # React SPA
│   ├── src/api/      # API client
│   ├── src/components/
│   └── src/pages/
```

## Setup

### Backend
```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend
```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

### AI Configuration

Set `AI_PROVIDER` in `backend/.env` to `claude` or `gemini`, and provide the corresponding API key:

```env
AI_PROVIDER=claude
ANTHROPIC_API_KEY=your-key-here
GEMINI_API_KEY=your-key-here
```

## Usage

Switch AI providers at runtime via the `AIManager`:

```php
// Use default provider (from .env)
$ai = app(AIProvider::class);
$response = $ai->chat($systemPrompt, $messages);

// Use a specific provider
$ai = app(AIManager::class)->provider('gemini');
$response = $ai->chat($systemPrompt, $messages);
```
