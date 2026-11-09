<?php

namespace Enle\DailyCron\Contracts;

defined('ABSPATH') || exit;

interface RunLock
{
    public function acquire(): bool;

    public function release(): void;
}
