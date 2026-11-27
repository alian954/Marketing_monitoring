<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientLead;
use Illuminate\Support\Facades\DB;

/**
 * Imports leads exported from a CRM or a form tool (HubSpot, Salesforce, Google Sheets, SurveyCake...).
 *
 * Leads are identified by a lead ID column, or otherwise by a hash of the email or phone number,
 * so re-importing an updated export moves leads through the funnel without double counting. The
 * contact details themselves are never stored.
 */
class ClientLeadImporter
{
    public const MAX_ROWS = 20000;

    private const MAX_REPORTED_ERRORS = 20;

    /** @var array<string, list<string>> */
    public const FIELD_ALIASES = [
        'reference' => ['lead id', 'id', 'record id', '名單編號', '名單 id', '客戶編號', '編號', '序號'],
        'email' => ['email', 'e-mail', 'email address', '電子郵件', '信箱', '電子信箱'],
        'phone' => ['phone', 'phone number', 'mobile', '電話', '手機', '手機號碼', '聯絡電話'],
        'date' => ['created date', 'create date', 'created at', 'date', 'lead date', '建立日期', '建立時間', '填單時間', '填寫時間', '名單日期', '日期', '時間戳記'],
        'source' => ['utm source', 'source', 'lead source', 'original source', 'platform', '來源', '名單來源', '廣告來源', '管道', '平台'],
        'campaign' => ['utm campaign', 'campaign', 'campaign name', '活動', '活動名稱', '廣告活動'],
        'status' => ['status', 'lead status', 'stage', 'deal stage', 'lifecycle stage', '狀態', '名單狀態', '階段', '跟進狀態', '銷售階段'],
        'deal_value' => ['deal amount', 'amount', 'deal value', 'revenue', '成交金額', '金額', '訂單金額', '合約金額'],
        'won_date' => ['close date', 'closed date', 'won date', '成交日期', '簽約日期'],
        'gclid' => ['gclid', 'google click id'],
        'currency' => ['currency', '幣別'],
    ];

    /** @var array<string, string> Pattern => platform key, checked in order. */
    private const PLATFORM_PATTERNS = [
        '/google|adwords|gclid|youtube|谷歌/iu' => 'google_ads',
        '/facebook|instagram|meta|\bfb\b|\big\b|臉書/iu' => 'meta_marketing',
        '/tiktok|抖音/iu' => 'tiktok_ads',
        '/\bline\b|line\s*ads|lap/iu' => 'line_ads',
    ];

    /** @var array<string, string> Pattern => stage, checked in order. */
    private const STAGE_PATTERNS = [
        '/成交|簽約|已購買|已付款|closed\s*won|\bwon\b|customer|deal\s*won|成單/iu' => ClientLead::STAGE_WON,
        '/無效|垃圾|重複|錯誤|spam|junk|lost|disqualified|unqualified|closed\s*lost|不合格|失敗/iu' => ClientLead::STAGE_LOST,
        '/有效|合格|qualified|\bmql\b|\bsql\b|已聯繫|已聯絡|預約|洽談|opportunity|contacted|跟進中/iu' => ClientLead::STAGE_QUALIFIED,
    ];

    public function __construct(private readonly MarketingCsvReader $csvReader) {}

    public static function platformFor(string $source): string
    {
        foreach (self::PLATFORM_PATTERNS as $pattern => $platform) {
            if (preg_match($pattern, $source) === 1) {
                return $platform;
            }
        }

        return ClientLead::PLATFORM_OTHER;
    }

    public static function stageFor(string $status, ?float $dealValue): string
    {
        foreach (self::STAGE_PATTERNS as $pattern => $stage) {
            if (preg_match($pattern, $status) === 1) {
                return $stage;
            }
        }

        return $dealValue !== null && $dealValue > 0 ? ClientLead::STAGE_WON : ClientLead::STAGE_NEW;
    }

    /**
     * @return array{imported: int, stages: array<string, int>, errors: list<array{line: int, message: string}>, error_count: int, truncated: bool, from: string|null, to: string|null}
     *
     * @throws MarketingCsvException
     */
    public function import(Client $client, string $path, string $defaultCurrencyCode): array
    {
        $csv = $this->csvReader->read($path, self::FIELD_ALIASES, self::MAX_ROWS);
        $columns = $csv['columns'];

        if (! array_key_exists('date', $columns)) {
            throw new MarketingCsvException('找不到名單建立日期欄位，例如「建立日期」或 created date。');
        }

        if (! array_key_exists('reference', $columns) && ! array_key_exists('email', $columns) && ! array_key_exists('phone', $columns)) {
            throw new MarketingCsvException('需要「名單編號」、Email 或電話其中一個欄位來辨識同一筆名單；Email 與電話只會以雜湊值比對，不會保存。');
        }

        $records = [];
        $errors = [];
        $errorCount = 0;

        foreach ($csv['rows'] as $row) {
            $cells = $row['cells'];
            $reference = $this->reference($cells);
            $createdOn = MarketingCsvReader::date($cells['date']);
            $dealValue = isset($cells['deal_value']) ? MarketingCsvReader::number($cells['deal_value']) : null;
            $currencyCode = strtoupper(trim($cells['currency'] ?? '')) ?: $defaultCurrencyCode;
            $message = match (true) {
                $reference === null => '缺少名單編號、Email 或電話。',
                $createdOn === null => '日期格式無法辨識：'.mb_substr($cells['date'], 0, 30),
                $dealValue !== null && $dealValue < 0 => '成交金額不可為負數。',
                preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1 => '幣別必須是 3 碼英文代碼。',
                default => null,
            };

            if ($message !== null) {
                $errorCount++;

                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = ['line' => $row['line'], 'message' => $message];
                }

                continue;
            }

            $gclid = trim($cells['gclid'] ?? '');
            $source = trim($cells['source'] ?? '');
            $stage = self::stageFor(trim($cells['status'] ?? ''), $dealValue);
            $wonOn = isset($cells['won_date']) ? MarketingCsvReader::date($cells['won_date']) : null;
            $records[$reference] = [
                'lead_reference' => $reference,
                'created_on' => $createdOn,
                'platform' => $source === '' && $gclid !== '' ? 'google_ads' : self::platformFor($source),
                'campaign_name' => mb_substr(trim($cells['campaign'] ?? ''), 0, 191) ?: null,
                'stage' => $stage,
                'deal_value' => $stage === ClientLead::STAGE_WON && $dealValue !== null ? number_format($dealValue, 2, '.', '') : null,
                'won_on' => $stage === ClientLead::STAGE_WON ? ($wonOn ?? $createdOn) : null,
                'currency_code' => $currencyCode,
                'google_click_id' => $gclid === '' ? null : mb_substr($gclid, 0, 255),
            ];
        }

        $imported = 0;

        DB::transaction(function () use ($client, $records, &$imported): void {
            foreach (array_chunk($records, 500, true) as $chunk) {
                $existing = $client->leads()->whereIn('lead_reference', array_keys($chunk))->get()->keyBy('lead_reference');

                foreach ($chunk as $reference => $record) {
                    $lead = $existing->get($reference) ?? $client->leads()->make();
                    $lead->fill($record)->save();
                    $imported++;
                }
            }
        });

        $stages = array_fill_keys(array_keys(ClientLead::STAGE_LABELS), 0);

        foreach ($records as $record) {
            $stages[$record['stage']]++;
        }

        $dates = array_column($records, 'created_on');

        return [
            'imported' => $imported,
            'stages' => $stages,
            'errors' => $errors,
            'error_count' => $errorCount,
            'truncated' => $csv['truncated'],
            'from' => $dates === [] ? null : min($dates),
            'to' => $dates === [] ? null : max($dates),
        ];
    }

    /** @param array<string, string> $cells */
    private function reference(array $cells): ?string
    {
        $id = trim($cells['reference'] ?? '');

        if ($id !== '') {
            return 'id:'.mb_substr($id, 0, 100);
        }

        $email = mb_strtolower(trim($cells['email'] ?? ''));

        if ($email !== '') {
            return 'email:'.hash('sha256', $email);
        }

        $phone = preg_replace('/\D+/', '', $cells['phone'] ?? '') ?? '';

        if (str_starts_with($phone, '886')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone === '' ? null : 'phone:'.hash('sha256', $phone);
    }
}
