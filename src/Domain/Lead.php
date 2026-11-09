<?php

namespace Enle\DailyCron\Domain;

defined('ABSPATH') || exit;

/**
 * One founder going through the Idea-to-Market sequence: who they are, their
 * report, and where they are in the sequence. Times are Unix timestamps (UTC).
 */
final class Lead
{
    public const ACTIVE    = 'active';
    public const STOPPED   = 'stopped';
    public const COMPLETED = 'completed';

    /**
     * @param array<string,string> $report Report sections (summary, offer, icp, positioning, messaging, roadmap, verdict).
     * @param array<string,int> $sent Step key → sent time.
     * @param array<int,string> $skipped Step keys skipped (window passed).
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public int $crm_contact_id,
        public string $report_title,
        public string $report_url,
        public array $report,
        public int $enrolled_at,
        public string $status = self::ACTIVE,
        public string $stop_reason = '',
        public array $sent = array(),
        public array $skipped = array(),
        public int $updated_at = 0,
    ) {
    }

    public function firstName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name), 2);
        $first = is_array($parts) ? (string) $parts[0] : '';

        // ai-assistant's placeholder name for a lead who gave none.
        return $first === '' || strcasecmp($this->name, 'Idea Validation Prospect') === 0 ? '' : $first;
    }

    public function lastSentAt(): int
    {
        return $this->sent === array() ? 0 : max($this->sent);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
