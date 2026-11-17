@if (session('status'))
    <div class="alert alert-success" role="status">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-error" role="alert" aria-labelledby="validation-error-title">
        <p id="validation-error-title" class="alert-title">請檢查以下內容</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
