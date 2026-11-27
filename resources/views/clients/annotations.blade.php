@extends('layouts.app')

@section('title', $client->name.'｜行銷日曆')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $selectedCategory = old('category', \App\Models\ClientMarketingAnnotation::CATEGORY_BUDGET);
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">行銷日曆</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜行銷日曆</h1>
            <p class="page-description">記錄預算調整、素材上線、檔期與網站改版。註記會和網站監測事件、行銷警示一起標在跨平台總覽的每日花費圖上，方便解讀數字變化。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.cross-platform', $client) }}">跨平台總覽</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @if ($canManage)
        <section class="panel spaced-top" aria-labelledby="create-annotation-title">
            <div class="panel-header">
                <h2 id="create-annotation-title" class="panel-title">新增註記</h2>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.annotations.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="create-annotation-title">
                    @csrf
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="annotation-occurred-on">日期</label>
                            <input class="field-input{{ $errors->has('occurred_on') ? ' is-invalid' : '' }}" id="annotation-occurred-on" name="occurred_on" type="date" value="{{ old('occurred_on', $today) }}" required @if ($errors->has('occurred_on')) aria-invalid="true" aria-describedby="annotation-occurred-on-error" @endif>
                            @error('occurred_on')<p id="annotation-occurred-on-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="annotation-category">類型</label>
                            <select class="field-input{{ $errors->has('category') ? ' is-invalid' : '' }}" id="annotation-category" name="category" required @if ($errors->has('category')) aria-invalid="true" aria-describedby="annotation-category-error" @endif>
                                @foreach (\App\Models\ClientMarketingAnnotation::CATEGORIES as $category => $label)
                                    <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('category')<p id="annotation-category-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="annotation-title">標題</label>
                        <input class="field-input{{ $errors->has('title') ? ' is-invalid' : '' }}" id="annotation-title" name="title" type="text" maxlength="120" value="{{ old('title') }}" required placeholder="例：Meta 日預算由 3,000 調高至 5,000" @if ($errors->has('title')) aria-invalid="true" aria-describedby="annotation-title-error" @endif>
                        @error('title')<p id="annotation-title-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="annotation-note">說明（選填）</label>
                        <textarea class="field-textarea{{ $errors->has('note') ? ' is-invalid' : '' }}" id="annotation-note" name="note" rows="3" maxlength="2000" @if ($errors->has('note')) aria-invalid="true" aria-describedby="annotation-note-error" @endif>{{ old('note') }}</textarea>
                        @error('note')<p id="annotation-note-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">新增註記</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="annotation-list-title">
        <div class="panel-header">
            <div>
                <h2 id="annotation-list-title" class="panel-title">註記</h2>
                <p class="panel-description">共 {{ number_format($totalCount) }} 則，顯示最近 {{ number_format($annotations->count()) }} 則。</p>
            </div>
        </div>
        @if ($annotations->isEmpty())
            <p class="empty-copy panel-body">尚未新增註記。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">行銷註記的日期、類型、內容與建立者</caption>
                    <thead>
                        <tr>
                            <th scope="col">日期</th>
                            <th scope="col">類型</th>
                            <th scope="col">內容</th>
                            <th scope="col">建立者</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($annotations as $annotation)
                            <tr>
                                <td><time datetime="{{ $annotation->occurred_on->toDateString() }}">{{ $annotation->occurred_on->format('Y/n/j') }}</time></td>
                                <td>{{ $annotation->categoryLabel() }}</td>
                                <th scope="row">
                                    {{ $annotation->title }}
                                    @if ($annotation->note !== null)
                                        <span class="secondary-text annotation-note">{{ $annotation->note }}</span>
                                    @endif
                                </th>
                                <td>{{ $annotation->createdBy?->name ?? '—' }}</td>
                                <td>
                                    @if ($canManage)
                                        <form action="{{ route('clients.annotations.destroy', [$client, $annotation]) }}" method="POST" class="inline-form">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-quiet button-small" type="submit" aria-label="刪除註記：{{ $annotation->title }}">刪除</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
