<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

use App\Domain\Chemicals\DTO\PubChemCompoundData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin, read-only wrapper around PubChem's public PUG REST / PUG View APIs
 * (pubchem.ncbi.nlm.nih.gov) — no API key, no auth. Used for (1) auto-filling a new
 * item's formula/GHS data from its CAS no./name at create time, and (2) a standalone
 * lookup page for procurement research (§ CLAUDE.md's "PubChem" post-launch note has
 * the full design rationale). Every lookup is cached for 30 days — PubChem's own
 * compound data for a given CID essentially never changes, and this keeps repeat
 * lookups (the same common reagent looked up by several people) off the network
 * entirely. Never throws on a network/parse failure — returns null so a lookup
 * failure degrades to "no data found", never a broken page.
 */
final class PubChemClient
{
    private const CACHE_TTL_DAYS = 30;

    public function __construct(
        private readonly string $pugBaseUrl,
        private readonly string $pugViewBaseUrl,
        private readonly int $timeoutSeconds,
        private readonly string|bool|null $caBundle = null,
    ) {
    }

    public function lookupByCas(string $cas): ?PubChemCompoundData
    {
        $cas = trim($cas);
        if ($cas === '') {
            return null;
        }

        $key = 'pubchem:v2:cas:'.$cas;
        $cached = Cache::get($key);
        if ($cached instanceof PubChemCompoundData) {
            return $cached;
        }

        $data = $this->resolve('xref/RegistryID', $cas);
        if ($data !== null) {
            Cache::put($key, $data, now()->addDays(self::CACHE_TTL_DAYS));
        }

        return $data;
    }

    public function lookupByName(string $name): ?PubChemCompoundData
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $key = 'pubchem:v2:name:'.mb_strtolower($name);
        $cached = Cache::get($key);
        if ($cached instanceof PubChemCompoundData) {
            return $cached;
        }

        $data = $this->resolve('name', $name);
        if ($data !== null) {
            Cache::put($key, $data, now()->addDays(self::CACHE_TTL_DAYS));
        }

        return $data;
    }

    private function resolve(string $domain, string $value): ?PubChemCompoundData
    {
        try {
            $request = Http::timeout($this->timeoutSeconds);
            if ($this->caBundle !== null) {
                $request = $request->withOptions(['verify' => $this->caBundle]);
            }

            $response = $request->get("{$this->pugBaseUrl}/compound/{$domain}/".rawurlencode($value)
                    .'/property/Title,MolecularFormula,MolecularWeight,IUPACName/JSON');

            if (! $response->successful()) {
                return null;
            }

            $properties = $response->json('PropertyTable.Properties', []);
            if ($properties === [] || ! isset($properties[0]['CID'])) {
                return null;
            }

            $cid = (int) $properties[0]['CID'];
            $ghs = $this->fetchGhs($cid);

            return new PubChemCompoundData(
                cid: $cid,
                title: (string) ($properties[0]['Title'] ?? $value),
                molecularFormula: $properties[0]['MolecularFormula'] ?? null,
                molecularWeight: isset($properties[0]['MolecularWeight']) ? (string) $properties[0]['MolecularWeight'] : null,
                iupacName: $properties[0]['IUPACName'] ?? null,
                signalWord: $ghs['signalWord'],
                ghsCodes: $ghs['ghsCodes'],
                hStatements: $ghs['hStatements'],
                pStatements: $ghs['pStatements'],
            );
        } catch (Throwable $e) {
            Log::warning('PubChemClient lookup failed', ['domain' => $domain, 'value' => $value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{signalWord: ?string, ghsCodes: list<string>, hStatements: list<string>, pStatements: list<string>}
     */
    private function fetchGhs(int $cid): array
    {
        $empty = ['signalWord' => null, 'ghsCodes' => [], 'hStatements' => [], 'pStatements' => []];

        try {
            $request = Http::timeout($this->timeoutSeconds);
            if ($this->caBundle !== null) {
                $request = $request->withOptions(['verify' => $this->caBundle]);
            }

            $response = $request->get("{$this->pugViewBaseUrl}/data/compound/{$cid}/JSON/", ['heading' => 'GHS Classification']);

            if (! $response->successful()) {
                return $empty;
            }

            $information = $this->findGhsInformation($response->json('Record.Section', []));
            if ($information === null) {
                return $empty;
            }

            $signalWord = null;
            $ghsCodes = [];
            $hStatements = [];
            $pStatements = [];

            $knownGhs = array_keys(config('ghs.pictograms'));
            $knownH = array_keys(config('ghs.hazard_statements'));
            $knownP = array_keys(config('ghs.precautionary_statements'));

            foreach ($information as $entry) {
                $name = $entry['Name'] ?? '';
                $strings = array_column($entry['Value']['StringWithMarkup'] ?? [], 'String');
                $markups = $entry['Value']['StringWithMarkup'][0]['Markup'] ?? [];

                if ($name === 'Pictogram(s)') {
                    foreach ($markups as $markup) {
                        if (preg_match('#(GHS0[1-9])\.svg#', (string) ($markup['URL'] ?? ''), $m)) {
                            $ghsCodes[] = $m[1];
                        }
                    }
                } elseif ($name === 'Signal') {
                    $signalWord = $strings[0] ?? null;
                } elseif ($name === 'GHS Hazard Statements') {
                    foreach ($strings as $s) {
                        if (preg_match('#^(H\d{3})#', $s, $m)) {
                            $hStatements[] = $m[1];
                        }
                    }
                } elseif ($name === 'Precautionary Statement Codes') {
                    foreach ($strings as $s) {
                        if (preg_match_all('#P\d{3}#', $s, $m)) {
                            $pStatements = [...$pStatements, ...$m[0]];
                        }
                    }
                }
            }

            return [
                'signalWord' => $signalWord,
                'ghsCodes' => array_values(array_unique(array_intersect($ghsCodes, $knownGhs))),
                'hStatements' => array_values(array_unique(array_intersect($hStatements, $knownH))),
                'pStatements' => array_values(array_unique(array_intersect($pStatements, $knownP))),
            ];
        } catch (Throwable $e) {
            Log::warning('PubChemClient GHS lookup failed', ['cid' => $cid, 'error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * PUG View nests "GHS Classification" several levels deep under Safety and
     * Hazards > Hazards Identification — walk the section tree to find it rather
     * than hardcoding the depth, since PubChem has changed this nesting before.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>|null
     */
    private function findGhsInformation(array $sections): ?array
    {
        foreach ($sections as $section) {
            if (($section['TOCHeading'] ?? null) === 'GHS Classification') {
                return $section['Information'] ?? null;
            }
            if (isset($section['Section'])) {
                $found = $this->findGhsInformation($section['Section']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
