<?php

namespace Enle\DailyCron\Contracts;

use WP_Error;

defined('ABSPATH') || exit;

interface Mailer
{
    /**
     * @param array<int,string> $headers Extra headers ("Name: value").
     * @return true|WP_Error
     */
    public function send(string $to, string $subject, string $html, array $headers = array()): bool|WP_Error;
}
