<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

/**
 * Pulls the pack size out of a central-store product name such as
 * "Asiatic Acid 500 mg /ขวด" or "โซเดียมไฮดรอกไซด์ 0.1 N 1 ลิตร /ขวด" — the store keeps size
 * and packaging inside the name string, not in separate columns. Only a trailing
 * "<number> <unit> /<packaging>" is read; anything else (multi-pack "10 x 5 ml", names with
 * no packaging slash) is left whole rather than guessed at, and the caller imports it with no
 * package size for a manager to fill in.
 */
final class CatalogNameParser
{
    /** Spelling (lower-cased) => `units.code`. */
    private const UNITS = [
        'mg' => 'mg', 'มก' => 'mg', 'มก.' => 'mg', 'มิลลิกรัม' => 'mg',
        'g' => 'g', 'gm' => 'g', 'กรัม' => 'g', 'กรัม.' => 'g',
        'kg' => 'kg', 'กก' => 'kg', 'กก.' => 'kg', 'กิโลกรัม' => 'kg',
        'ug' => 'ug', 'µg' => 'ug', 'μg' => 'ug', 'mcg' => 'ug', 'ไมโครกรัม' => 'ug',
        'ml' => 'mL', 'มล' => 'mL', 'มล.' => 'mL', 'มิลลิลิตร' => 'mL',
        'l' => 'L', 'lit' => 'L', 'ลิตร' => 'L',
        'ul' => 'uL', 'µl' => 'uL', 'μl' => 'uL', 'ไมโครลิตร' => 'uL',
        'ชิ้น' => 'pcs', 'อัน' => 'pcs', 'ใบ' => 'pcs', 'แผ่น' => 'pcs', 'เม็ด' => 'pcs',
        'ชุด' => 'pcs', 'ดวง' => 'pcs', 'หลอด' => 'pcs', 'ก้อน' => 'pcs', 'แคปซูล' => 'pcs',
        'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs', 'tablets' => 'pcs',
    ];

    /**
     * @return array{name: string, package_size: ?string, unit: ?string, packaging: ?string}
     */
    public function parse(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

        $matched = preg_match(
            '/^(?<name>.+?)\s+(?<size>\d+(?:,\d{3})*(?:\.\d+)?)\s*(?<unit>[^\d\s\/]+)\s*\/\s*(?<pack>\S.*)$/u',
            $raw,
            $m,
        );

        if ($matched !== 1) {
            return ['name' => $raw, 'package_size' => null, 'unit' => null, 'packaging' => $this->packagingOnly($raw)];
        }

        $unit = self::UNITS[mb_strtolower($m['unit'])] ?? null;
        // "10 x 5 ml" is a multi-pack: the 5 ml is one part of it, not the pack's size.
        if ($unit === null || preg_match('/[x×]\s*$/ui', $m['name']) === 1) {
            return ['name' => $raw, 'package_size' => null, 'unit' => null, 'packaging' => trim($m['pack'])];
        }

        $size = str_replace(',', '', $m['size']);
        if ((float) $size <= 0) {
            return ['name' => $raw, 'package_size' => null, 'unit' => null, 'packaging' => trim($m['pack'])];
        }

        return [
            'name' => trim($m['name']),
            'package_size' => $size,
            'unit' => $unit,
            'packaging' => trim($m['pack']),
        ];
    }

    private function packagingOnly(string $raw): ?string
    {
        return preg_match('/\/\s*(?<pack>[^\/\d][^\/]*)$/u', $raw, $m) === 1 ? trim($m['pack']) : null;
    }
}
