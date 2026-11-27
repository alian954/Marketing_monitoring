@extends('layouts.app')

@section('title', '組織操作紀錄')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">組織操作紀錄</h1>
            <p class="page-description">檢視組織成員、權限與組織設定的異動，以及平台管理員進入組織的紀錄。時間以台灣時間顯示。</p>
        </div>
        <a class="button button-secondary" href="{{ route('team.index') }}">返回組織成員</a>
    </div>

    <section class="panel" aria-labelledby="workspace-activity-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="workspace-activity-title">最近操作</h2>
                <p class="panel-description">紀錄只會新增，不可修改或刪除。</p>
            </div>
        </div>

        @if ($events->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無操作紀錄</h3>
                <p class="empty-copy">邀請成員、調整權限或變更組織設定後，紀錄會顯示於此處。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="workspace-activity-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">操作時間、操作類型、操作者與內容</caption>
                    <thead>
                        <tr>
                            <th scope="col">時間</th>
                            <th scope="col">操作</th>
                            <th scope="col">操作者</th>
                            <th scope="col">內容</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><time class="timestamp" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td><strong>{{ $event->actionLabel() }}</strong></td>
                                <td>
                                    @if ($event->actor === null)
                                        <span class="secondary-text">系統或已刪除帳號</span>
                                    @elseif ($event->actor->isPlatformAdmin())
                                        平台管理員
                                    @else
                                        {{ $event->actor->name }}
                                    @endif
                                </td>
                                <td>
                                    @forelse ($event->detailLines($memberNames) as $line)
                                        <p class="secondary-text">{{ $line }}</p>
                                    @empty
                                        <span class="secondary-text">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($events->hasPages())
                <div class="panel-body pagination-wrap">{{ $events->links('vendor.pagination.monitor') }}</div>
            @endif
        @endif
    </section>
@endsection
