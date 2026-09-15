<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Chemicals\Services\PubChemClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only PubChem (NIH) lookup — backs both the item-create form's "auto-fill"
 * button and the standalone procurement lookup page (`/chemicals/lookup`). Gated on
 * a bare `item.view` permission (no dedicated Policy — same reasoning as
 * `ReportController`: this isn't authorizing access to a specific Eloquent resource,
 * just a read-only external-data lookup any item.view holder may use).
 */
final class ChemicalLookupController extends Controller
{
    public function lookup(Request $request, PubChemClient $client): JsonResponse
    {
        $this->authorize('item.view');

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'by' => ['required', 'string', 'in:cas,name'],
        ]);

        $result = $validated['by'] === 'cas'
            ? $client->lookupByCas($validated['query'])
            : $client->lookupByName($validated['query']);

        if ($result === null) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'cid' => $result->cid,
            'title' => $result->title,
            'molecular_formula' => $result->molecularFormula,
            'molecular_weight' => $result->molecularWeight,
            'iupac_name' => $result->iupacName,
            'signal_word' => $result->signalWord,
            'ghs_codes' => $result->ghsCodes,
            'h_statements' => $result->hStatements,
            'p_statements' => $result->pStatements,
        ]);
    }
}
