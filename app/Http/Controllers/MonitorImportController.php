<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\ProbeException;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\UrlMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class MonitorImportController extends Controller
{
    /** @var list<list<string>> */
    private const SUPPORTED_HEADERS = [
        ['name', 'url', 'label'],
        ['name', 'url', 'label', 'expected_text'],
        ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
        ['name', 'url', 'label', 'expected_response_header'],
        ['name', 'url', 'label', 'expected_text', 'expected_response_header'],
        ['name', 'url', 'label', 'expected_text', 'expected_text_mode', 'expected_response_header'],
        ['name', 'url', 'label', 'interval_seconds'],
        ['name', 'url', 'label', 'expected_text', 'interval_seconds'],
        ['name', 'url', 'label', 'expected_text', 'expected_text_mode', 'interval_seconds'],
        ['name', 'url', 'label', 'expected_response_header', 'interval_seconds'],
        ['name', 'url', 'label', 'expected_text', 'expected_response_header', 'interval_seconds'],
        ['name', 'url', 'label', 'expected_text', 'expected_text_mode', 'expected_response_header', 'interval_seconds'],
    ];

    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
    ) {}

    public function create(): View
    {
        return view('monitors.import');
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
            throw ValidationException::withMessages(['csv_file' => 'CSV 檔案是空的。請新增標題列：name,url,label；也可加上 expected_text,expected_text_mode。']);
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static fn ($cell): string => mb_strtolower(trim((string) $cell)), $header);

        if (! in_array($header, self::SUPPORTED_HEADERS, true)) {
            fclose($stream);
            throw ValidationException::withMessages(['csv_file' => '標題列必須依序使用 name,url,label，並可選擇加入 expected_text,expected_text_mode。']);
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
            unset($row['expected_response_header']);

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
                'allowed_redirect_hosts' => [],
                'accepted_status_codes' => [],
                'expected_text' => ($row['expected_text'] ?? '') !== '' ? $row['expected_text'] : null,
                'expected_text_mode' => $row['expected_text_mode'] ?? 'present',
                'expected_response_header' => $row['expected_response_header'] ?? null,
                'timeout_seconds' => (int) config('monitoring.request_timeout_seconds'),
                'confirmation_delay_seconds' => (int) config('monitoring.confirmation_delay_seconds'),
                'interval_seconds' => $row['interval_seconds'] ?? (int) config('monitoring.interval_seconds'),
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
}
