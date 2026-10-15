<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// 誰が何を見られるか
class Perms
{
    public static function engineer($u, $e): bool
    {
        if (!$u || !$e) return false;
        return $u->role === 'admin' || ($u->role === 'ses' && (int) $e->ses_id === (int) $u->ses_id) || ($u->role === 'eg' && (int) $e->id === (int) $u->engineer_id);
    }

    public static function caseView($u, $c): bool
    {
        if (!$u || !$c) return false;
        if ($u->role === 'admin') return true;
        // SES はグループの案件をすべて見られる（下書きは自社が登録したものだけ）
        if ($u->role === 'ses') return $c->status !== 'draft' || (int) $c->source_ses_id === (int) $u->ses_id;
        return DB::table('case_offers')->where('case_id', $c->id)->where('engineer_id', $u->engineer_id)->exists()
            || DB::table('applications')->where('case_id', $c->id)->where('engineer_id', $u->engineer_id)->exists();
    }

    public static function caseEdit($u, $c): bool
    {
        return $u->role === 'admin' || ($u->role === 'ses' && (int) $c->source_ses_id === (int) $u->ses_id);
    }

    // 応募・契約（ses_id と engineer_id を持つもの）
    public static function app($u, $a): bool
    {
        if (!$u || !$a) return false;
        return $u->role === 'admin' || ($u->role === 'ses' && (int) $a->ses_id === (int) $u->ses_id) || ($u->role === 'eg' && (int) $a->engineer_id === (int) $u->engineer_id);
    }
}
