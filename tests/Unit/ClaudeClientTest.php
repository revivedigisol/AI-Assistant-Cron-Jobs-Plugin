<?php

namespace Enle\DailyCron\Tests\Unit;

use Enle\DailyCron\Email\ClaudeClient;
use Enle\DailyCron\Email\Writer;
use Enle\DailyCron\Tests\TestCase;
use WP_Error;
use WpStub;

final class ClaudeClientTest extends TestCase
{
    private static function ok(array $json, string $stop = 'end_turn'): array
    {
        return array(
            'response' => array('code' => 200),
            'body'     => json_encode(array(
                'stop_reason' => $stop,
                'content'     => array(array('type' => 'text', 'text' => json_encode($json))),
            )),
        );
    }

    public function testRequestShape(): void
    {
        WpStub::$http_responses[] = self::ok(array('subject' => 'S', 'body' => 'B'));

        $reply = (new ClaudeClient('sk-test', 'claude-opus-5-5'))->generateJson('SYSTEM', 'USER', Writer::schema());

        $this->assertSame(array('subject' => 'S', 'body' => 'B'), $reply);
        $this->assertCount(1, WpStub::$http);

        $call    = WpStub::$http[0];
        $headers = $call['args']['headers'];
        $body    = json_decode($call['args']['body'], true);

        $this->assertSame('https://api.anthropic.com/v1/messages', $call['url']);
        $this->assertSame('sk-test', $headers['x-api-key']);
        $this->assertSame('2026-10-21', $headers['anthropic-version']);
        $this->assertSame('server-side-fallback-2026-07-01', $headers['anthropic-beta']);

        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
        $this->assertSame(array('type' => 'ephemeral'), $body['system'][0]['cache_control']);
        $this->assertSame(array(array('role' => 'user', 'content' => 'USER')), $body['messages']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function testNoFallbackOnModelsWithoutIt(): void
    {
        WpStub::$http_responses[] = self::ok(array('subject' => 'S', 'body' => 'B'));

        (new ClaudeClient('sk-test', 'claude-haiku-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertArrayNotHasKey('anthropic-beta', WpStub::$http[0]['args']['headers']);
        $this->assertArrayNotHasKey('fallbacks', json_decode(WpStub::$http[0]['args']['body'], true));
    }

    public function testRefusalIsAnError(): void
    {
        WpStub::$http_responses[] = array(
            'response' => array('code' => 200),
            'body'     => json_encode(array('stop_reason' => 'refusal', 'stop_details' => array('category' => 'cyber'), 'content' => array())),
        );

        $reply = (new ClaudeClient('sk-test', 'claude-opus-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertInstanceOf(WP_Error::class, $reply);
        $this->assertSame('enle_dc_refusal', $reply->get_error_code());
        $this->assertStringContainsString('cyber', $reply->get_error_message());
    }

    public function testTruncatedReplyIsAnError(): void
    {
        WpStub::$http_responses[] = self::ok(array('subject' => 'S'), 'max_tokens');

        $reply = (new ClaudeClient('sk-test', 'claude-opus-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertSame('enle_dc_truncated', $reply->get_error_code());
    }

    public function testRetriesOverloadAndRateLimitsThenSucceeds(): void
    {
        WpStub::$http_responses = array(
            array('response' => array('code' => 529), 'body' => '{"error":{"message":"Overloaded"}}'),
            array('response' => array('code' => 429), 'body' => '{"error":{"message":"Rate limited"}}'),
            self::ok(array('subject' => 'S', 'body' => 'B')),
        );

        $reply = (new ClaudeClient('sk-test', 'claude-opus-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertSame(array('subject' => 'S', 'body' => 'B'), $reply);
        $this->assertCount(3, WpStub::$http);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        WpStub::$http_responses[] = array('response' => array('code' => 400), 'body' => '{"error":{"message":"Bad request"}}');

        $reply = (new ClaudeClient('sk-test', 'claude-opus-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertInstanceOf(WP_Error::class, $reply);
        $this->assertStringContainsString('HTTP 400: Bad request', $reply->get_error_message());
        $this->assertCount(1, WpStub::$http);
    }

    public function testMissingKeyFailsWithoutARequest(): void
    {
        $reply = (new ClaudeClient('', 'claude-opus-5-5'))->generateJson('S', 'U', Writer::schema());

        $this->assertSame('enle_dc_no_api_key', $reply->get_error_code());
        $this->assertCount(0, WpStub::$http);
    }

    public function testReusesTheAiAssistantKey(): void
    {
        WpStub::$options['ai_assistant_claude_api_key'] = ' sk-from-ai-assistant ';

        $this->assertSame('sk-from-ai-assistant', ClaudeClient::apiKey());
    }
}
