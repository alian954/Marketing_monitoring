@extends('layouts.app')

@section('title', '組織成員與權限')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">組織成員與權限</h1>
            <p class="page-description">邀請組織人員，並設定其資料範圍與維護權限。席次 {{ number_format($occupiedSeatCount) }} / {{ $seatLimit === null ? '不限' : number_format($seatLimit) }}，含待接受邀請。</p>
        </div>
    </div>

    @if (session('createdInviteUrl'))
        <section class="panel spaced-top" aria-labelledby="created-invitation-title">
            <div class="panel-header">
                <div>
                    <h2 id="created-invitation-title" class="panel-title">邀請連結已建立</h2>
                    <p class="panel-description">複製連結傳給對方；7 天後失效。</p>
                </div>
            </div>
            <div class="panel-body">
                <label class="field-label" for="created-invitation-url">邀請連結</label>
                <input class="field-input" id="created-invitation-url" type="url" value="{{ session('createdInviteUrl') }}" readonly>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="invite-member-title">
        <div class="panel-header">
            <div>
                <h2 id="invite-member-title" class="panel-title">邀請成員</h2>
                <p class="panel-description">唯讀適合客戶查看；可編輯適合同事維護監測活動。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('team.invitations.store') }}" method="POST" class="workspace-access-form" aria-labelledby="invite-member-title">
                @csrf
                <div class="workspace-access-fields">
                    <div class="field-group">
                        <label class="field-label" for="invite-email">電子郵件</label>
                        <input class="field-input @error('email') is-invalid @enderror" id="invite-email" name="email" type="email" maxlength="254" value="{{ old('email') }}" autocomplete="email" required @if ($errors->has('email')) aria-invalid="true" aria-describedby="invite-email-error" @endif>
                        @error('email')
                            <p id="invite-email-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <fieldset class="workspace-role-fieldset @error('role') is-invalid @enderror" @if ($errors->has('role')) aria-describedby="invite-role-error" @endif>
                    <legend class="field-label">組織權限</legend>
                        <div class="workspace-role-options">
                            <label class="workspace-role-option" for="invite-role-read-only">
                                <input id="invite-role-read-only" name="role" type="radio" value="read_only" @checked(old('role', \App\Models\WorkspaceMembership::ROLE_READ_ONLY) === \App\Models\WorkspaceMembership::ROLE_READ_ONLY) @if ($errors->has('role')) aria-invalid="true" aria-describedby="invite-role-error" @endif>
                                <span>
                                    <strong>唯讀</strong>
                                    <span class="secondary-text">可查看監測與行銷資料</span>
                                </span>
                            </label>
                            <label class="workspace-role-option" for="invite-role-editor">
                                <input id="invite-role-editor" name="role" type="radio" value="editor" @checked(old('role') === \App\Models\WorkspaceMembership::ROLE_EDITOR) @if ($errors->has('role')) aria-invalid="true" aria-describedby="invite-role-error" @endif>
                                <span>
                                    <strong>可編輯</strong>
                                    <span class="secondary-text">可維護監測項目與活動</span>
                                </span>
                            </label>
                        </div>
                        @error('role')
                            <p id="invite-role-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </div>
                @php
                    $invitationClientScopeErrors = collect($errors->getMessages())
                        ->filter(static fn (array $messages, string $field): bool => $field === 'client_scope' || $field === 'client_ids' || str_starts_with($field, 'client_ids.'))
                        ->flatten();
                @endphp
                <fieldset class="workspace-client-scope-fieldset" @if ($invitationClientScopeErrors->isNotEmpty()) aria-describedby="invite-client-scope-errors" @endif>
                    <legend class="field-label">客戶範圍</legend>
                    <div class="workspace-role-options">
                        <label class="workspace-role-option" for="invite-client-scope-all">
                            <input id="invite-client-scope-all" name="client_scope" type="radio" value="all" required @checked(old('client_scope', \App\Models\WorkspaceMembership::CLIENT_SCOPE_ALL) === \App\Models\WorkspaceMembership::CLIENT_SCOPE_ALL) @if ($invitationClientScopeErrors->isNotEmpty()) aria-invalid="true" aria-describedby="invite-client-scope-errors" @endif>
                            <span><strong>全部客戶</strong><span class="secondary-text">依組織權限查看或維護</span></span>
                        </label>
                        <label class="workspace-role-option" for="invite-client-scope-selected">
                            <input id="invite-client-scope-selected" name="client_scope" type="radio" value="selected" @checked(old('client_scope') === \App\Models\WorkspaceMembership::CLIENT_SCOPE_SELECTED) @if ($invitationClientScopeErrors->isNotEmpty()) aria-invalid="true" aria-describedby="invite-client-scope-errors" @endif>
                            <span><strong>指定客戶</strong><span class="secondary-text">至少勾選一個客戶</span></span>
                        </label>
                    </div>
                    @php
                        $oldInvitationClientIds = old('client_ids', []);
                        $oldInvitationClientIds = is_array($oldInvitationClientIds) ? array_map('intval', $oldInvitationClientIds) : [];
                    @endphp
                    @if ($clients->isEmpty())
                        <p class="field-help">尚無客戶，請先建立客戶後再指定範圍。</p>
                    @else
                        <fieldset class="workspace-client-options">
                            <legend class="sr-only">指定可存取的客戶</legend>
                            @foreach ($clients as $client)
                                <label class="workspace-role-option" for="invite-client-{{ $client->id }}">
                                    <input id="invite-client-{{ $client->id }}" name="client_ids[]" type="checkbox" value="{{ $client->id }}" @checked(in_array($client->id, $oldInvitationClientIds, true)) @if ($invitationClientScopeErrors->isNotEmpty()) aria-invalid="true" aria-describedby="invite-client-scope-errors" @endif>
                                    <span>{{ $client->name }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                    @if ($invitationClientScopeErrors->isNotEmpty())
                        <div id="invite-client-scope-errors" class="field-error" role="alert">
                            @foreach ($invitationClientScopeErrors as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
                <button class="button button-primary" type="submit">建立邀請連結</button>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="workspace-members-title">
        <div class="panel-header">
            <div>
                <h2 id="workspace-members-title" class="panel-title">組織成員</h2>
                <p class="panel-description">{{ number_format($memberships->count()) }} 位成員</p>
            </div>
        </div>
        @if ($memberships->isEmpty())
            <div class="panel-body secondary-text">尚無成員</div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">組織成員與存取權</caption>
                    <thead>
                        <tr>
                            <th scope="col">成員</th>
                            <th scope="col">權限</th>
                            <th scope="col">客戶範圍</th>
                            <th scope="col">加入時間</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($memberships as $membership)
                            <tr>
                                <th scope="row">
                                    {{ $membership->memberUser->name }}
                                    <span class="secondary-text">{{ $membership->memberUser->email }}</span>
                                </th>
                                <td>
                                    <form action="{{ route('team.members.role.update', $membership) }}" method="POST" class="inline-form workspace-member-role-form" aria-label="更新 {{ $membership->memberUser->name }} 的存取權">
                                        @csrf
                                        @method('PATCH')
                                        <label class="sr-only" for="member-role-{{ $membership->id }}">{{ $membership->memberUser->name }} 的存取權</label>
                                        <select class="field-select" id="member-role-{{ $membership->id }}" name="role">
                                            <option value="{{ \App\Models\WorkspaceMembership::ROLE_READ_ONLY }}" @selected($membership->role === \App\Models\WorkspaceMembership::ROLE_READ_ONLY)>唯讀</option>
                                            <option value="{{ \App\Models\WorkspaceMembership::ROLE_EDITOR }}" @selected($membership->role === \App\Models\WorkspaceMembership::ROLE_EDITOR)>可編輯</option>
                                        </select>
                                        <button class="button button-quiet button-small" type="submit">更新權限</button>
                                    </form>
                                </td>
                                <td>
                                    {{ $membership->clientScopeLabel() }}
                                    <a class="strong-link" href="{{ route('team.members.clients.edit', $membership) }}">調整</a>
                                </td>
                                <td><time datetime="{{ $membership->accepted_at->toIso8601String() }}">{{ $membership->accepted_at->timezone('Asia/Taipei')->format('Y/n/j') }}</time></td>
                                <td>
                                    <form action="{{ route('team.members.destroy', $membership) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-quiet button-small" type="submit">撤銷存取</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="pending-invitations-title">
        <div class="panel-header">
            <div>
                <h2 id="pending-invitations-title" class="panel-title">待接受邀請</h2>
                <p class="panel-description">{{ number_format($invitations->count()) }} 個有效連結</p>
            </div>
        </div>
        @if ($invitations->isEmpty())
            <div class="panel-body secondary-text">沒有待接受的邀請</div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">待接受邀請及到期時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">電子郵件</th>
                            <th scope="col">權限</th>
                            <th scope="col">客戶範圍</th>
                            <th scope="col">有效期限</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            <tr>
                                <th scope="row">{{ $invitation->email }}</th>
                                <td>{{ $invitation->roleLabel() }}</td>
                                <td>{{ $invitation->clientScopeLabel() }}</td>
                                <td><time datetime="{{ $invitation->expires_at->toIso8601String() }}">{{ $invitation->expires_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td>
                                    <form action="{{ route('team.invitations.destroy', $invitation) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-quiet button-small" type="submit">撤銷邀請</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
