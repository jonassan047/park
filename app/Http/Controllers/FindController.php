<?php

namespace App\Http\Controllers;

use App\Services\Ids;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 「ID で探す」：表示 ID（CA-000012 など。旧い1文字の形 C-12 でも探せる）から、その詳細の画面へ移る（v0.9.0）。
// 見られない ID と無い ID は、同じ文で返す（ID があることだけが漏れないように）。
class FindController extends Controller
{
    const NOT_FOUND = 'その ID は見つからないか、開けません。';

    public function find(Request $r)
    {
        $u = $r->user();
        $raw = $r->query('code');
        $code = is_string($raw) ? Ids::normalize($raw) : null; // 配列などは「見つからない」と同じ
        $to = $code ? $this->target($u, $code) : null;
        return $to ? redirect($to) : back()->with('flash', ['msg' => self::NOT_FOUND, 'type' => 'bad']);
    }

    // その人が開いてよい詳細の道。開けない・無いときは null
    private function target($u, string $code): ?string
    {
        $kind = Ids::kindOf($code);
        if (!$kind) return null;
        [$table, $col] = Ids::KINDS[$kind];
        $row = DB::table($table)->where($col, $code)->first();
        if (!$row) return null;
        $role = $u->role;
        switch ($kind) {
            case 'A': // 管理者の ID は、管理者だけ
                return $role === 'admin' && $row->role === 'admin' ? "/admin/admins/{$row->id}" : null;
            case 'T': // 担当者：管理者は SES の担当者の画面、同じ会社の SES・担当者は自社の画面
                if ($row->role !== 'ses' || !$row->ses_id) return null;
                if ($role === 'admin') return "/admin/ses/{$row->ses_id}/users/{$row->id}";
                return $role === 'ses' && (int) $row->ses_id === (int) $u->ses_id ? '/ses/company' : null;
            case 'S':
                // 直属の入れ物には SES の詳細が無いので、直属のエンジニアの一覧へ
                if ($role === 'admin') return $row->kind === 'direct' ? '/admin/engineers?ses=direct' : "/admin/ses/{$row->id}";
                return $role === 'ses' && (int) $row->id === (int) $u->ses_id ? '/ses/company' : null;
            case 'E':
                return Perms::engineer($u, $row) ? "/engineers/{$row->id}" : null;
            case 'C':
                return Perms::caseView($u, $row) ? "/cases/{$row->id}" : null;
            case 'AP':
                return Perms::app($u, $row) ? "/applications/{$row->id}" : null;
            case 'CT':
                return Perms::app($u, $row) ? "/contracts/{$row->id}" : null;
            case 'IV': // 請求書は、管理者と自社の SES
                return $role === 'admin' || ($role === 'ses' && (int) $row->ses_id === (int) $u->ses_id) ? "/invoices/{$row->id}" : null;
        }
        return null;
    }
}
