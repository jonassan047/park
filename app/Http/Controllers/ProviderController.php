<?php

namespace App\Http\Controllers;

use App\Services\Ids;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// プロバイダー（案件を SES に提供してくれる上位者）：管理者は登録・一覧・編集・削除。SES は一覧・詳細・登録だけ（編集・削除・メモは管理者だけ）。エンジニアには出さない
class ProviderController extends Controller
{
    private function base(Request $r): string { return $r->user()->role === 'admin' ? '/admin/providers' : '/ses/providers'; }

    public function index(Request $r)
    {
        $list = DB::table('providers')->orderBy('name')->orderBy('id')->get();
        [$list, $pager] = page_slice($list);
        return view('admin.providers-index', ['list' => $list, 'pager' => $pager, 'base' => $this->base($r)]);
    }

    public function create(Request $r) { return view('admin.providers-form', ['p' => null, 'base' => $this->base($r)]); }

    public function store(Request $r)
    {
        $in = $this->input($r);
        if (isset($in['bad'])) return back()->withInput()->with('flash', ['msg' => $in['bad'], 'type' => 'bad']);
        if ($r->user()->role !== 'admin') $in['ok']['notes'] = null; // メモは管理者だけ
        $id = DB::table('providers')->insertGetId($in['ok'] + ['code' => Ids::next('PV'), 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($r->user()->id, 'provider-add', $in['ok']['name']);
        return $this->go($this->base($r)."/$id", 'プロバイダーを登録しました。'.($r->user()->role === 'admin' ? '' : ' 案件の登録で選べます。'));
    }

    public function show(Request $r, int $id)
    {
        return view('admin.providers-show', ['p' => $this->find($id), 'base' => $this->base($r)]);
    }

    public function edit(Request $r, int $id)
    {
        return view('admin.providers-form', ['p' => $this->find($id), 'base' => $this->base($r)]);
    }

    public function update(Request $r, int $id)
    {
        $this->find($id);
        $in = $this->input($r);
        if (isset($in['bad'])) return back()->withInput()->with('flash', ['msg' => $in['bad'], 'type' => 'bad']);
        DB::table('providers')->where('id', $id)->update($in['ok'] + ['updated_at' => now_str()]);
        return $this->go("/admin/providers/$id", '保存しました。');
    }

    public function destroy(Request $r, int $id)
    {
        $p = $this->find($id);
        DB::table('providers')->where('id', $id)->delete();
        N::audit($r->user()->id, 'provider-delete', $p->name);
        return $this->go('/admin/providers', 'プロバイダー「'.$p->name.'」を削除しました。');
    }

    private function find(int $id): object
    {
        $p = DB::table('providers')->where('id', $id)->first();
        abort_unless($p, 404);
        return $p;
    }

    // 入力の検査。必須はメール・企業名・氏名（姓）
    private function input(Request $r): array
    {
        $name = clean_name($r->input('name'));
        $sei = clean_name($r->input('sei'));
        $mei = clean_name($r->input('mei'));
        $email = strtolower((string) str_in($r->input('email')));
        if ($email === '') return ['bad' => 'メールアドレスを入れてください。'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) return ['bad' => 'メールアドレスの形が正しくありません。'];
        if ($name === null) return ['bad' => '企業名を入れてください。'];
        if (mb_strlen($name) > 100) return ['bad' => '企業名は100文字までにしてください。'];
        if ($sei === null) return ['bad' => '氏名（姓）を入れてください。'];
        if (mb_strlen($sei) > 50 || ($mei !== null && mb_strlen($mei) > 50)) return ['bad' => '氏名は50文字までにしてください。'];
        $phone = str_in($r->input('phone'));
        if ($phone !== null && mb_strlen($phone) > 50) return ['bad' => '電話番号は50文字までにしてください。'];
        $strengths = str_in($r->input('strengths'));
        if ($strengths !== null && mb_strlen($strengths) > 200) return ['bad' => '得意な分野は200文字までにしてください。'];
        $ad = W::addressCols($r->all() + ['postal_code' => null, 'address' => null], '所在地');
        if (isset($ad['bad'])) return $ad;
        $emp = int_or_null($r->input('employees'));
        if ($emp !== null && ($emp < 0 || $emp > 1000000)) return ['bad' => '人数が正しくありません。'];
        return ['ok' => ['name' => $name, 'sei' => $sei, 'mei' => $mei, 'email' => $email, 'phone' => $phone, ...$ad['ok'],
            'employees' => $emp, 'strengths' => $strengths, 'notes' => str_in($r->input('notes'))]];
    }
}
