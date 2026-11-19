<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Services\AcceptedStatusCodeList;
use App\Services\DiscordWebhookUrlPolicy;
use App\Services\NotificationEmailRecipientParser;
use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\ProbeException;
use App\Services\Probe\RequestHeaderParser;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\SlackWebhookUrlPolicy;
use App\Services\UrlMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MonitorImportController extends Controller
{
    /** @var list<string> */
    private const REQUIRED_HEADERS = ['name', 'url', 'label'];

    /** @var list<string> */
    private const OPTIONAL_HEADERS = [
        'expected_text',
        'expected_text_mode',
        'expected_response_header',
        'interval_seconds',
        'timeout_seconds',
        'response_time_threshold_ms',
        'accepted_status_codes',
        'request_headers',
        'allowed_redirect_hosts',
        'notification_email',
        'notification_escalation_email',
        'notification_escalation_delay_minutes',
        'notification_escalation_stage_2_email',
        'notification_escalation_stage_2_delay_minutes',
        'notification_slack_webhook',
        'notification_discord_webhook',
        'confirmation_delay_seconds',
    ];

    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
    ) {}

    public function create(): View
    {
        return view('monitors.import');
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS], ',', '"', '');
            fclose($stream);
        }, 'monitor-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $stream = fopen($validated['csv_file']->getRealPath(), 'r');

        if ($stream === false) {
            throw ValidationException::withMessages(['csv_file' => '無法讀取上傳的 CSV 檔案。']);
        }

        $header = fgetcsv($stream, 0, ',', '"', '');

        if (! is_array($header)) {
            fclose($stream);
            throw ValidationException::withMessages(['csv_file' => 'CSV 檔案是空的。請新增標題列：name,url,label。']);
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static fn ($cell): string => mb_strtolower(trim((string) $cell)), $header);
        $optionalHeaders = array_slice($header, count(self::REQUIRED_HEADERS));
        $orderedOptionalHeaders = array_values(array_intersect(self::OPTIONAL_HEADERS, $optionalHeaders));
        $containsExpectedTextModeWithoutText = in_array('expected_text_mode', $optionalHeaders, true)
            && ! in_array('expected_text', $optionalHeaders, true);

        if (
            array_slice($header, 0, count(self::REQUIRED_HEADERS)) !== self::REQUIRED_HEADERS
            || $optionalHeaders !== $orderedOptionalHeaders
            || count($optionalHeaders) !== count(array_unique($optionalHeaders))
            || $containsExpectedTextModeWithoutText
        ) {
            fclose($stream);
            throw ValidationException::withMessages([
                'csv_file' => '標題列必須以 name,url,label 開頭，後續欄位請依序排列：expected_text、expected_text_mode、expected_response_header、interval_seconds、timeout_seconds、response_time_threshold_ms、accepted_status_codes、request_headers、allowed_redirect_hosts、notification_email、notification_escalation_email、notification_escalation_delay_minutes、notification_escalation_stage_2_email、notification_escalation_stage_2_delay_minutes、notification_slack_webhook、notification_discord_webhook、confirmation_delay_seconds；expected_text_mode 需搭配 expected_text。',
            ]);
        }

        $columnIndexes = array_flip($header);
        $existingHashes = Monitor::query()->pluck('url_hash')->flip()->all();
        $seenHashes = [];
        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;

            if (count($rows) >= 500) {
                $rows[] = ['line' => $line, 'name' => '', 'masked_url' => '', 'label' => '', 'eligible' => false, 'duplicate' => false, 'errors' => ['CSV 最多只能有 500 筆資料列。']];
                break;
            }

            $cells = array_pad($cells, count($header), '');
            $name = trim((string) ($cells[0] ?? ''));
            $url = trim((string) ($cells[1] ?? ''));
            $label = trim((string) ($cells[2] ?? ''));
            $expectedText = isset($columnIndexes['expected_text'])
                ? (ExpectedTextMatcher::normalize((string) ($cells[$columnIndexes['expected_text']] ?? '')) ?? '')
                : '';
            $expectedTextMode = isset($columnIndexes['expected_text_mode'])
                ? mb_strtolower(trim((string) ($cells[$columnIndexes['expected_text_mode']] ?? '')))
                : 'present';
            $expectedTextMode = $expectedTextMode === '' ? 'present' : $expectedTextMode;
            $errors = [];
            $duplicate = false;
            $expectedResponseHeader = null;
            $expectedResponseHeaderName = null;
            $intervalSeconds = (int) config('monitoring.interval_seconds');
            $timeoutSeconds = (int) config('monitoring.request_timeout_seconds');
            $timeoutIsValid = true;
            $responseTimeThresholdMs = null;
            $acceptedStatusCodes = [];
            $requestHeaders = [];
            $requestHeaderNames = [];
            $allowedRedirectHosts = [];
            $notificationEmail = null;
            $notificationEmailCount = 0;
            $notificationEscalationEmail = null;
            $notificationEscalationEmailCount = 0;
            $notificationEscalationDelayMinutes = null;
            $notificationEscalationStageTwoEmail = null;
            $notificationEscalationStageTwoEmailCount = 0;
            $notificationEscalationStageTwoDelayMinutes = null;
            $notificationSlackWebhook = null;
            $notificationDiscordWebhook = null;
            $confirmationDelaySeconds = (int) config('monitoring.confirmation_delay_seconds');

            if (isset($columnIndexes['accepted_status_codes'])) {
                try {
                    $acceptedStatusCodes = AcceptedStatusCodeList::normalize(
                        (string) ($cells[$columnIndexes['accepted_status_codes']] ?? ''),
                    );
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (isset($columnIndexes['request_headers'])) {
                try {
                    $requestHeaders = RequestHeaderParser::parse(
                        (string) ($cells[$columnIndexes['request_headers']] ?? ''),
                    );
                    $requestHeaderNames = array_keys($requestHeaders);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (isset($columnIndexes['allowed_redirect_hosts'])) {
                try {
                    $allowedRedirectHosts = $this->urlPolicy->normalizeRedirectHosts(
                        (string) ($cells[$columnIndexes['allowed_redirect_hosts']] ?? ''),
                    );
                } catch (ProbeException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (isset($columnIndexes['notification_email'])) {
                $notificationEmailInput = (string) ($cells[$columnIndexes['notification_email']] ?? '');

                if (mb_strlen($notificationEmailInput) > 2549) {
                    $errors[] = '告警收件信箱清單過長。';
                } else {
                    $notificationEmail = NotificationEmailRecipientParser::normalize($notificationEmailInput);
                    $notificationEmailCount = count(NotificationEmailRecipientParser::parse($notificationEmail));
                    $notificationEmailError = NotificationEmailRecipientParser::validationError($notificationEmail);

                    if ($notificationEmailError !== null) {
                        $errors[] = $notificationEmailError;
                    }
                }
            }

            if (isset($columnIndexes['notification_escalation_email'])) {
                $notificationEscalationEmailInput = (string) ($cells[$columnIndexes['notification_escalation_email']] ?? '');

                if (mb_strlen($notificationEscalationEmailInput) > 2549) {
                    $errors[] = '升級告警收件信箱清單過長。';
                } else {
                    $notificationEscalationEmail = NotificationEmailRecipientParser::normalize($notificationEscalationEmailInput);
                    $notificationEscalationEmailCount = count(NotificationEmailRecipientParser::parse($notificationEscalationEmail));
                    $notificationEscalationEmailError = NotificationEmailRecipientParser::validationError($notificationEscalationEmail);

                    if ($notificationEscalationEmailError !== null) {
                        $errors[] = $notificationEscalationEmailError;
                    }
                }
            }

            if (isset($columnIndexes['notification_escalation_delay_minutes'])) {
                $notificationEscalationDelayMinutes = $this->parseEscalationDelay(
                    (string) ($cells[$columnIndexes['notification_escalation_delay_minutes']] ?? ''),
                    '升級',
                    $errors,
                );
            }

            if (isset($columnIndexes['notification_escalation_stage_2_email'])) {
                $notificationEscalationStageTwoEmailInput = (string) ($cells[$columnIndexes['notification_escalation_stage_2_email']] ?? '');

                if (mb_strlen($notificationEscalationStageTwoEmailInput) > 2549) {
                    $errors[] = '第二階段升級收件信箱清單過長。';
                } else {
                    $notificationEscalationStageTwoEmail = NotificationEmailRecipientParser::normalize($notificationEscalationStageTwoEmailInput);
                    $notificationEscalationStageTwoEmailCount = count(NotificationEmailRecipientParser::parse($notificationEscalationStageTwoEmail));
                    $notificationEscalationStageTwoEmailError = NotificationEmailRecipientParser::validationError($notificationEscalationStageTwoEmail);

                    if ($notificationEscalationStageTwoEmailError !== null) {
                        $errors[] = '第二階段'.$notificationEscalationStageTwoEmailError;
                    }
                }
            }

            if (isset($columnIndexes['notification_escalation_stage_2_delay_minutes'])) {
                $notificationEscalationStageTwoDelayMinutes = $this->parseEscalationDelay(
                    (string) ($cells[$columnIndexes['notification_escalation_stage_2_delay_minutes']] ?? ''),
                    '第二階段升級',
                    $errors,
                );
            }

            if (isset($columnIndexes['notification_slack_webhook'])) {
                $notificationSlackWebhookInput = trim((string) ($cells[$columnIndexes['notification_slack_webhook']] ?? ''));

                if (mb_strlen($notificationSlackWebhookInput) > 2048) {
                    $errors[] = 'Slack webhook 網址不得超過 2048 個字元。';
                } elseif ($notificationSlackWebhookInput !== '' && ! SlackWebhookUrlPolicy::isAllowed($notificationSlackWebhookInput)) {
                    $errors[] = '請使用 hooks.slack.com 或 hooks.slack-gov.com 的 HTTPS Incoming Webhook 網址。';
                } elseif ($notificationSlackWebhookInput !== '') {
                    $notificationSlackWebhook = $notificationSlackWebhookInput;
                }
            }

            if (isset($columnIndexes['notification_discord_webhook'])) {
                $notificationDiscordWebhookInput = trim((string) ($cells[$columnIndexes['notification_discord_webhook']] ?? ''));

                if (mb_strlen($notificationDiscordWebhookInput) > 2048) {
                    $errors[] = 'Discord webhook 網址不得超過 2048 個字元。';
                } elseif ($notificationDiscordWebhookInput !== '' && ! DiscordWebhookUrlPolicy::isAllowed($notificationDiscordWebhookInput)) {
                    $errors[] = '請使用 discord.com 的 Discord Incoming Webhook HTTPS 網址。';
                } elseif ($notificationDiscordWebhookInput !== '') {
                    $notificationDiscordWebhook = $notificationDiscordWebhookInput;
                }
            }

            if (isset($columnIndexes['confirmation_delay_seconds'])) {
                $confirmationDelayInput = trim((string) ($cells[$columnIndexes['confirmation_delay_seconds']] ?? ''));

                if ($confirmationDelayInput !== '') {
                    if (! ctype_digit($confirmationDelayInput)) {
                        $errors[] = '異常確認延遲必須是整數秒。';
                    } else {
                        $parsedConfirmationDelay = (int) $confirmationDelayInput;

                        if ($parsedConfirmationDelay < 30) {
                            $errors[] = '異常確認延遲不得低於 30 秒。';
                        } elseif ($parsedConfirmationDelay > 300) {
                            $errors[] = '異常確認延遲不得高於 300 秒。';
                        } else {
                            $confirmationDelaySeconds = $parsedConfirmationDelay;
                        }
                    }
                }
            }

            if ($notificationEscalationEmail !== null && $notificationEscalationDelayMinutes === null) {
                $errors[] = '設定升級告警收件信箱時，請同時設定等待時間。';
            }

            if ($notificationEscalationEmail === null) {
                $notificationEscalationDelayMinutes = null;
            }

            if ($notificationEscalationStageTwoEmail !== null && $notificationEscalationEmail === null) {
                $errors[] = '設定第二階段時，請先設定第一階段升級收件人。';
            }

            if ($notificationEscalationStageTwoEmail !== null && $notificationEscalationStageTwoDelayMinutes === null) {
                $errors[] = '設定第二階段升級收件人時，請同時設定等待時間。';
            }

            if (
                $notificationEscalationStageTwoEmail !== null
                && $notificationEscalationDelayMinutes !== null
                && $notificationEscalationStageTwoDelayMinutes !== null
                && $notificationEscalationStageTwoDelayMinutes <= $notificationEscalationDelayMinutes
            ) {
                $errors[] = '第二階段等待時間必須晚於第一階段。';
            }

            if ($notificationEscalationStageTwoEmail === null) {
                $notificationEscalationStageTwoDelayMinutes = null;
            }

            if (isset($columnIndexes['expected_response_header'])) {
                try {
                    $expectedResponseHeader = ResponseHeaderCondition::normalizedCondition(
                        (string) ($cells[$columnIndexes['expected_response_header']] ?? ''),
                    );
                    $expectedResponseHeaderName = ResponseHeaderCondition::headerName($expectedResponseHeader);
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (isset($columnIndexes['interval_seconds'])) {
                $intervalInput = trim((string) ($cells[$columnIndexes['interval_seconds']] ?? ''));

                if ($intervalInput !== '') {
                    $parsedInterval = ctype_digit($intervalInput) ? (int) $intervalInput : null;

                    if ($parsedInterval === null || ! in_array($parsedInterval, Monitor::SUPPORTED_INTERVAL_SECONDS, true)) {
                        $errors[] = '檢查間隔只能使用 '.implode('、', Monitor::SUPPORTED_INTERVAL_SECONDS).' 秒。';
                    } else {
                        $intervalSeconds = $parsedInterval;
                    }
                }
            }

            if (isset($columnIndexes['timeout_seconds'])) {
                $timeoutInput = trim((string) ($cells[$columnIndexes['timeout_seconds']] ?? ''));

                if ($timeoutInput !== '') {
                    $parsedTimeout = ctype_digit($timeoutInput) ? (int) $timeoutInput : null;

                    if ($parsedTimeout === null) {
                        $timeoutIsValid = false;
                        $errors[] = '請求逾時秒數必須是整數。';
                    } elseif ($parsedTimeout < 1) {
                        $timeoutIsValid = false;
                        $errors[] = '請求逾時秒數不得低於 1 秒。';
                    } elseif ($parsedTimeout > 15) {
                        $timeoutIsValid = false;
                        $errors[] = '請求逾時秒數不得高於 15 秒。';
                    } else {
                        $timeoutSeconds = $parsedTimeout;
                    }
                }
            }

            if (isset($columnIndexes['response_time_threshold_ms'])) {
                $thresholdInput = trim((string) ($cells[$columnIndexes['response_time_threshold_ms']] ?? ''));

                if ($thresholdInput !== '') {
                    $parsedThreshold = ctype_digit($thresholdInput) ? (int) $thresholdInput : null;

                    if ($parsedThreshold === null) {
                        $errors[] = '回應時間門檻必須是整數。';
                    } elseif ($parsedThreshold < 100) {
                        $errors[] = '回應時間門檻不得低於 100 毫秒。';
                    } elseif ($parsedThreshold > 14000) {
                        $errors[] = '回應時間門檻不得高於 14000 毫秒。';
                    } elseif ($timeoutIsValid && $parsedThreshold >= $timeoutSeconds * 1000) {
                        $errors[] = '回應時間門檻必須低於請求逾時上限（'.($timeoutSeconds * 1000).' 毫秒）。';
                    } else {
                        $responseTimeThresholdMs = $parsedThreshold;
                    }
                }
            }

            if (count($cells) > count($header)) {
                $errors[] = '資料欄位數量與標題列不一致。';
            }

            if ($name === '' || mb_strlen($name) > 120) {
                $errors[] = '名稱為必填欄位，且長度不得超過 120 個字元。';
            }

            if ($url === '' || mb_strlen($url) > 2048) {
                $errors[] = '網址為必填欄位，且長度不得超過 2048 個字元。';
            }

            if (mb_strlen($label) > 100) {
                $errors[] = '標籤長度不得超過 100 個字元。';
            }

            if (mb_strlen($expectedText) > 500) {
                $errors[] = '預期文字不得超過 500 個字元。';
            }

            if (! in_array($expectedTextMode, [
                ExpectedTextMatcher::MODE_PRESENT,
                ExpectedTextMatcher::MODE_PRESENT_ANY,
                ExpectedTextMatcher::MODE_ABSENT,
            ], true)) {
                $errors[] = '預期文字比對方式只能是 present、present_any 或 absent。';
            }

            $normalizedUrl = $url;
            $hash = null;
            $socialPlatform = null;

            if ($errors === []) {
                try {
                    $normalizedUrl = $this->validateUrl($url);
                    $hash = Monitor::fingerprintUrl($normalizedUrl);
                    $socialPlatform = Monitor::socialPlatformForUrl($normalizedUrl);
                } catch (ProbeException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if ($socialPlatform !== null && $expectedText === '') {
                $errors[] = '社群貼文必須提供 expected_text 欄位中的預期文字。';
            }

            if ($socialPlatform !== null && ! in_array($expectedTextMode, [
                ExpectedTextMatcher::MODE_PRESENT,
                ExpectedTextMatcher::MODE_PRESENT_ANY,
            ], true)) {
                $errors[] = '社群貼文的 expected_text_mode 必須是 present 或 present_any。';
            }

            if ($requestHeaders !== [] && mb_strtolower((string) parse_url($normalizedUrl, PHP_URL_SCHEME)) !== 'https') {
                $errors[] = '自訂 HTTP 標頭可能含敏感值，監測網址必須使用 HTTPS。';
            }

            if ($hash !== null && isset($existingHashes[$hash])) {
                $duplicate = true;
                $errors[] = '此網址已在監測中。';
            } elseif ($hash !== null && isset($seenHashes[$hash])) {
                $duplicate = true;
                $errors[] = '此網址在 CSV 檔案中重複。';
            } elseif ($hash !== null && $errors === []) {
                $seenHashes[$hash] = true;
            }

            $rows[] = [
                'line' => $line,
                'name' => $name,
                'url' => $normalizedUrl,
                'masked_url' => $url === '' ? '' : $this->urlMasker->mask($normalizedUrl),
                'label' => $label,
                'expected_text' => $expectedText,
                'expected_text_mode' => $expectedTextMode,
                'expected_response_header' => $expectedResponseHeader,
                'expected_response_header_name' => $expectedResponseHeaderName,
                'interval_seconds' => $intervalSeconds,
                'timeout_seconds' => $timeoutSeconds,
                'response_time_threshold_ms' => $responseTimeThresholdMs,
                'accepted_status_codes' => $acceptedStatusCodes,
                'request_headers' => $requestHeaders,
                'request_header_names' => $requestHeaderNames,
                'allowed_redirect_hosts' => $allowedRedirectHosts,
                'notification_email' => $notificationEmail,
                'notification_email_count' => $notificationEmailCount,
                'notification_escalation_email' => $notificationEscalationEmail,
                'notification_escalation_email_count' => $notificationEscalationEmailCount,
                'notification_escalation_delay_minutes' => $notificationEscalationDelayMinutes,
                'notification_escalation_stage_2_email' => $notificationEscalationStageTwoEmail,
                'notification_escalation_stage_2_email_count' => $notificationEscalationStageTwoEmailCount,
                'notification_escalation_stage_2_delay_minutes' => $notificationEscalationStageTwoDelayMinutes,
                'notification_slack_webhook' => $notificationSlackWebhook,
                'notification_slack_webhook_configured' => $notificationSlackWebhook !== null,
                'notification_discord_webhook' => $notificationDiscordWebhook,
                'notification_discord_webhook_configured' => $notificationDiscordWebhook !== null,
                'confirmation_delay_seconds' => $confirmationDelaySeconds,
                'url_hash' => $hash,
                'eligible' => $errors === [],
                'duplicate' => $duplicate,
                'errors' => $errors,
            ];
        }

        fclose($stream);
        $previewToken = Str::random(40);
        session([
            'monitor_import_preview' => Crypt::encryptString(json_encode($rows, JSON_THROW_ON_ERROR)),
            'monitor_import_token' => $previewToken,
        ]);
        $previewRows = array_map(static function (array $row): array {
            unset(
                $row['expected_response_header'],
                $row['request_headers'],
                $row['notification_email'],
                $row['notification_escalation_email'],
                $row['notification_escalation_stage_2_email'],
                $row['notification_slack_webhook'],
                $row['notification_discord_webhook'],
            );

            return $row;
        }, $rows);

        return view('monitors.import', [
            'previewRows' => $previewRows,
            'previewToken' => $previewToken,
            'validCount' => collect($rows)->where('eligible', true)->count(),
            'invalidCount' => collect($rows)->where('eligible', false)->count(),
        ]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $validated = $request->validate(['preview_token' => ['required', 'string', 'size:40']]);
        $encryptedRows = session('monitor_import_preview');
        $storedToken = session('monitor_import_token');

        if (! is_string($encryptedRows) || ! is_string($storedToken) || ! hash_equals($storedToken, $validated['preview_token'])) {
            return redirect()->route('monitors.import')->withErrors(['file' => '請先預覽 CSV，再確認匯入。']);
        }

        try {
            $rows = json_decode(Crypt::decryptString($encryptedRows), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            session()->forget(['monitor_import_preview', 'monitor_import_token']);

            return redirect()->route('monitors.import')->withErrors(['file' => '預覽已失效，請重新上傳 CSV 檔案。']);
        }

        $added = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! ($row['eligible'] ?? false) || ! isset($row['url'], $row['url_hash'])) {
                $skipped++;

                continue;
            }

            if (Monitor::query()->where('url_hash', $row['url_hash'])->exists()) {
                $skipped++;

                continue;
            }

            $monitor = Monitor::query()->firstOrCreate(['url_hash' => $row['url_hash']], [
                'name' => $row['name'],
                'url' => $row['url'],
                'label' => $row['label'] !== '' ? $row['label'] : null,
                'enabled' => true,
                'config_version' => 1,
                'allowed_redirect_hosts' => $row['allowed_redirect_hosts'] ?? [],
                'accepted_status_codes' => $row['accepted_status_codes'] ?? [],
                'request_headers' => $row['request_headers'] ?? [],
                'notification_email' => $row['notification_email'] ?? null,
                'notification_escalation_email' => $row['notification_escalation_email'] ?? null,
                'notification_escalation_delay_minutes' => $row['notification_escalation_delay_minutes'] ?? null,
                'notification_escalation_stage_2_email' => $row['notification_escalation_stage_2_email'] ?? null,
                'notification_escalation_stage_2_delay_minutes' => $row['notification_escalation_stage_2_delay_minutes'] ?? null,
                'notification_slack_webhook' => $row['notification_slack_webhook'] ?? null,
                'notification_discord_webhook' => $row['notification_discord_webhook'] ?? null,
                'expected_text' => ($row['expected_text'] ?? '') !== '' ? $row['expected_text'] : null,
                'expected_text_mode' => $row['expected_text_mode'] ?? 'present',
                'expected_response_header' => $row['expected_response_header'] ?? null,
                'timeout_seconds' => $row['timeout_seconds'] ?? (int) config('monitoring.request_timeout_seconds'),
                'confirmation_delay_seconds' => $row['confirmation_delay_seconds'] ?? (int) config('monitoring.confirmation_delay_seconds'),
                'interval_seconds' => $row['interval_seconds'] ?? (int) config('monitoring.interval_seconds'),
                'response_time_threshold_ms' => $row['response_time_threshold_ms'] ?? null,
                'next_check_at' => now(),
            ]);

            if ($monitor->wasRecentlyCreated) {
                $added++;
            } else {
                $skipped++;
            }
        }

        session()->forget(['monitor_import_preview', 'monitor_import_token']);

        return redirect()->route('monitors.index')->with('status', '已匯入 '.$added.' 個監測項目；略過 '.$skipped.' 筆無效或重複資料。');
    }

    private function validateUrl(string $url): string
    {
        if (config('monitoring.transport') === 'fixture') {
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

            if (! in_array($host, ['fixture.test', 'healthy.fixture.test'], true) || ! in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new ProbeException('fixture_host_rejected', '離線測試模式只接受 fixture.test 網域。');
            }

            return $url;
        }

        return $this->urlPolicy->validateAndResolve($url)['url'];
    }

    /** @param list<string> $errors */
    private function parseEscalationDelay(string $input, string $label, array &$errors): ?int
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (! ctype_digit($input)) {
            $errors[] = $label.'等待時間必須是整數分鐘。';

            return null;
        }

        $delayMinutes = (int) $input;

        if ($delayMinutes < 5 || $delayMinutes > 1440) {
            $errors[] = $label.'等待時間必須介於 5 至 1440 分鐘。';

            return null;
        }

        return $delayMinutes;
    }
}
