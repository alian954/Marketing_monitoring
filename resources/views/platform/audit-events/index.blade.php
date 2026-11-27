@extends('layouts.app')

@section('title', '稽核紀錄')

@section('content')
    @php($hasFilters = collect($filters)->contains(fn (string $value): bool => $value !== ''))

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform governance</p>
            <h1 class="page-title">稽核紀錄</h1>
            <p class="page-description">追蹤組織、產品、方案、訂閱與成員權限的管理操作。紀錄只會新增，不可修改或刪除。</p>
        </div>
        <a class="button button-secondary" href="{{ route('platform.index') }}">返回總管理平台</a>
    </div>

    <section class="panel" aria-labelledby="audit-event-list-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="audit-event-list-title">操作紀錄</h2>
                <p class="panel-description">共 {{ number_format($events->total()) }} 筆；時間以台灣時間顯示。</p>
            </div>
        </div>

        <div class="panel-body">
            <search>
                <form action="{{ route('platform.audit-events.index') }}" method="GET" aria-label="篩選稽核紀錄">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="audit-action">操作類型</label>
                            <select class="field-select" id="audit-action" name="action">
                                <option value="" @selected($filters['action'] === '')>全部操作</option>
                                @foreach ($actions as $action)
                                    <option value="{{ $action->value }}" @selected($filters['action'] === $action->value)>{{ $action->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="audit-organization">組織</label>
                            <select class="field-select" id="audit-organization" name="organization_id">
                                <option value="" @selected($filters['organization_id'] === '')>全部組織</option>
                                @foreach ($organizations as $organization)
                                    <option value="{{ $organization->id }}" @selected($filters['organization_id'] === (string) $organization->id)>{{ $organization->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="audit-date-from">日期（起）</label>
                            <input class="field-input @error('date_from') is-invalid @enderror" id="audit-date-from" name="date_from" type="date" value="{{ $filters['date_from'] }}" @if ($errors->has('date_from')) aria-invalid="true" aria-describedby="audit-date-from-error" @endif>
                            @error('date_from')
                                <p id="audit-date-from-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="audit-date-to">日期（迄）</label>
                            <input class="field-input @error('date_to') is-invalid @enderror" id="audit-date-to" name="date_to" type="date" value="{{ $filters['date_to'] }}" @if ($errors->has('date_to')) aria-invalid="true" aria-describedby="audit-date-to-error" @endif>
                            @error('date_to')
                                <p id="audit-date-to-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">套用篩選</button>
                        @if ($hasFilters)
                            <a class="strong-link" href="{{ route('platform.audit-events.index') }}">清除篩選</a>
                        @endif
                    </div>
                </form>
            </search>
        </div>

        @if ($events->isEmpty())
            <div class="empty-state">
                @if ($hasFilters)
                    <h3 class="empty-title">找不到符合條件的紀錄</h3>
                    <p class="empty-copy">請調整篩選條件。</p>
                @else
                    <h3 class="empty-title">尚無稽核紀錄</h3>
                    <p class="empty-copy">建立組織、調整產品或管理成員後，操作會顯示於此處。</p>
                @endif
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="audit-event-list-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">操作時間、操作類型、組織、操作者、對象、內容與來源 IP</caption>
                    <thead>
                        <tr>
                            <th scope="col">時間</th>
                            <th scope="col">操作</th>
                            <th scope="col">組織</th>
                            <th scope="col">操作者</th>
                            <th scope="col">內容</th>
                            <th scope="col">來源 IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><time class="timestamp" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td>
                                    <strong>{{ $event->actionLabel() }}</strong>
                                    @if ($event->subject_type !== null)
                                        <p class="secondary-text"><code>{{ $event->subject_type }}#{{ $event->subject_id }}</code></p>
                                    @endif
                                </td>
                                <td>{{ $event->workspace?->organization?->name ?? '平台' }}</td>
                                <td>
                                    @if ($event->actor !== null)
                                        {{ $event->actor->name }}
                                        <p class="secondary-text"><span dir="ltr">{{ $event->actor->email }}</span></p>
                                    @else
                                        <span class="secondary-text">系統或已刪除帳號</span>
                                    @endif
                                </td>
                                <td>
                                    @forelse ($event->detailLines($memberNames) as $line)
                                        <p class="secondary-text">{{ $line }}</p>
                                    @empty
                                        <span class="secondary-text">—</span>
                                    @endforelse
                                </td>
                                <td><span dir="ltr">{{ $event->ip_address ?? '—' }}</span></td>
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
