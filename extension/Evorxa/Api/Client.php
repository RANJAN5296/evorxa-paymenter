<?php

namespace Paymenter\Extensions\Servers\Evorxa\Api;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;

/**
 * Evorxa HTTP API client (https://api.evorxa.com/api).
 *
 * - GET requests are retried once on network errors, 429 and 502-504.
 *   Anything that can create or charge is never retried.
 * - With debug logging on (Evorxa > Settings), every call is written to the Laravel log with the token and all passwords masked.
 * - One token is shared by every client panel and Evorxa allows 60 requests/min,
 *   so low-priority reads back off when the remaining budget is nearly gone.
 */
class Client
{
    const MODULE = 'Evorxa';
    const DEFAULT_BASE = 'https://api.evorxa.com/api';
    const VERSION = '1.0.0';

    /** Keep this many requests of the per-minute budget for provisioning and lifecycle calls. */
    const RESERVE = 8;

    private $token;
    private $base;
    private $lastHeaders = [];

    public function __construct($token, $hostname = '')
    {
        $this->token = trim((string) $token);
        $this->base = self::baseFor($hostname);
    }

    public static function baseFor($hostname)
    {
        $host = strtolower(trim((string) $hostname));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim($host, '/');
        if ($host === '' || !preg_match('/^[a-z0-9.-]+(:[0-9]+)?(\/[a-z0-9\/_-]*)?$/', $host)) {
            return self::DEFAULT_BASE;
        }
        if (strpos($host, '/') === false) {
            $host .= '/api';
        }
        return 'https://' . $host;
    }

    /** Client for a Paymenter server record (Admin > Servers, extension "Evorxa"). */
    public static function forServer($server)
    {
        if (!$server) {
            throw new ApiException('No Evorxa server configured. Add one under Admin > Servers (extension "Evorxa").');
        }
        $settings = [];
        foreach ($server->settings as $setting) {
            $settings[$setting->key] = $setting->value;
        }
        return self::fromConfig($settings);
    }

    /** Client from the extension config array (keys: host, token). */
    public static function fromConfig(array $config)
    {
        if (empty($config['token'])) {
            throw new ApiException('No Evorxa API token: add it to your Evorxa server under Admin > Servers.');
        }
        return new self($config['token'], isset($config['host']) ? $config['host'] : '');
    }

    /** First Evorxa server, used by the scheduler and the admin pages. */
    public static function forDefaultServer()
    {
        return self::forServer(self::defaultServer());
    }

    public static function defaultServer()
    {
        return Server::where('extension', self::MODULE)->orderBy('id')->first();
    }

    /** Token id is the part before "|" in Sanctum-style tokens. */
    public function tokenId()
    {
        $pos = strpos($this->token, '|');
        return $pos === false ? null : (int) substr($this->token, 0, $pos);
    }

    public function get($path, array $query = [], array $opts = [])
    {
        return $this->request('GET', $path, $query, null, $opts);
    }

    /** Unauthenticated GET for the public catalog endpoints. */
    public function getPublic($path, array $query = [])
    {
        return $this->request('GET', $path, $query, null, ['public' => true]);
    }

    public function post($path, array $body = [], array $opts = [])
    {
        return $this->request('POST', $path, [], $body, $opts);
    }

    public function put($path, array $body = [], array $opts = [])
    {
        return $this->request('PUT', $path, [], $body, $opts);
    }

    public function delete($path, array $body = [], array $opts = [])
    {
        return $this->request('DELETE', $path, [], $body, $opts);
    }

    public function lastHeader($name)
    {
        $name = strtolower($name);
        return isset($this->lastHeaders[$name]) ? $this->lastHeaders[$name] : null;
    }

    /**
     * @param array $opts timeout (s), low (bool: back off when the rate budget is low), log (bool)
     */
    private function request($method, $path, array $query, $body, array $opts)
    {
        $public = !empty($opts['public']);
        if ($this->token === '' && !$public) {
            throw new ApiException('The Evorxa API token is empty.');
        }
        if (!empty($opts['low'])) {
            $this->guardBudget();
        }

        $url = $this->base . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $payload = null;
        if ($method !== 'GET') {
            $payload = json_encode($body ? $body : new \stdClass());
        }
        $timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
        $attempts = $method === 'GET' ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            list($status, $raw, $error) = $this->send($method, $url, $payload, $timeout, $public);
            $retryable = $status === 0 || in_array($status, [429, 502, 503, 504], true);
            if ($attempt < $attempts && $retryable) {
                $wait = (int) $this->lastHeader('retry-after');
                usleep(1000000 * max(1, min(3, $wait ?: 1)));
                continue;
            }
            break;
        }

        $decoded = null;
        if ($raw !== '' && $raw !== null) {
            $decoded = json_decode($raw, true);
        }
        $this->log($method . ' ' . $path, $query ? $query : $body, $status, $decoded !== null ? $decoded : $raw, $error);
        $this->trackBudget();

        if ($status === 0) {
            throw new ApiException($error ?: 'no response', 0);
        }
        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])
                ? $decoded['error']
                : 'HTTP ' . $status;
            throw new ApiException($message, $status, $decoded);
        }
        if ($status === 204 || $raw === '' || $raw === null) {
            return [];
        }
        if (!is_array($decoded)) {
            throw new ApiException('Unexpected response from Evorxa (not JSON).', $status, $raw);
        }
        return $decoded;
    }

    private function send($method, $url, $payload, $timeout, $public = false)
    {
        $this->lastHeaders = [];
        $headers = [
            'Accept: application/json',
            'User-Agent: Evorxa-Paymenter/' . self::VERSION,
        ];
        if (!$public) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $this->lastHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $error = $raw === false ? curl_error($ch) : '';
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $raw === false ? '' : $raw, $error];
    }

    /** Remember a nearly exhausted rate budget so other requests can back off. */
    private function trackBudget()
    {
        $remaining = $this->lastHeader('x-ratelimit-remaining');
        if ($remaining === null) {
            return;
        }
        $remaining = (int) $remaining;
        if ($remaining <= self::RESERVE + 5) {
            Cache::set('ratelimit', ['remaining' => $remaining, 'at' => time()], 60);
        } elseif (Cache::get('ratelimit') !== null) {
            Cache::delete('ratelimit');
        }
    }

    private function guardBudget()
    {
        $state = Cache::get('ratelimit');
        if (is_array($state) && $state['remaining'] <= self::RESERVE && time() - (int) $state['at'] < 60) {
            throw new ApiException('Busy, retry shortly', 429);
        }
    }

    private function log($action, $request, $status, $response, $error)
    {
        $debug = false;
        try {
            $debug = \Paymenter\Extensions\Servers\Evorxa\Support\Settings::get('debug') === '1';
        } catch (\Throwable $e) {
        }
        if (!$debug && $status > 0 && $status < 500) {
            return;
        }
        $response = self::mask($response);
        $text = is_array($response) ? json_encode($response, JSON_UNESCAPED_SLASHES) : (string) $response;
        if ($error) {
            $text = 'cURL error: ' . $error;
        }
        $line = 'Evorxa API ' . $action . ' -> HTTP ' . $status;
        $context = ['request' => self::mask($request), 'response' => substr(str_replace($this->token, '***', $text), 0, 4000)];
        $status >= 500 || $status === 0 ? Log::warning($line, $context) : Log::debug($line, $context);
    }

    /** Recursively blank values whose key looks secret. */
    public static function mask($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|license_key|webhook|public_key/i', $key)) {
                $data[$key] = $value === null || $value === '' ? $value : '***';
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            }
        }
        return $data;
    }
}
