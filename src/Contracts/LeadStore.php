<?php

namespace Enle\DailyCron\Contracts;

use Enle\DailyCron\Domain\Lead;

defined('ABSPATH') || exit;

interface LeadStore
{
    /** Saves a new lead (id 0) or updates an existing one; returns the id. */
    public function save(Lead $lead): int;

    public function find(int $id): ?Lead;

    /** The newest lead for this email, any status. */
    public function findLatestByEmail(string $email): ?Lead;

    /** @return array<int,Lead> Active leads, oldest enrolment first. */
    public function active(int $limit): array;

    /** @return array<int,Lead> Newest first, for the admin page. */
    public function recent(int $limit): array;

    /**
     * @param string $action sent|previewed|skipped|failed|stopped|enrolled|updated|completed
     */
    public function log(int $lead_id, string $step, string $action, string $detail = '', string $subject = '', string $body = ''): void;

    /** @return array<int,array<string,mixed>> Newest first. */
    public function recentLog(int $limit): array;

    /** Deletes finished leads (and their log) not touched since $before. Returns rows deleted. */
    public function purgeFinished(int $before): int;
}
