<?php

namespace Enle\DailyCron\Email;

use Enle\DailyCron\Contracts\AiClient;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Claude Messages API over wp_remote_post(). Raw HTTP rather than the PHP
 * SDK on purpose: the plugin is deployed by copying files, with no Composer
 * on the server (the same reason ai-assistant calls Claude this way).
 *
 * - Structured output (output_config.format = json_schema), so the reply is
 *   always a JSON object of the requested shape.
 * - Server-side refusal fallback ("fallbacks": "default") on the models that
 *   support it.
 * - The system prompt is the same for every email, so it is marked for
 *   prompt caching.
 * - Retries network errors, 429, 5xx and 529 (3 attempts); other 4xx fail at once.
 */
final class ClaudeClient implements AiClient
{
    public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2026-10-21';
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Models that accept fallbacks: "default". */
    private const FALLBACK_MODELS = array('claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5');

    private const ATTEMPTS = 3;

    public function __construct(
        private string $api_key,
        private string $model,
        private string $effort = 'medium',
        private int $timeout = 120,
    ) {
    }

    /**
     * The key ai-assistant already stores (option ai_assistant_claude_api_key),
     * unless the ENLE_DC_CLAUDE_API_KEY constant overrides it.
     */
    public static function apiKey(): string
    {
        if (defined('ENLE_DC_CLAUDE_API_KEY') && is_string(ENLE_DC_CLAUDE_API_KEY) && ENLE_DC_CLAUDE_API_KEY !== '') {
            return ENLE_DC_CLAUDE_API_KEY;
        }

        return trim((string) get_option('ai_assistant_claude_api_key', ''));
    }

    /** @return array<string,mixed> The request body (public for tests). */
    public function body(string $system, string $user, array $schema): array
    {
        $body = array(
            'model'         => $this->model,
            'max_tokens'    => 16000,
            'output_config' => array(
                'effort' => $this->effort,
                'format' => array('type' => 'json_schema', 'schema' => $schema),
            ),
            'system'        => array(
                array('type' => 'text', 'text' => $system, 'cache_control' => array('type' => 'ephemeral')),
            ),
            'messages'      => array(
                array('role' => 'user', 'content' => $user),
            ),
        );

        if ($this->usesFallback()) {
            $body['fallbacks'] = 'default';
        }

        return $body;
    }

    public function generateJson(string $system, string $user, array $schema): array|WP_Error
    {
        if ($this->api_key === '') {
            return new WP_Error('enle_dc_no_api_key', 'No Claude API key is configured (ai-assistant setting or ENLE_DC_CLAUDE_API_KEY).');
        }

        $headers = array(
            'content-type'      => 'application/json',
            'x-api-key'         => $this->api_key,
            'anthropic-version' => self::API_VERSION,
        );

        if ($this->usesFallback()) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        $args = array(
            'headers' => $headers,
            'body'    => (string) wp_json_encode($this->body($system, $user, $schema)),
            'timeout' => $this->timeout,
        );

        $last_error = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $response = wp_remote_post(self::ENDPOINT, $args);

            if (is_wp_error($response)) {
                $last_error = new WP_Error('enle_dc_http', 'Claude request failed: ' . $response->get_error_message());
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $data = json_decode((string) wp_remote_retrieve_body($response), true);

                if ($code >= 200 && $code < 300) {
                    return self::readReply(is_array($data) ? $data : array());
                }

                $message    = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'no error message';
                $last_error = new WP_Error('enle_dc_api', sprintf('Claude API returned HTTP %d: %s', $code, substr($message, 0, 300)));

                if ($code !== 429 && $code < 500) {
                    return $last_error;
                }
            }

            if ($attempt < self::ATTEMPTS) {
                self::pause($attempt);
            }
        }

        return $last_error;
    }

    /**
     * @param array<string,mixed> $data Decoded API response.
     * @return array<string,mixed>|WP_Error
     */
    public static function readReply(array $data): array|WP_Error
    {
        $stop = (string) ($data['stop_reason'] ?? '');

        // Check the stop reason before reading content.
        if ($stop === 'refusal') {
            $category = isset($data['stop_details']['category']) ? (string) $data['stop_details']['category'] : 'unknown';

            return new WP_Error('enle_dc_refusal', 'Claude declined to write this email (category: ' . $category . ').');
        }

        if ($stop === 'max_tokens') {
            return new WP_Error('enle_dc_truncated', 'Claude\'s reply was cut off at the token limit.');
        }

        foreach ((array) ($data['content'] ?? array()) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $json = json_decode((string) $block['text'], true);

                return is_array($json)
                    ? $json
                    : new WP_Error('enle_dc_bad_json', 'Claude\'s reply was not valid JSON.');
            }
        }

        return new WP_Error('enle_dc_no_text', 'Claude\'s reply had no text.');
    }

    private function usesFallback(): bool
    {
        return in_array($this->model, self::FALLBACK_MODELS, true);
    }

    private static function pause(int $attempt): void
    {
        if (!defined('ENLE_DC_TESTING')) {
            sleep($attempt === 1 ? 2 : 6);
        }
    }
}
