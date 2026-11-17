<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Services\Probe\ProbeException;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\UrlMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class MonitorImportController extends Controller
{
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
            throw ValidationException::withMessages(['csv_file' => 'CSV 檔案是空的。請新增標題列：name,url,label。']);
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static fn ($cell): string => mb_strtolower(trim((string) $cell)), $header);

        if ($header !== ['name', 'url', 'label']) {
            fclose($stream);
            throw ValidationException::withMessages(['csv_file' => '第一列必須完全符合：name,url,label。']);
        }

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

            $cells = array_pad($cells, 3, '');
            $name = trim((string) ($cells[0] ?? ''));
            $url = trim((string) ($cells[1] ?? ''));
            $label = trim((string) ($cells[2] ?? ''));
            $errors = [];
            $duplicate = false;

            if (count($cells) > 3) {
                $errors[] = '每筆資料只能有 name、url、label 三個欄位。';
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

            $normalizedUrl = $url;
            $hash = null;

            if ($errors === []) {
                try {
                    $normalizedUrl = $this->validateUrl($url);
                    $hash = Monitor::fingerprintUrl($normalizedUrl);

                    if (isset($existingHashes[$hash])) {
                        $duplicate = true;
                        $errors[] = '此網址已在監測中。';
                    } elseif (isset($seenHashes[$hash])) {
                        $duplicate = true;
                        $errors[] = '此網址在 CSV 檔案中重複。';
                    } else {
                        $seenHashes[$hash] = true;
                    }
                } catch (ProbeException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            $rows[] = [
                'line' => $line,
                'name' => $name,
                'url' => $normalizedUrl,
                'masked_url' => $url === '' ? '' : $this->urlMasker->mask($normalizedUrl),
                'label' => $label,
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

        return view('monitors.import', [
            'previewRows' => $rows,
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
                'expected_text' => null,
                'interval_seconds' => (int) config('monitoring.interval_seconds'),
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
