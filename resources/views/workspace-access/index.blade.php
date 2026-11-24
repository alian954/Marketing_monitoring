@extends('layouts.app')

@section('title', '工作區成員')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">工作區成員</h1>
            <p class="page-description">邀請成員檢視監測與行銷資料。</p>
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
                <h2 id="invite-member-title" class="panel-title">邀請唯讀成員</h2>
                <p class="panel-description">成員可以查看資料，不能修改工作區。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('team.invitations.store') }}" method="POST" class="workspace-access-form">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="invite-email">電子郵件</label>
                    <input class="field-input @error('email') is-invalid @enderror" id="invite-email" name="email" type="email" maxlength="254" value="{{ old('email') }}" autocomplete="email" required @if ($errors->has('email')) aria-invalid="true" aria-describedby="invite-email-error" @endif>
                    @error('email')
                        <p id="invite-email-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <button class="button button-primary" type="submit">建立邀請連結</button>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="workspace-members-title">
        <div class="panel-header">
            <div>
                <h2 id="workspace-members-title" class="panel-title">成員</h2>
                <p class="panel-description">{{ number_format($memberships->count()) }} 位唯讀成員</p>
            </div>
        </div>
        @if ($memberships->isEmpty())
            <div class="panel-body secondary-text">尚無成員</div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">工作區成員與存取權</caption>
                    <thead>
                        <tr>
                            <th scope="col">成員</th>
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
                            <th scope="col">有效期限</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            <tr>
                                <th scope="row">{{ $invitation->email }}</th>
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
