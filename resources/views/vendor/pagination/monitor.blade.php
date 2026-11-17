@if ($paginator->hasPages())
    <nav class="pagination-nav" role="navigation" aria-label="分頁導覽">
        <p class="pagination-info">顯示第 {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} 筆，共 {{ $paginator->total() }} 筆</p>
        <ul class="pagination-list">
            @if ($paginator->onFirstPage())
                <li><span class="pagination-link is-disabled" aria-disabled="true">上一頁</span></li>
            @else
                <li><a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="上一頁">上一頁</a></li>
            @endif

            <li><span class="pagination-current" aria-current="page">第 {{ $paginator->currentPage() }} 頁，共 {{ $paginator->lastPage() }} 頁</span></li>

            @if ($paginator->hasMorePages())
                <li><a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="下一頁">下一頁</a></li>
            @else
                <li><span class="pagination-link is-disabled" aria-disabled="true">下一頁</span></li>
            @endif
        </ul>
    </nav>
@endif
