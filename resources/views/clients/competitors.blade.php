@extends('layouts.app')

@section('title', $client->name.'｜競品監測')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">競品監測</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜競品監測</h1>
            <p class="page-description">追蹤競爭對手的官網、活動頁或價格頁。頁面文字變更時（例如換檔期、改價格、上新優惠）會依監測項目的通知設定提醒你。標籤為「{{ $tag }}」的監測項目都會列在這裡。</p>
        </div>
        <div class="heading-actions">
            @if ($canManage)
                <a class="button button-primary" href="{{ route('monitors.create', ['client_id' => $client->getKey(), 'preset' => 'competitor']) }}">新增競品頁面</a>
            @endif
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    <section class="panel spaced-top" aria-labelledby="competitor-list-title">
        <div class="panel-header">
            <div>
                <h2 id="competitor-list-title" class="panel-title">競品頁面</h2>
                <p class="panel-description">{{ $rows->count() }} 個頁面，變更紀錄顯示最近 10 次可見文字變更。</p>
            </div>
        </div>
        @if ($rows->isEmpty())
            <p class="empty-copy panel-body">尚未新增競品頁面。新增時會自動加上「{{ $tag }}」標籤並開啟頁面文字變更通知。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">競品頁面的最後檢查時間與最近內容變更</caption>
                    <thead>
                        <tr>
                            <th scope="col">頁面</th>
                            <th scope="col">最後檢查</th>
                            <th scope="col">最近變更</th>
                            <th scope="col">文字變更通知</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $monitor = $row['monitor']; @endphp
                            <tr>
                                <th scope="row"><a href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a></th>
                                <td>{{ $monitor->last_checked_at?->timezone('Asia/Taipei')->format('Y/n/j H:i') ?? '尚未檢查' }}</td>
                                <td>
                                    @forelse (array_slice($row['changes'], 0, 3) as $change)
                                        <time class="secondary-text" datetime="{{ $change['changed_at']->toIso8601String() }}">{{ $change['changed_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @empty
                                        <span class="secondary-text">尚未偵測到變更</span>
                                    @endforelse
                                </td>
                                <td>{{ $monitor->alert_on_visible_text_change ? '已開啟' : '未開啟' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
