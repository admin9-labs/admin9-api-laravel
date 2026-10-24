<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Mitoop\Http\JsonResponder;
use Mitoop\Http\Resources\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceResponseContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    #[DataProvider('collectionResponses')]
    public function test_package_resource_collections_preserve_the_success_contract(string $shape, ?array $meta, bool $empty = false): void
    {
        $items = [['id' => 1, 'label' => 'first'], ['id' => 2, 'label' => 'second']];
        $data = match ($shape) {
            'page' => new LengthAwarePaginator([$items[0]], 2, 1),
            'simple' => new Paginator($items, 1),
            'cursor' => new CursorPaginator($items, 1, null, ['parameters' => ['id']]),
            'last_cursor' => new CursorPaginator([$items[0]], 1, new Cursor(['id' => 0]), ['parameters' => ['id']]),
            'empty_cursor' => new CursorPaginator([], 1, null, ['parameters' => ['id']]),
            'empty_page' => new LengthAwarePaginator([], 0, 1),
            'collection' => [$items[0]],
            'empty_collection' => [],
        };
        $resource = new class(null) extends Resource
        {
            /**
             * @return array{id: int, label: string}
             */
            public function toArray(Request $request): array
            {
                return ['id' => $this->resource['id'], 'label' => strtoupper($this->resource['label'])];
            }
        };
        $path = ApiRouting::path('/_test/resource-response');

        Route::middleware('api')->get($path, fn (JsonResponder $responder) => $responder->success($resource::collection($data)));

        $response = $this->getJson($path)->assertOk()->assertHeader('X-Request-Id');
        $requestId = $response->headers->get('X-Request-Id');
        $this->assertIsString($requestId);
        $this->assertNotSame('', $requestId);

        $expected = [
            'success' => true,
            'code' => 0,
            'message' => 'ok',
            'data' => $empty ? [] : [['id' => 1, 'label' => 'FIRST']],
            'request_id' => $requestId,
        ];

        if ($meta !== null) {
            $expected['meta'] = $meta;
        }

        $response->assertExactJson($expected);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>|null, 2?: bool}>
     */
    public static function collectionResponses(): array
    {
        return [
            'page pagination' => ['page', [
                'pagination' => 'page', 'page' => 1, 'page_size' => 1, 'has_more' => true, 'total' => 2,
            ]],
            'simple pagination' => ['simple', [
                'pagination' => 'page', 'page' => 1, 'page_size' => 1, 'has_more' => true,
            ]],
            'cursor pagination' => ['cursor', [
                'pagination' => 'cursor', 'next_cursor' => (new Cursor(['id' => 1], true))->encode(), 'page_size' => 1, 'has_more' => true,
            ]],
            'terminal cursor' => ['last_cursor', [
                'pagination' => 'cursor', 'next_cursor' => '', 'page_size' => 1, 'has_more' => false,
            ]],
            'empty cursor' => ['empty_cursor', [
                'pagination' => 'cursor', 'next_cursor' => '', 'page_size' => 1, 'has_more' => false,
            ], true],
            'empty page' => ['empty_page', [
                'pagination' => 'page', 'page' => 1, 'page_size' => 1, 'has_more' => false, 'total' => 0,
            ], true],
            'unpaginated collection' => ['collection', null],
            'empty unpaginated collection' => ['empty_collection', null, true],
        ];
    }
}
