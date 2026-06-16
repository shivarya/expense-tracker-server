<?php

/**
 * AIClient
 *
 * Provider-agnostic chat-completion client for OpenAI-compatible APIs. Lets the
 * server call a cheap/free model (Google Gemini Flash-Lite by default) without
 * touching call sites — the provider is chosen entirely via env.
 *
 * Provider selection (first match wins):
 *   AI_PROVIDER = gemini | openai | groq | azure | openai_compatible
 *   else AZURE_OPENAI_ENDPOINT set -> azure   (preserves legacy behavior)
 *   else GEMINI_API_KEY set        -> gemini
 *   else OPENAI_API_KEY set        -> openai
 *   else GROQ_API_KEY set          -> groq
 *
 * Env per provider:
 *   gemini:            GEMINI_API_KEY,  AI_MODEL (default gemini-2.0-flash-lite)
 *   openai:            OPENAI_API_KEY,  AI_MODEL (default gpt-4o-mini)
 *   groq:              GROQ_API_KEY,    AI_MODEL (default llama-3.3-70b-versatile)
 *   azure:             AZURE_OPENAI_ENDPOINT, AZURE_OPENAI_API_KEY,
 *                      AZURE_OPENAI_DEPLOYMENT, AZURE_OPENAI_API_VERSION
 *   openai_compatible: AI_BASE_URL, AI_API_KEY, AI_MODEL
 *
 * AI_BASE_URL may override the default base for gemini/openai/groq too.
 */
class AIClient
{
    private string $provider = '';
    private string $url = '';
    private string $model = '';
    private array $headers = [];
    private bool $configured = false;
    private bool $useMaxCompletionTokens = false;

    public function __construct()
    {
        $provider = strtolower($this->env('AI_PROVIDER'));

        if ($provider === '') {
            if ($this->env('AZURE_OPENAI_ENDPOINT') !== '') {
                $provider = 'azure';
            } elseif ($this->env('GEMINI_API_KEY') !== '') {
                $provider = 'gemini';
            } elseif ($this->env('OPENAI_API_KEY') !== '') {
                $provider = 'openai';
            } elseif ($this->env('GROQ_API_KEY') !== '') {
                $provider = 'groq';
            } else {
                return; // stays unconfigured
            }
        }

        $this->provider = $provider;
        $this->configure();
    }

    private function configure(): void
    {
        switch ($this->provider) {
            case 'azure':
                $endpoint = rtrim($this->env('AZURE_OPENAI_ENDPOINT'), '/');
                $deployment = $this->env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4-turbo');
                $apiVersion = $this->env('AZURE_OPENAI_API_VERSION', '2024-02-15-preview');
                $key = $this->env('AZURE_OPENAI_API_KEY');
                if ($endpoint === '' || $key === '') {
                    return;
                }
                $this->url = "{$endpoint}/openai/deployments/{$deployment}/chat/completions?api-version={$apiVersion}";
                $this->model = $deployment;
                $this->headers = ['Content-Type: application/json', 'api-key: ' . $key];
                $this->useMaxCompletionTokens = true;
                $this->configured = true;
                break;

            case 'gemini':
                $this->configureBearer(
                    'https://generativelanguage.googleapis.com/v1beta/openai',
                    $this->env('GEMINI_API_KEY', $this->env('AI_API_KEY')),
                    'gemini-2.0-flash-lite'
                );
                break;

            case 'openai':
                $this->configureBearer(
                    'https://api.openai.com/v1',
                    $this->env('OPENAI_API_KEY', $this->env('AI_API_KEY')),
                    'gpt-4o-mini'
                );
                break;

            case 'groq':
                $this->configureBearer(
                    'https://api.groq.com/openai/v1',
                    $this->env('GROQ_API_KEY', $this->env('AI_API_KEY')),
                    'llama-3.3-70b-versatile'
                );
                break;

            case 'openai_compatible':
            default:
                $this->configureBearer($this->env('AI_BASE_URL'), $this->env('AI_API_KEY'), 'gpt-4o-mini');
                break;
        }
    }

    private function configureBearer(string $defaultBase, string $key, string $defaultModel): void
    {
        $base = rtrim($this->env('AI_BASE_URL', $defaultBase), '/');
        if ($base === '' || $key === '') {
            return;
        }
        $this->url = $base . '/chat/completions';
        $this->model = $this->env('AI_MODEL', $defaultModel);
        $this->headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        $this->configured = true;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Run a chat completion. In JSON mode, returns the assistant message content
     * decoded as an associative array, or null on any failure.
     */
    public function chatCompletion(array $messages, float $temperature = 0.1, bool $jsonMode = true, int $maxRetries = 3): ?array
    {
        if (!$this->configured) {
            error_log('AIClient: no AI provider configured');
            return null;
        }

        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        // Token-cap parameter name differs across providers.
        if ($this->useMaxCompletionTokens) {
            $payload['max_completion_tokens'] = 2000;
        } else {
            $payload['max_tokens'] = 2000;
        }

        // Azure encodes the model in the URL (deployment); others need it in the body.
        if ($this->provider !== 'azure') {
            $payload['model'] = $this->model;
        }

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $attempt = 0;
        $waitTime = 1; // seconds, exponential backoff

        while ($attempt < $maxRetries) {
            $ch = curl_init($this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                error_log("AIClient curl error ({$this->provider}): {$curlErr}");
                $attempt++;
                if ($attempt >= $maxRetries) {
                    return null;
                }
                sleep($waitTime);
                $waitTime *= 2;
                continue;
            }

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $content = $result['choices'][0]['message']['content'] ?? null;
                if ($content === null) {
                    return null;
                }
                $parsed = json_decode($content, true);
                return is_array($parsed) ? $parsed : null;
            }

            // Retry on rate limit / transient server errors.
            if ($httpCode === 429 || $httpCode >= 500) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    error_log("AIClient {$this->provider} HTTP {$httpCode} after {$maxRetries} attempts: " . substr((string)$response, 0, 300));
                    return null;
                }
                sleep($waitTime);
                $waitTime *= 2;
                continue;
            }

            error_log("AIClient {$this->provider} HTTP {$httpCode}: " . substr((string)$response, 0, 300));
            return null;
        }

        return null;
    }

    /**
     * Read an env var (getenv, falling back to $_ENV), returning $default when
     * unset or empty.
     */
    private function env(string $key, string $default = ''): string
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            $val = (string)($_ENV[$key] ?? '');
        }
        $val = trim((string)$val);
        return $val !== '' ? $val : $default;
    }
}
