<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportExcelRequest;
use App\Http\Requests\ResolveDuplicateRequest;
use App\Models\DuplicateReview;
use App\Models\ImportBatch;
use App\Services\DuplicateResolverService;
use App\Services\ImportBatchService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportBatchService $importer,
        private readonly DuplicateResolverService $resolver,
    ) {}

    /**
     * @throws Throwable
     */
    public function store(ImportExcelRequest $request): JsonResponse
    {
        $batch = $this->importer->import(
            file: $request->file('file'),
            importedBy: $request->user()->email,
        );

        return response()->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'rows_inserted' => $batch->rows_inserted,
            'rows_skipped' => $batch->rows_skipped,
            'rows_flagged' => $batch->rows_flagged,
            'rows_invalid' => $batch->rows_invalid,
        ], 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        return response()->json($batch->load('duplicateReviews'));
    }

    public function duplicates(ImportBatch $batch): JsonResponse
    {
        $reviews = $batch->duplicateReviews()
            ->with('existingItem.carGroup')
            ->where('status', 'pending')
            ->paginate(50);

        return response()->json($reviews);
    }

    public function resolveDuplicate(
        ResolveDuplicateRequest $request,
        DuplicateReview $review,
    ): JsonResponse {
        $this->resolver->resolve(
            review: $review,
            action: $request->validated('action'),
            resolvedBy: $request->user()->email,
        );

        return response()->json([
            'id' => $review->id,
            'status' => $review->fresh()->status,
            'resolved_by' => $review->resolved_by,
            'resolved_at' => $review->resolved_at,
        ]);
    }
}
