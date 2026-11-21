<?php

namespace App\Services\Probe;

use Closure;

class NativeHostResolver implements DetailedHostResolver
{
    private const DEFAULT_DNS_TIMEOUT_MILLISECONDS = 5000;

    private const MAX_DNS_TIMEOUT_MILLISECONDS = 5000;

    /** @var Closure(string, int): array<int, array<string, mixed>>|false */
    private readonly Closure $recordLookup;

    /** @param Closure(string, int): array<int, array<string, mixed>>|false|null $recordLookup */
    public function __construct(?Closure $recordLookup = null)
    {
        $this->recordLookup = $recordLookup ?? static fn (string $hostname, int $type): array|false => @dns_get_record($hostname, $type);
    }

    public function resolve(string $hostname): array
    {
        return $this->resolveDetails($hostname)['ips'];
    }

    /** @return array{ips: list<string>, cnames: list<string>, records: list<array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}>} */
    public function resolveDetails(string $hostname, ?int $timeoutMilliseconds = null): array
    {
        $recordType = DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_NS | DNS_TXT;

        if (defined('DNS_CAA')) {
            $recordType |= DNS_CAA;
        }

        $records = $this->lookupRecords($hostname, $recordType, $timeoutMilliseconds);

        if ($records === false) {
            throw new ProbeException('dns_timeout', 'DNS 查詢失敗或逾時。');
        }

        $addresses = [];
        $cnames = [];
        $dnsRecords = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }

            if (strtoupper((string) ($record['type'] ?? '')) === 'CNAME' && is_string($record['target'] ?? null)) {
                $cname = $this->normalizeTarget($record['target']);

                if ($cname !== null && $cname !== '.') {
                    $cnames[] = $cname;
                }
            }

            $dnsRecord = $this->normalizeDnsRecord($record);

            if ($dnsRecord !== null) {
                $dnsRecords[] = $dnsRecord;
            }
        }

        if ($addresses === []) {
            throw new ProbeException('dns_no_address', 'DNS 回應沒有可用的 A/AAAA 位址，可能是名稱不存在、沒有地址記錄，或解析器未提供回答。');
        }

        $dnsRecords = array_values(array_unique($dnsRecords, SORT_REGULAR));
        usort($dnsRecords, static fn (array $left, array $right): int => strcmp($left['type']."\0".$left['value'], $right['type']."\0".$right['value']));

        return [
            'ips' => array_values(array_unique($addresses)),
            'cnames' => array_values(array_unique($cnames)),
            'records' => $dnsRecords,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    private function lookupRecords(string $hostname, int $recordType, ?int $timeoutMilliseconds): array|false
    {
        $timeoutMilliseconds = min(
            self::MAX_DNS_TIMEOUT_MILLISECONDS,
            max(1, $timeoutMilliseconds ?? self::DEFAULT_DNS_TIMEOUT_MILLISECONDS),
        );

        if (! function_exists('pcntl_alarm')
            || ! function_exists('pcntl_async_signals')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
            || ! defined('SIGALRM')) {
            return ($this->recordLookup)($hostname, $recordType);
        }

        $signal = SIGALRM;
        $startedAt = hrtime(true);
        $previousAsyncSignals = pcntl_async_signals();
        $previousHandler = pcntl_signal_get_handler($signal);
        $previousAlarmSeconds = pcntl_alarm(0);
        $dnsAlarmSeconds = max(1, (int) ceil($timeoutMilliseconds / 1000));
        $alarmSeconds = $previousAlarmSeconds > 0
            ? min($dnsAlarmSeconds, $previousAlarmSeconds)
            : $dnsAlarmSeconds;
        $previousAlarmWillFire = $previousAlarmSeconds > 0 && $previousAlarmSeconds <= $dnsAlarmSeconds;
        $previousAlarmFired = false;

        try {
            pcntl_async_signals(true);
            pcntl_signal($signal, function (int $receivedSignal) use ($previousAlarmWillFire, $previousHandler, &$previousAlarmFired): void {
                if ($previousAlarmWillFire) {
                    $previousAlarmFired = true;

                    if (is_callable($previousHandler)) {
                        $previousHandler($receivedSignal);

                        return;
                    }
                }

                throw new ProbeException('dns_timeout', 'DNS 查詢失敗或逾時。');
            });
            pcntl_alarm($alarmSeconds);

            return ($this->recordLookup)($hostname, $recordType);
        } finally {
            pcntl_alarm(0);
            pcntl_signal($signal, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);

            if (! $previousAlarmFired && $previousAlarmSeconds > 0) {
                $elapsedSeconds = (int) ceil((hrtime(true) - $startedAt) / 1_000_000_000);
                $remainingAlarmSeconds = $previousAlarmSeconds - $elapsedSeconds;

                if ($remainingAlarmSeconds > 0) {
                    pcntl_alarm($remainingAlarmSeconds);
                } elseif (is_callable($previousHandler)) {
                    $previousAlarmFired = true;
                    $previousHandler($signal);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{type: 'MX'|'NS'|'TXT'|'CAA', value: string}|null
     */
    private function normalizeDnsRecord(array $record): ?array
    {
        $type = strtoupper((string) ($record['type'] ?? ''));

        if ($type === 'MX' && is_numeric($record['pri'] ?? null) && is_string($record['target'] ?? null)) {
            $target = $this->normalizeTarget($record['target']);

            return $target === null ? null : ['type' => 'MX', 'value' => (int) $record['pri'].' '.$target];
        }

        if ($type === 'NS' && is_string($record['target'] ?? null)) {
            $target = $this->normalizeTarget($record['target']);

            return $target === null ? null : ['type' => 'NS', 'value' => $target];
        }

        if ($type === 'TXT' && is_string($record['txt'] ?? null)) {
            $value = $record['txt'];

            if (! mb_check_encoding($value, 'UTF-8')) {
                $value = 'base64:'.base64_encode($value);
            }

            return ['type' => 'TXT', 'value' => $value];
        }

        if ($type === 'CAA'
            && is_numeric($record['flags'] ?? null)
            && is_string($record['tag'] ?? null)
            && is_string($record['value'] ?? null)) {
            $value = $record['value'];

            if (! mb_check_encoding($value, 'UTF-8')) {
                $value = 'base64:'.base64_encode($value);
            }

            return ['type' => 'CAA', 'value' => (int) $record['flags'].' '.mb_strtolower(trim($record['tag'])).' '.$value];
        }

        return null;
    }

    private function normalizeTarget(string $target): ?string
    {
        $target = trim($target);

        if ($target === '.') {
            return '.';
        }

        $normalizedTarget = mb_strtolower(rtrim($target, '.'));

        return $normalizedTarget === '' ? null : $normalizedTarget;
    }
}
