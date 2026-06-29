<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaginationAwareResourceCollection extends AnonymousResourceCollection
{
    public function paginator(): Paginator|CursorPaginator|null
    {
        return $this->resource instanceof Paginator || $this->resource instanceof CursorPaginator
            ? $this->resource
            : null;
    }
}
