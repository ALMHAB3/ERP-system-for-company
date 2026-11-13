<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

final class ChainedHash
{
    /** @param object{prev_hash:string,occurred_at:string,user_id:?int,action:string,subject_type:?string,subject_id:?string,ip:?string,request_id:?string,data:string} $row */
    public static function of(object $row): string
    {
        return hash('sha256', implode("\x1F", [
            $row->prev_hash,
            $row->occurred_at,
            (string) ($row->user_id ?? ''),
            $row->action,
            (string) ($row->subject_type ?? ''),
            (string) ($row->subject_id ?? ''),
            (string) ($row->ip ?? ''),
            (string) ($row->request_id ?? ''),
            $row->data,
        ]));
    }
}
