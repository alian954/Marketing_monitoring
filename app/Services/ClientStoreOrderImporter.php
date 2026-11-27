<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientStoreOrder;

/**
 * Imports store orders exported as CSV from Shopify, WooCommerce, SHOPLINE, 91APP, CYBERBIZ or a
 * hand-made sheet.
 *
 * Files with an order number column are stored per order, so importing an overlapping export again
 * updates orders instead of double counting. Files without one are treated as daily totals.
 */
class ClientStoreOrderImporter
{
    public const MAX_ROWS = 20000;

    private const MAX_REPORTED_ERRORS = 20;

    /** @var array<string, list<string>> */
    public const FIELD_ALIASES = [
        'date' => ['date', 'day', '日期', '訂單日期', '下單日期', '訂單時間', '下單時間', '建立時間', '成立時間', 'created at', 'order date', 'ordered at', 'paid at', '付款時間'],
        'order_reference' => ['order id', 'order number', 'order no', 'order', '訂單編號', '訂單號碼', '訂單號', '訂單 id', 'number'],
        'revenue' => ['revenue', 'total', 'amount', 'total price', 'order total', 'sales', 'net sales', 'total sales', '營收', '金額', '訂單金額', '總金額', '訂單總額', '訂單總金額', '實付金額', '付款金額', '銷售額', '銷售金額', '營業額'],
        'orders_count' => ['orders', 'order count', 'orders count', '訂單數', '訂單量', '訂單筆數'],
        'new_customer' => ['is new customer', 'new customer', 'first order', 'customer type', '新客', '新顧客', '首購', '是否新客', '顧客類型', '客戶類型'],
        'new_customers' => ['new customers', 'new customer orders', '新客數', '新顧客數', '首購數', '新客訂單數'],
        'currency' => ['currency', 'currency code', '幣別', '貨幣'],
        'status' => ['status', 'order status', 'financial status', 'payment status', '訂單狀態', '付款狀態', '狀態'],
    ];

    private const CANCELLED_STATUS_PATTERN = '/cancel|refund|void|取消|退款|退貨|作廢|失敗|failed/iu';

    public function __construct(private readonly MarketingCsvReader $csvReader) {}

    /**
     * @return array{mode: string, imported: int, skipped_cancelled: int, errors: list<array{line: int, message: string}>, error_count: int, truncated: bool, from: string|null, to: string|null, revenue_by_currency: array<string, float>}
     *
     * @throws MarketingCsvException
     */
    public function import(Client $client, string $path, string $defaultCurrencyCode, ?string $sourceLabel): array
    {
        $csv = $this->csvReader->read($path, self::FIELD_ALIASES, self::MAX_ROWS);
        $missing = array_diff(['date', 'revenue'], array_keys($csv['columns']));

        if ($missing !== []) {
            throw new MarketingCsvException('找不到'.(in_array('date', $missing, true) ? '日期' : '金額').'欄位。請確認標題列包含「訂單日期」與「訂單金額」（或 date、total 等常見名稱）。');
        }

        $mode = array_key_exists('order_reference', $csv['columns']) ? 'orders' : 'daily';
        $records = [];
        $errors = [];
        $errorCount = 0;
        $skippedCancelled = 0;
        $addError = static function (int $line, string $message) use (&$errors, &$errorCount): void {
            $errorCount++;

            if (count($errors) < self::MAX_REPORTED_ERRORS) {
                $errors[] = ['line' => $line, 'message' => $message];
            }
        };

        foreach ($csv['rows'] as $row) {
            $cells = $row['cells'];

            if (isset($cells['status']) && $cells['status'] !== '' && preg_match(self::CANCELLED_STATUS_PATTERN, $cells['status']) === 1) {
                $skippedCancelled++;

                continue;
            }

            $date = MarketingCsvReader::date($cells['date']);
            $revenue = MarketingCsvReader::number($cells['revenue']);

            if ($mode === 'orders' && $revenue === null && array_key_exists('order:'.mb_substr(trim($cells['order_reference']), 0, 100), $records)) {
                // Shopify-style exports repeat the order on every line item and leave totals blank after the first.
                continue;
            }
            $currencyCode = strtoupper(trim($cells['currency'] ?? '')) ?: $defaultCurrencyCode;

            if ($date === null) {
                $addError($row['line'], '日期格式無法辨識：'.mb_substr($cells['date'], 0, 30));

                continue;
            }

            if ($revenue === null || $revenue < 0) {
                $addError($row['line'], '金額無效：'.mb_substr($cells['revenue'], 0, 30));

                continue;
            }

            if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
                $addError($row['line'], '幣別必須是 3 碼英文代碼。');

                continue;
            }

            if ($mode === 'orders') {
                $reference = mb_substr(trim($cells['order_reference']), 0, 100);

                if ($reference === '') {
                    $addError($row['line'], '缺少訂單編號。');

                    continue;
                }

                $isNewCustomer = isset($cells['new_customer']) ? MarketingCsvReader::boolean($cells['new_customer']) : null;
                $key = 'order:'.$reference;
                $records[$key] = [
                    'order_reference' => $key,
                    'ordered_on' => $date,
                    'currency_code' => $currencyCode,
                    'revenue' => $revenue,
                    'orders_count' => 1,
                    'new_customer_orders' => $isNewCustomer === null ? null : (int) $isNewCustomer,
                ];

                continue;
            }

            $ordersCount = isset($cells['orders_count']) ? MarketingCsvReader::number($cells['orders_count']) : null;
            $newCustomers = isset($cells['new_customers']) ? MarketingCsvReader::number($cells['new_customers']) : null;
            $key = 'daily:'.$date.':'.$currencyCode.':'.mb_substr((string) $sourceLabel, 0, 40);
            $existing = $records[$key] ?? [
                'order_reference' => $key,
                'ordered_on' => $date,
                'currency_code' => $currencyCode,
                'revenue' => 0.0,
                'orders_count' => 0,
                'new_customer_orders' => null,
            ];
            $existing['revenue'] += $revenue;
            $existing['orders_count'] += $ordersCount === null ? 1 : max(0, (int) round($ordersCount));

            if ($newCustomers !== null) {
                $existing['new_customer_orders'] = (int) ($existing['new_customer_orders'] ?? 0) + max(0, (int) round($newCustomers));
            }

            $records[$key] = $existing;
        }

        $now = now();
        $rows = array_map(static fn (array $record): array => $record + [
            'client_id' => $client->getKey(),
            'revenue' => number_format($record['revenue'], 2, '.', ''),
            'source_label' => $sourceLabel,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($records));

        foreach (array_chunk($rows, 500) as $chunk) {
            ClientStoreOrder::query()->upsert(
                $chunk,
                ['client_id', 'order_reference'],
                ['ordered_on', 'currency_code', 'revenue', 'orders_count', 'new_customer_orders', 'source_label', 'updated_at'],
            );
        }

        $dates = array_column($rows, 'ordered_on');
        $revenueByCurrency = [];

        foreach ($records as $record) {
            $revenueByCurrency[$record['currency_code']] = round(($revenueByCurrency[$record['currency_code']] ?? 0.0) + $record['revenue'], 2);
        }

        ksort($revenueByCurrency);

        return [
            'mode' => $mode,
            'imported' => count($rows),
            'skipped_cancelled' => $skippedCancelled,
            'errors' => $errors,
            'error_count' => $errorCount,
            'truncated' => $csv['truncated'],
            'from' => $dates === [] ? null : min($dates),
            'to' => $dates === [] ? null : max($dates),
            'revenue_by_currency' => $revenueByCurrency,
        ];
    }
}
