<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportExcelRequest;
use App\Services\ImportBatchService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ImportController extends Controller
{
    private ImportBatchService $importer;

    public function __construct(ImportBatchService $importer)
    {
        $this->importer = $importer;
    }

    /**
     * @throws Throwable
     */
    public function store(ImportExcelRequest $request): JsonResponse
    {
        $report = $this->importer->import($request->file('file'));

        return response()->json($report, 201);
    }
}
