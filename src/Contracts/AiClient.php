<?php

namespace Enle\DailyCron\Contracts;

use WP_Error;

defined('ABSPATH') || exit;

interface AiClient
{
    /**
     * One request; the reply must be a JSON object matching $schema.
     *
     * @param array<string,mixed> $schema JSON Schema for the reply.
     * @return array<string,mixed>|WP_Error The decoded JSON object.
     */
    public function generateJson(string $system, string $user, array $schema): array|WP_Error;
}
