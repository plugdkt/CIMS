<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads faculty personnel records from the MEDSCI ACC database (`account_medsci`).
 *
 * @phpstan-type MscAccUserRow object{
 *     id_user: int|string,
 *     name_user: string,
 *     username: string,
 *     email: string|null,
 *     pos_name: string|null,
 *     div_name: string|null
 * }
 */
class MscAccReader
{
    /**
     * @return Collection<int, MscAccUserRow>
     */
    public function getPersonnel(string $database = 'account_medsci'): Collection
    {
        config(['database.connections.msc_acc' => array_merge(config('database.connections.mysql'), [
            'database' => $database,
        ])]);

        /** @var Collection<int, MscAccUserRow> */
        return DB::connection('msc_acc')
            ->table('user as u')
            ->leftJoin('position as p', 'u.id_pos', '=', 'p.id_pos')
            ->leftJoin('division as d', 'u.id_div', '=', 'd.id_div')
            ->select([
                'u.id_user',
                'u.name_user',
                'u.username',
                'u.email',
                'p.pos_name',
                'd.div_name',
            ])
            ->orderBy('u.id_user')
            ->get();
    }
}
