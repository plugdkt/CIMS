<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chemicals\Services\ChemicalSyncService;
use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SyncPubChemChemicalsCommand extends Command
{
    protected $signature = 'chemicals:sync-pubchem
        {--all : ซิงค์ทุกรายการรวมถึงรายการที่ไม่มี CAS (ค้นหาด้วยชื่อ)}
        {--force : บังคับซิงค์ทับแม้จะมีข้อมูล GHS อยู่แล้ว}
        {--limit= : จำกัดจำนวนรายการ เช่น --limit=100}
        {--delay=250 : ระยะเวลาหน่วงระหว่างการเรียก API เป็นมิลลิวินาที (เพื่อไม่ให้เกิน Rate Limit ของ NIH PubChem)}';

    protected $description = 'ซิงค์ข้อมูลสูตรเคมีและข้อมูลความปลอดภัย GHS จาก PubChem เข้าสู่ตาราง items เป็นชุด';

    public function handle(ChemicalSyncService $syncService): int
    {
        $query = Item::query()->where('is_active', true);

        // Default: only sync items that have CAS number, unless --all is specified
        if (! $this->option('all')) {
            $query->whereNotNull('cas_no')->where('cas_no', '!=', '');
        }

        // Default: only sync items that do NOT have GHS yet, unless --force is specified
        if (! $this->option('force')) {
            $query->whereNull('ghs_codes');
        }

        $query->orderBy('id');

        /** @var string|null $limitOption */
        $limitOption = $this->option('limit');
        $limit = ($limitOption !== null && is_numeric($limitOption) && (int) $limitOption > 0)
            ? (int) $limitOption
            : null;

        $unlimitedTotal = (clone $query)->count();
        $total = $limit !== null ? min($limit, $unlimitedTotal) : $unlimitedTotal;

        if ($total === 0) {
            $this->info(__('chemicals.cmd_no_items'));

            return self::SUCCESS;
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info(__('chemicals.cmd_starting', ['count' => $total]));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $notFound = 0;
        $failed = 0;
        $processed = 0;

        /** @var string|int $delayOption */
        $delayOption = $this->option('delay');
        $delayMs = (int) $delayOption;

        foreach ($query->cursor() as $item) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $result = $syncService->syncItem($item);
                if ($result) {
                    $synced++;
                } else {
                    $notFound++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("PubChem batch sync error for item {$item->item_code}: ".$e->getMessage());
            }

            $bar->advance();

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            [__('chemicals.cmd_summary_header_result'), __('chemicals.cmd_summary_header_count')],
            [
                [__('chemicals.cmd_summary_synced'), $synced],
                [__('chemicals.cmd_summary_not_found'), $notFound],
                [__('chemicals.cmd_summary_failed'), $failed],
                [__('chemicals.cmd_summary_total'), $total],
            ]
        );

        return self::SUCCESS;
    }
}
