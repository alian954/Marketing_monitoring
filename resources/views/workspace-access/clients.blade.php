@extends('layouts.app')

@section('title', '成員客戶範圍')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('team.index') }}">工作區成員</a><span aria-hidden="true">/</span>
        <span aria-current="page">客戶範圍</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">調整客戶範圍</h1>
            <p class="page-description">{{ $membership->memberUser->name }} · {{ $membership->memberUser->email }}</p>
        </div>
    </div>

    <section class="panel spaced-top" aria-labelledby="member-client-scope-title">
        <div class="panel-header">
            <div>
                <h2 id="member-client-scope-title" class="panel-title">可存取客戶</h2>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('team.members.clients.update', $membership) }}" method="POST" class="workspace-access-form" aria-labelledby="member-client-scope-title">
                @csrf
                @method('PATCH')
                <fieldset class="workspace-client-scope-fieldset">
                    <legend class="field-label">範圍</legend>
                    <div class="workspace-role-options">
                        <label class="workspace-role-option" for="member-client-scope-all">
                            <input id="member-client-scope-all" name="client_scope" type="radio" value="all" required @checked(old('client_scope', $membership->client_scope) === \App\Models\WorkspaceMembership::CLIENT_SCOPE_ALL) @if ($errors->has('client_scope')) aria-invalid="true" aria-describedby="member-client-scope-errors" @endif>
                            <span><strong>全部客戶</strong><span class="secondary-text">依工作區權限查看或維護</span></span>
                        </label>
                        <label class="workspace-role-option" for="member-client-scope-selected">
                            <input id="member-client-scope-selected" name="client_scope" type="radio" value="selected" @checked(old('client_scope', $membership->client_scope) === \App\Models\WorkspaceMembership::CLIENT_SCOPE_SELECTED) @if ($errors->has('client_scope') || $errors->has('client_ids')) aria-invalid="true" aria-describedby="member-client-scope-errors" @endif>
                            <span><strong>指定客戶</strong><span class="secondary-text">至少選擇一個客戶</span></span>
                        </label>
                    </div>

                    @php
                        $oldMemberClientIds = old('client_ids', $selectedClientIds);
                        $oldMemberClientIds = is_array($oldMemberClientIds) ? array_map('intval', $oldMemberClientIds) : [];
                    @endphp
                    @if ($clients->isEmpty())
                        <p class="field-help">工作區目前沒有客戶。</p>
                    @else
                        <fieldset class="workspace-client-options">
                            <legend class="sr-only">指定可存取的客戶</legend>
                            @foreach ($clients as $client)
                                <label class="workspace-role-option" for="member-client-{{ $client->id }}">
                                    <input id="member-client-{{ $client->id }}" name="client_ids[]" type="checkbox" value="{{ $client->id }}" @checked(in_array($client->id, $oldMemberClientIds, true)) @if ($errors->has('client_scope') || $errors->has('client_ids')) aria-invalid="true" aria-describedby="member-client-scope-errors" @endif>
                                    <span>{{ $client->name }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @php
                        $clientScopeErrors = collect($errors->getMessages())
                            ->filter(static fn (array $messages, string $field): bool => $field === 'client_scope' || $field === 'client_ids' || str_starts_with($field, 'client_ids.'))
                            ->flatten();
                    @endphp
                    @if ($clientScopeErrors->isNotEmpty())
                        <div id="member-client-scope-errors" class="field-error" role="alert">
                            @foreach ($clientScopeErrors as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
                <div class="row-actions">
                    <button class="button button-primary" type="submit">儲存範圍</button>
                    <a class="button button-secondary" href="{{ route('team.index') }}">取消</a>
                </div>
            </form>
        </div>
    </section>
@endsection
