<?php

namespace App\Services\Tan90\MasterData;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backs the "POST GST verification" special route. Always does a structural
 * GSTIN check; additionally calls the configured provider endpoint when
 * gst.enabled is on in Module Settings. No live provider is wired up in this
 * build (no credentials exist), so a disabled/unreachable provider degrades
 * to the structural result rather than failing the request.
 */
class GstVerificationService
{
    private const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';

    public function __construct(private readonly ModuleSettingsService $settings)
    {
    }

    public function verify(string $gstin): array
    {
        $gstin = strtoupper(trim($gstin));

        if (! preg_match(self::GSTIN_PATTERN, $gstin)) {
            return ['status' => 'failed', 'message' => 'GSTIN does not match the expected 15-character format.'];
        }

        if ($this->settings->groupValues('gst')['enabled'] !== '1') {
            return ['status' => 'pending', 'message' => 'GSTIN format is valid. Live verification is disabled in Module Settings.'];
        }

        return $this->callProvider($gstin);
    }

    private function callProvider(string $gstin): array
    {
        $template = $this->settings->rawValue('gst', 'endpointTemplate');
        $apiKey = $this->settings->rawValue('gst', 'apiKey');
        $timeout = (int) ($this->settings->rawValue('gst', 'timeout') ?: 12);

        if (! $template || ! $apiKey) {
            return ['status' => 'pending', 'message' => 'GSTIN format is valid. Provider endpoint or API key is not configured.'];
        }

        $url = str_replace(['{api_key}', '{gstin}'], [$apiKey, $gstin], $template);

        try {
            $response = Http::timeout($timeout)->get($url);

            return $response->successful()
                ? ['status' => 'verified', 'message' => 'GSTIN verified with the configured provider.']
                : ['status' => 'failed', 'message' => 'Provider rejected the GSTIN (HTTP ' . $response->status() . ').'];
        } catch (Throwable $e) {
            Log::warning('Tan90 GST provider call failed', ['error' => $e->getMessage()]);

            return ['status' => 'pending', 'message' => 'GSTIN format is valid. Provider call failed; try again later.'];
        }
    }
}
