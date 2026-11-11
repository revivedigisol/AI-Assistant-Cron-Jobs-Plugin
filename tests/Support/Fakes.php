<?php

namespace Enle\DailyCron\Tests\Support;

use Enle\DailyCron\Contracts\AiClient;
use Enle\DailyCron\Contracts\LeadStore;
use Enle\DailyCron\Contracts\Mailer;
use Enle\DailyCron\Contracts\RunLock;
use Enle\DailyCron\Contracts\Signals;
use Enle\DailyCron\Domain\Lead;
use WP_Error;

final class InMemoryLeadStore implements LeadStore
{
    /** @var array<int,Lead> */
    public array $leads = array();

    /** @var array<int,array<string,mixed>> */
    public array $logRows = array();

    private int $next = 1;

    public function save(Lead $lead): int
    {
        if ($lead->id === 0) {
            $lead->id = $this->next++;
        }

        $this->leads[$lead->id] = clone $lead;

        return $lead->id;
    }

    public function find(int $id): ?Lead
    {
        return isset($this->leads[$id]) ? clone $this->leads[$id] : null;
    }

    public function findLatestByEmail(string $email): ?Lead
    {
        $found = null;
        foreach ($this->leads as $lead) {
            if ($lead->email === $email) {
                $found = clone $lead;
            }
        }

        return $found;
    }

    public function active(int $limit): array
    {
        $out = array();
        foreach ($this->leads as $lead) {
            if ($lead->isActive()) {
                $out[] = clone $lead;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function recent(int $limit, int $offset = 0): array
    {
        return array_slice(array_reverse(array_map(static fn ($l) => clone $l, $this->leads)), max(0, $offset), $limit);
    }

    public function countLeads(): int
    {
        return count($this->leads);
    }

    public function log(int $lead_id, string $step, string $action, string $detail = '', string $subject = '', string $body = ''): void
    {
        $this->logRows[] = compact('lead_id', 'step', 'action', 'detail', 'subject', 'body');
    }

    public function recentLog(int $limit, int $offset = 0): array
    {
        return array_slice(array_reverse($this->logRows), max(0, $offset), $limit);
    }

    public function countLog(): int
    {
        return count($this->logRows);
    }

    public function purgeFinished(int $before): int
    {
        $n = 0;
        foreach ($this->leads as $id => $lead) {
            if (!$lead->isActive() && $lead->updated_at > 0 && $lead->updated_at < $before) {
                unset($this->leads[$id]);
                $n++;
            }
        }

        return $n;
    }

    /** @return array<int,string> */
    public function actions(int $lead_id): array
    {
        return array_values(array_map(
            static fn ($row) => $row['action'] . ($row['step'] !== '' ? ':' . $row['step'] : ''),
            array_filter($this->logRows, static fn ($row) => $row['lead_id'] === $lead_id)
        ));
    }
}

final class FakeSignals implements Signals
{
    /** @var array<string,bool> */
    public array $purchased = array();

    /** @var array<string,int> email => booking time */
    public array $booked = array();

    /** @var array<string,string> */
    public array $status = array();

    /** @var array<string,bool> */
    public array $replied = array();

    /** @var array<int,string> */
    public array $unsubscribed = array();

    /** Email whose checks throw, to simulate a database error. */
    public string $throwFor = '';

    public function hasPurchased(string $email): bool
    {
        if ($email === $this->throwFor) {
            throw new \RuntimeException('Database went away');
        }

        return $this->purchased[$email] ?? false;
    }

    public function hasBookedSince(string $email, int $since): bool
    {
        return isset($this->booked[$email]) && $this->booked[$email] >= $since;
    }

    public function crmStatus(string $email): string
    {
        return $this->status[$email] ?? 'subscribed';
    }

    public function hasRepliedTag(string $email): bool
    {
        return $this->replied[$email] ?? false;
    }

    public function unsubscribeInCrm(string $email): void
    {
        $this->unsubscribed[] = $email;
        $this->status[$email] = 'unsubscribed';
    }
}

/** Returns scripted replies in order (the last one repeats) and records each call. */
final class FakeAi implements AiClient
{
    /** @var array<int,array<string,mixed>|WP_Error> */
    public array $replies = array();

    /** @var array<int,array{system:string,user:string}> */
    public array $calls = array();

    public function generateJson(string $system, string $user, array $schema): array|WP_Error
    {
        $this->calls[] = array('system' => $system, 'user' => $user);

        if ($this->replies === array()) {
            return new WP_Error('no_reply', 'No scripted reply.');
        }

        $index = min(count($this->calls), count($this->replies)) - 1;

        return $this->replies[$index];
    }
}

final class FakeMailer implements Mailer
{
    /** @var array<int,array{to:string,subject:string,html:string,headers:array<int,string>}> */
    public array $sent = array();

    public bool|WP_Error $result = true;

    public function send(string $to, string $subject, string $html, array $headers = array()): bool|WP_Error
    {
        $this->sent[] = compact('to', 'subject', 'html', 'headers');

        return $this->result;
    }
}

final class FakeLock implements RunLock
{
    public bool $free = true;

    public int $released = 0;

    public function acquire(): bool
    {
        return $this->free;
    }

    public function release(): void
    {
        $this->released++;
    }
}
