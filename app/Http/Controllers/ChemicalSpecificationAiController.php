<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Chemicals\Services\ChemicalSpecificationAiService;
use App\Http\Requests\GenerateAiSpecificationRequest;
use Illuminate\Http\JsonResponse;

final class ChemicalSpecificationAiController extends Controller
{
    public function generate(
        GenerateAiSpecificationRequest $request,
        ChemicalSpecificationAiService $aiService,
    ): JsonResponse {
        $this->authorize('create', \App\Models\Item::class);

        $nameTh = (string) $request->input('name_th', '');
        $nameEn = $request->filled('name_en') ? (string) $request->input('name_en') : null;
        $casNo = $request->filled('cas_no') ? (string) $request->input('cas_no') : null;
        $formula = $request->filled('formula') ? (string) $request->input('formula') : null;

        $specification = $aiService->generateSpecification(
            nameTh: $nameTh,
            nameEn: $nameEn,
            casNo: $casNo,
            formula: $formula,
        );

        if ($specification === null) {
            return response()->json([
                'success' => false,
                'message' => __('items.ai_spec_failed'),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'specification' => $specification,
        ]);
    }
}
