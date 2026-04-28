<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportExcelRequest;
use App\Http\Requests\ResolveDuplicateRequest;
use App\Models\DuplicateReview;
use App\Models\ImportBatch;
use App\Services\DuplicateResolverService;
use App\Services\ImportBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportBatchService $importer,
        private readonly DuplicateResolverService $duplicateResolver,
    ) {}

    /**
     * @throws Throwable
     */
    public function store(ImportExcelRequest $request): JsonResponse
    {
        $report = $this->importer->import(
            $request->file('file'),
            $request->user()?->email
        );

        return response()->json($report, 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        $batch->loadCount([
            'duplicateReviews as duplicates_total',
            'duplicateReviews as duplicates_pending' => fn($query) => $query->where('status', 'pending'),
        ]);

        return response()->json([
            'id' => $batch->id,
            'file_name' => $batch->file_name,
            'imported_by' => $batch->imported_by,
            'status' => $batch->status,
            'error_message' => $batch->error_message,
            'rows_inserted' => $batch->rows_inserted,
            'rows_skipped' => $batch->rows_skipped,
            'rows_flagged' => $batch->rows_flagged,
            'rows_invalid' => $batch->rows_invalid,
            'duplicates_total' => $batch->duplicates_total,
            'duplicates_pending' => $batch->duplicates_pending,
            'created_at' => $batch->created_at,
            'updated_at' => $batch->updated_at,
        ]);
    }

    public function duplicates(Request $request, ImportBatch $batch): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', 50)));

        $paginator = $batch->duplicateReviews()
            ->with('existingItem.carGroup')
            ->latest()
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (DuplicateReview $review): array {
            return [
                'id' => $review->id,
                'batch_id' => $review->batch_id,
                'excel_row' => $review->excel_row,
                'excel_sheet' => $review->excel_sheet,
                'payload' => $review->payload,
                'existing_item_id' => $review->existing_item_id,
                'status' => $review->status,
                'resolved_by' => $review->resolved_by,
                'resolved_at' => $review->resolved_at,
                'existing_item' => $review->existingItem ? [
                    'id' => $review->existingItem->id,
                    'model' => $review->existingItem->model,
                    'serial_code' => $review->existingItem->serial_code,
                    'car_group' => $review->existingItem->carGroup ? [
                        'id' => $review->existingItem->carGroup->id,
                        'name' => $review->existingItem->carGroup->name,
                        'region' => $review->existingItem->carGroup->region,
                        'parent_id' => $review->existingItem->carGroup->parent_id,
                    ] : null,
                ] : null,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * @throws Throwable
     */
    public function resolveDuplicate(ResolveDuplicateRequest $request, DuplicateReview $review): JsonResponse
    {
        if (! $review->isPending()) {
            return response()->json([
                'message' => 'This duplicate review is already resolved.',
            ], 422);
        }

        $this->duplicateResolver->resolve(
            $review,
            (string) $request->input('action'),
            $request->user()?->email ?? 'system@local'
        );

        $review->refresh();

        return response()->json([
            'id' => $review->id,
            'status' => $review->status,
            'resolved_by' => $review->resolved_by,
            'resolved_at' => $review->resolved_at,
        ]);
    }
}
