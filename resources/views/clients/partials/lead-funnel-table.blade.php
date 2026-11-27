@php
    $money = static fn (?float $amount): string => $amount === null ? '—' : number_format($amount, 0);
    $percent = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1).'%';
@endphp
<div class="data-table-wrap">
    <table class="data-table">
        <caption class="sr-only">各平台名單數、有效名單、成交與各階段成本</caption>
        <thead>
            <tr>
                <th scope="col">來源</th>
                <th scope="col" class="numeric">花費</th>
                <th scope="col" class="numeric">名單</th>
                <th scope="col" class="numeric">有效名單</th>
                <th scope="col" class="numeric">成交</th>
                <th scope="col" class="numeric">每筆名單成本</th>
                <th scope="col" class="numeric">每筆有效名單成本</th>
                <th scope="col" class="numeric">每筆成交成本</th>
                <th scope="col" class="numeric">成交金額 ROAS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([...$funnel['rows'], $funnel['total']] as $row)
                <tr @class(['cross-platform-total-row' => $row['platform'] === 'total'])>
                    <th scope="row">{{ $row['label'] }}</th>
                    <td class="numeric">{{ $money($row['spend']) }}</td>
                    <td class="numeric">{{ number_format($row['leads']) }}</td>
                    <td class="numeric">{{ number_format($row['qualified']) }}<span class="secondary-text">{{ $percent($row['qualified_rate_percent']) }}</span></td>
                    <td class="numeric">{{ number_format($row['won']) }}<span class="secondary-text">{{ $percent($row['win_rate_percent']) }}</span></td>
                    <td class="numeric">{{ $money($row['cost_per_lead']) }}</td>
                    <td class="numeric">{{ $money($row['cost_per_qualified_lead']) }}</td>
                    <td class="numeric">{{ $money($row['cost_per_deal']) }}</td>
                    <td class="numeric">{{ $row['deal_roas'] === null ? '—' : number_format($row['deal_roas'], 2).' 倍' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
