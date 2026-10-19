<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Services\Notifier as N;
use App\Support\SkillCatalog;

// 業務の手続き（招待・登録・配信・応募・選考・成約・延長）。画面（コントローラー）からはここを呼ぶ。
class Workflow
{
    // ---------- ユーザーと招待 ----------
    public static function issueToken(int $userId, int $days = 7): string
    {
        $t = Str::random(40);
        DB::table('users')->where('id', $userId)->update(['token' => $t, 'token_expires' => date('Y-m-d H:i:s', time() + $days * 86400)]);
        return $t;
    }

    public static function userByToken(?string $t): ?object
    {
        if (!$t) return null;
        return DB::table('users')->where('token', $t)->where('token_expires', '>', now_str())->first();
    }

    // 1つの欄の氏名を、name と姓・名の列にして返す（1欄の入口：初めの設定・招待・担当者の追加など）
    public static function nameCols(string $full): array
    {
        [$sei, $mei] = split_name($full);
        return ['name' => $full, 'name_sei' => self::cut50($sei), 'name_mei' => self::cut50($mei)];
    }

    private static function cut50(?string $s): ?string
    {
        return $s === null ? null : mb_substr($s, 0, 50);
    }

    // 登録済みの全員の name・kana を姓・名に分けて入れる（マイグレーション用。name・kana は書き換えない）
    public static function splitExistingNames(): void
    {
        foreach (['users', 'engineers'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    [$ns, $nm] = split_name($row->name);
                    [$ks, $km] = split_name($row->kana);
                    DB::table($table)->where('id', $row->id)->update(['name_sei' => self::cut50($ns), 'name_mei' => self::cut50($nm), 'kana_sei' => self::cut50($ks), 'kana_mei' => self::cut50($km)]);
                }
            });
        }
    }

    public static function invite(array $d, $actor, string $note = ''): ?object
    {
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!$email) return null;
        $u = DB::table('users')->where('email', $email)->first();
        if ($u && $u->password) return $u; // すでに使っている人は招待しない
        // 未設定の人でも、立場（役割・SES・エンジニア）が依頼と違うなら招待しない（別の立場のリンクを渡さないため）
        if ($u && ($u->role !== $d['role'] || (int) $u->ses_id !== (int) ($d['ses_id'] ?? 0) || (int) $u->engineer_id !== (int) ($d['engineer_id'] ?? 0))) return null;
        if (!$u) {
            $id = DB::table('users')->insertGetId(['email' => $email] + self::nameCols((string) $d['name']) + ['role' => $d['role'], 'ses_id' => $d['ses_id'] ?? null,
                'engineer_id' => $d['engineer_id'] ?? null, 'created_at' => now_str(), 'updated_at' => now_str()]);
            $u = DB::table('users')->find($id);
        }
        $t = self::issueToken($u->id);
        $what = ['eg' => 'エンジニアとして PARK に登録されました。', 'ses' => 'SES のご担当者として PARK にご招待します。', 'admin' => 'PARK の管理者に招待されました。'][$d['role']];
        N::queueMail($email, $d['name'], 'ご招待：パスワードを設定してください',
            user_label($u)." 様\n\n{$what}".($note ? "\n$note" : '')."\n\n次のリンクから、パスワードを設定してください（7日間有効）。\n".url("/invite/$t")."\n\n"
            .($d['role'] === 'eg' ? 'パスワードの設定のときに、個人情報の取り扱いへの同意をお願いしています。スキルシートは、事実にもとづいて一緒に作ります。' : ''), 'invite', null, admin_sender($actor));
        N::audit($actor->id ?? null, 'invite', lbl('role', $d['role'])." {$d['name']} <$email>");
        return $u;
    }

    public static function createUser(string $email, string $name, string $role, string $password, array $extra = []): int
    {
        return DB::table('users')->insertGetId(['email' => strtolower($email)] + self::nameCols($name) + ['role' => $role, 'password' => Hash::make($password),
            'ses_id' => $extra['ses_id'] ?? null, 'engineer_id' => $extra['engineer_id'] ?? null, 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // ---------- 最初の情報登録 ----------
    // 氏名・ふりがな・電話・所属／役職を保存する。登録を済ませた日時は、最初の1回だけ入れる
    // 管理者は企業名、SES は SES名（ses_companies.name）も保存する。戻り値は、画面に出す注意（なければ null）
    public static function saveProfile($user, array $d): ?string
    {
        $ks = clean_name($d['kana_sei'] ?? null); $km = clean_name($d['kana_mei'] ?? null);
        $upd = ['kana_sei' => $ks, 'kana_mei' => $km, 'kana' => join_name($ks, $km), 'phone' => str_in($d['phone'] ?? ''), 'updated_at' => now_str()];
        // 姓・名がどちらも空なら、今の氏名のまま
        $ns = clean_name($d['name_sei'] ?? null); $nm = clean_name($d['name_mei'] ?? null);
        if ($ns !== null || $nm !== null) $upd += ['name_sei' => $ns, 'name_mei' => $nm, 'name' => join_name($ns, $nm)];
        if ($user->role !== 'eg') $upd['position'] = str_in($d['position'] ?? '');
        if ($user->role === 'admin' && ($c = str_in($d['company_name'] ?? '')) !== null) $upd['company_name'] = $c;
        if (!DB::table('users')->where('id', $user->id)->value('profile_completed_at')) $upd['profile_completed_at'] = now_str();
        $warn = null;
        DB::transaction(function () use ($user, $upd, $d, &$warn) {
            DB::table('users')->where('id', $user->id)->update($upd);
            if ($user->role === 'ses' && ($n = str_in($d['ses_name'] ?? '')) !== null) $warn = self::renameSes((int) $user->ses_id, $n, $user);
        });
        N::audit($user->id, 'profile', '');
        return $warn;
    }

    // SES名を変える（最初の情報登録・アカウント・会社の画面の三つの入口で同じ動き）。変えたときだけ記録して、管理者にベルで知らせる。
    // 同じ名前の SES がほかにあれば、その注意の文を返す
    public static function renameSes(int $sesId, string $name, $actor): ?string
    {
        $name = trim($name);
        $s = DB::table('ses_companies')->find($sesId);
        if (!$s || $s->kind !== 'ses' || $name === '' || $s->name === $name) return null;
        DB::table('ses_companies')->where('id', $sesId)->update(['name' => $name, 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'ses-rename', "{$s->name} → $name");
        $dup = DB::table('ses_companies')->where('id', '!=', $sesId)->where('name', $name)->exists();
        N::toAdmins('SES の名前が変わりました', "{$s->name} が名前を $name に変えました。".($dup ? '（同じ名前の SES がほかにもあります）' : ''), "/admin/ses/$sesId", false, 'ses-rename');
        return $dup ? '同じ名前の SES がほかにもあります。見分けられるよう、正式名称で入れてください。' : null;
    }

    // 情報の登録のあとに進む先と、そこに出すお知らせ（なければ null）
    public static function profileHome($user): array
    {
        if ($user->role === 'ses') {
            if (!DB::table('ses_companies')->where('id', $user->ses_id)->value('self_confirmed_at')) return ['/ses/company', '会社の情報も確かめてください。'];
            return ['/ses', null];
        }
        if ($user->role === 'eg') {
            if (!DB::table('engineers')->where('id', $user->engineer_id)->value('self_confirmed_at')) return ['/engineers/'.$user->engineer_id, '登録の内容を確かめて「確認しました」を押してください。'];
            return ['/eg', null];
        }
        return ['/admin', null];
    }

    // ---------- 管理者の直属 ----------
    // 管理者に直接つくエンジニアの入れ物（SES ではない）。必要になったときに1つだけ作る
    public static function directSesId(): int
    {
        $id = DB::table('ses_companies')->where('kind', 'direct')->orderBy('id')->value('id');
        if ($id) return (int) $id;
        return (int) DB::table('ses_companies')->insertGetId(['name' => '管理者の直属', 'kind' => 'direct', 'status' => 'active',
            'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    public static function isDirect(?int $sesId): bool
    {
        return $sesId && DB::table('ses_companies')->where('id', $sesId)->value('kind') === 'direct';
    }

    // ---------- SES ----------
    // 管理者（$actor が admin）が登録するときは、申し込みの通知と受付メールを出さない
    public static function applySes(array $d, $actor = null): int
    {
        $id = DB::table('ses_companies')->insertGetId([
            'name' => str_in($d['name'] ?? ''), 'rep_name' => str_in($d['rep_name'] ?? ''), 'contact_name' => str_in($d['contact_name'] ?? ''),
            'email' => strtolower((string) str_in($d['email'] ?? '')), 'phone' => str_in($d['phone'] ?? ''), 'address' => str_in($d['address'] ?? ''),
            'employees' => int_or_null($d['employees'] ?? null), 'strengths' => str_in($d['strengths'] ?? ''), 'message' => str_in($d['message'] ?? ''),
            'notes' => str_in($d['notes'] ?? ''), 'status' => 'pending', 'agreed_at' => !empty($d['agree']) ? now_str() : null, 'created_at' => now_str(), 'updated_at' => now_str(),
        ]);
        if (($actor->role ?? '') === 'admin') return $id;
        $name = str_in($d['name'] ?? ''); $contact = str_in($d['contact_name'] ?? '');
        N::toAdmins('SES から参加の申し込みがありました', "{$name}（{$contact} 様）から参加の申し込みがありました。内容を確認し、承認してください。", "/admin/ses/$id", true, 'ses-apply');
        N::queueMail(str_in($d['email'] ?? ''), $contact, '参加のお申し込みを受け付けました', "{$contact} 様\n\nPARK への参加のお申し込みを受け付けました。管理者が内容を確認のうえ、ご連絡します。\n\n会社名：{$name}", 'ses-apply');
        return $id;
    }

    public static function approveSes(int $sesId, $actor, ?string $note = null): void
    {
        $s = DB::table('ses_companies')->find($sesId);
        DB::table('ses_companies')->where('id', $sesId)->update(['status' => 'active', 'joined_at' => $s->joined_at ?: today_str(), 'updated_at' => now_str()]);
        N::audit($actor->id, 'ses-approve', $s->name);
        if ($s->email) self::invite(['email' => $s->email, 'name' => $s->contact_name ?: ($s->rep_name ?: $s->name), 'role' => 'ses', 'ses_id' => $sesId], $actor, $note ?? "{$s->name} の PARK への参加を承認しました。");
    }

    // ---------- エンジニア ----------
    const ENG_FIELDS = ['name', 'kana', 'initials', 'email', 'birth_year', 'nearest_station', 'years_exp', 'summary', 'strengths', 'desired_price', 'min_price', 'available_from', 'work_style', 'days_per_week', 'status',
        // 履歴書・業務経歴書の項目（2026-10-16）
        'gender', 'phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education', 'graduated_ym', 'career_summary', 'desired_roles', 'english_level', 'appeal', 'wishes', 'birth_date', 'nickname',
        // 氏名・ふりがなの姓・名（2026-10-19。末尾に足した）
        'name_sei', 'name_mei', 'kana_sei', 'kana_mei'];

    // 詳しいプロフィールの画面で保存する項目（gender から nickname まで。姓・名は含めない）
    const ENG_PROFILE_FIELDS = ['gender', 'phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education', 'graduated_ym', 'career_summary',
        'desired_roles', 'english_level', 'appeal', 'wishes', 'birth_date', 'nickname'];

    private static function engValues(array $d, ?object $old = null): array
    {
        // 姓・名の欄があればそれをつなぐ。無くて name・kana だけなら分ける（見本・AI・古い画面のため）
        foreach (['name' => 'name', 'kana' => 'kana'] as $full => $base) {
            if (array_key_exists($base.'_sei', $d) || array_key_exists($base.'_mei', $d)) {
                $d[$base.'_sei'] = clean_name($d[$base.'_sei'] ?? null);
                $d[$base.'_mei'] = clean_name($d[$base.'_mei'] ?? null);
                $d[$full] = join_name($d[$base.'_sei'], $d[$base.'_mei']);
            } elseif (array_key_exists($full, $d)) {
                [$d[$base.'_sei'], $d[$base.'_mei']] = array_map([self::class, 'cut50'], split_name(is_string($d[$full]) ? $d[$full] : null));
            }
        }
        $v = [];
        foreach (self::ENG_FIELDS as $f) {
            if (!array_key_exists($f, $d)) { $v[$f] = $old->$f ?? null; continue; }
            if (in_array($f, ['birth_year', 'days_per_week', 'walk_minutes'])) $v[$f] = int_or_null($d[$f]);
            elseif (in_array($f, ['years_exp', 'desired_price', 'min_price'])) $v[$f] = num_or_null($d[$f]);
            else $v[$f] = str_in($d[$f]);
        }
        if (!$v['initials'] && $v['kana']) $v['initials'] = self::initialsFromKana($v['kana']);
        if ($v['birth_date']) $v['birth_year'] = (int) substr($v['birth_date'], 0, 4); // 生年月日があれば、年もそろえる
        $v['email'] = $v['email'] ? strtolower($v['email']) : null;
        $v['work_style'] = $v['work_style'] ?: 'any';
        $v['status'] = $v['status'] ?: 'available';
        $v['days_per_week'] = $v['days_per_week'] ?: 5;
        return $v;
    }

    // 「やまだ たろう」→「T.Y」（スキルシートで名前を伏せるとき用）
    public static function initialsFromKana(string $kana): ?string
    {
        $rows = ['A' => 'あ', 'I' => 'い', 'U' => 'う', 'E' => 'え', 'O' => 'お', 'K' => 'かきくけこ', 'G' => 'がぎぐげご', 'S' => 'さしすせそ', 'J' => 'じ', 'Z' => 'ざずぜぞ',
            'T' => 'たつてと', 'C' => 'ち', 'D' => 'だぢづでど', 'N' => 'なにぬねの', 'H' => 'はひへほ', 'F' => 'ふ', 'B' => 'ばびぶべぼ', 'P' => 'ぱぴぷぺぽ',
            'M' => 'まみむめも', 'Y' => 'やゆよ', 'R' => 'らりるれろ', 'W' => 'わ'];
        $hira = mb_convert_kana($kana, 'cs');
        $parts = preg_split('/\s+/u', trim($hira));
        if (count($parts) < 2) return null;
        $ini = function ($ch) use ($rows) { foreach ($rows as $r => $chars) if (mb_strpos($chars, $ch) !== false) return $r; return '?'; };
        return $ini(mb_substr($parts[1], 0, 1)).'.'.$ini(mb_substr($parts[0], 0, 1));
    }

    public static function createEngineer(int $sesId, array $d, $actor, bool $invite = true): int
    {
        $v = self::engValues($d);
        $id = DB::table('engineers')->insertGetId($v + ['ses_id' => $sesId, 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($actor->id ?? null, 'engineer-create', "{$v['name']}（SES {$sesId}）");
        if ($invite && $v['email']) self::invite(['email' => $v['email'], 'name' => $v['name'], 'role' => 'eg', 'ses_id' => $sesId, 'engineer_id' => $id], $actor);
        N::toAdmins('エンジニアが登録されました', "{$v['name']} さんが登録されました。経験票・スキルシートの作成を一緒に進めてください。", "/engineers/$id", false);
        return $id;
    }

    public static function updateEngineer(int $id, array $d, $actor): void
    {
        $old = DB::table('engineers')->find($id);
        $v = self::engValues($d, $old);
        DB::table('engineers')->where('id', $id)->update($v + ['updated_at' => now_str()]);
        N::audit($actor->id, 'engineer-update', $v['name']);
        self::editedBy($id, $actor);
    }

    // 登録の内容は、本人が責任を持つ。管理者・SES が書き換えたら、本人にもう一度確かめてもらう
    public static function editedBy(int $engineerId, $actor): void
    {
        if ($actor && $actor->role !== 'eg') DB::table('engineers')->where('id', $engineerId)->update(['self_confirmed_at' => null]);
    }

    public static function confirmEngineer(int $engineerId, $actor): void
    {
        DB::table('engineers')->where('id', $engineerId)->update(['self_confirmed_at' => now_str()]);
        N::audit($actor->id, 'engineer-confirm', (string) $engineerId);
    }

    // 成約した案件のうち、経験票に書いてもらう必要があるもの（始まったのにまだ、終わったのに終わってから書いていない）
    public static function historyTodo(int $engineerId)
    {
        return DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.engineer_id', $engineerId)
            ->where(fn ($q) => $q->whereNull('k.history_updated_at')->orWhere(fn ($q) => $q->where('k.status', 'ended')->whereColumn('k.history_updated_at', '<', 'k.end_date')))
            ->select('k.*', 'c.title')->orderByDesc('k.start_date')->get();
    }

    public static function saveSkills(int $engineerId, array $d): void
    {
        $names = (array) ($d['skill_name'] ?? []); $cats = (array) ($d['skill_cat'] ?? []);
        $years = (array) ($d['skill_years'] ?? []); $levels = (array) ($d['skill_level'] ?? []);
        DB::transaction(function () use ($engineerId, $names, $cats, $years, $levels) {
            DB::table('engineer_skills')->where('engineer_id', $engineerId)->delete();
            foreach ($names as $i => $n) {
                if (trim((string) $n) === '') continue;
                // 技術マスタにある言葉は正式な名前・分類にそろえる。無い言葉はマスタに足す（管理者が分類を確かめる）
                $t = SkillCatalog::find(trim($n));
                if (!$t && SkillCatalog::ensure(trim($n), $cats[$i] ?? 'other')) $t = SkillCatalog::find(trim($n));
                DB::table('engineer_skills')->insert(['engineer_id' => $engineerId, 'name' => $t[1] ?? trim($n), 'category' => $t[2] ?? ($cats[$i] ?? 'other'), 'term_id' => $t[0] ?? null,
                    'years' => num_or_null($years[$i] ?? null), 'level' => int_or_null($levels[$i] ?? null) ?: 3]);
            }
        });
        DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => now_str()]);
    }

    const PROJ_FIELDS = ['title', 'industry', 'period_from', 'period_to', 'role', 'team_size', 'tech', 'phases', 'description', 'achievements', 'os_tools', 'langs', 'dbs', 'sort'];

    public static function saveProject(int $engineerId, array $d, ?int $projectId = null): void
    {
        $v = [];
        foreach (self::PROJ_FIELDS as $f) {
            if (in_array($f, ['team_size', 'sort'])) $v[$f] = int_or_null($d[$f] ?? null);
            elseif ($f === 'phases') $v[$f] = implode('、', parse_list(implode('、', (array) ($d[$f] ?? [])))) ?: null;
            else $v[$f] = str_in($d[$f] ?? '');
        }
        if ($v['sort'] === null) unset($v['sort']);
        if ($projectId) DB::table('engineer_projects')->where('id', $projectId)->where('engineer_id', $engineerId)->update($v);
        else DB::table('engineer_projects')->insert($v + ['engineer_id' => $engineerId]);
        DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => now_str()]);
    }

    // 学歴・職歴・資格（行ごとに送られてくる。空の行は捨てる）
    public static function saveHistories(int $engineerId, array $d): void
    {
        DB::transaction(function () use ($engineerId, $d) {
            DB::table('engineer_histories')->where('engineer_id', $engineerId)->delete();
            $kinds = (array) ($d['h_kind'] ?? []); $yms = (array) ($d['h_ym'] ?? []); $texts = (array) ($d['h_text'] ?? []);
            foreach ($texts as $i => $text) {
                if (trim((string) $text) === '') continue;
                DB::table('engineer_histories')->insert(['engineer_id' => $engineerId, 'kind' => ($kinds[$i] ?? 'work') === 'edu' ? 'edu' : 'work', 'ym' => str_in($yms[$i] ?? ''), 'text' => trim($text), 'sort' => $i]);
            }
            DB::table('engineer_certs')->where('engineer_id', $engineerId)->delete();
            $names = (array) ($d['c_name'] ?? []); $cyms = (array) ($d['c_ym'] ?? []);
            foreach ($names as $i => $name) {
                if (trim((string) $name) === '') continue;
                DB::table('engineer_certs')->insert(['engineer_id' => $engineerId, 'name' => trim($name), 'ym' => str_in($cyms[$i] ?? ''), 'sort' => $i]);
            }
        });
        DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => now_str()]);
    }

    // ---------- 案件 ----------
    const CASE_FIELDS = ['title', 'client_label', 'price', 'price_note', 'required_skills', 'preferred_skills', 'description', 'location', 'work_style', 'start_date', 'period',
        'days_per_week', 'interviews_count', 'headcount', 'min_years', 'conditions', 'deadline', 'status', 'raw_text'];

    public static function saveCase(array $d, $user, ?int $id = null): int
    {
        $v = [];
        foreach (self::CASE_FIELDS as $f) {
            if (in_array($f, ['days_per_week', 'interviews_count', 'headcount'])) $v[$f] = int_or_null($d[$f] ?? null);
            elseif (in_array($f, ['price', 'min_years'])) $v[$f] = num_or_null($d[$f] ?? null);
            else $v[$f] = str_in($d[$f] ?? '');
        }
        $v['work_style'] = $v['work_style'] ?: 'any';
        $v['status'] = $v['status'] ?: 'open';
        // 必須・尚可の技術を正式な名前にそろえ、マスタに無いものは足して管理者に知らせる
        $added = [];
        foreach (['required_skills', 'preferred_skills'] as $f) {
            $list = [];
            foreach (parse_list($v[$f]) as $w) {
                if ($name = SkillCatalog::ensure($w)) $added[] = $name;
                $t = SkillCatalog::find($w);
                $list[] = $t[1] ?? $w;
            }
            $v[$f] = $list ? implode('、', array_unique($list)) : null;
        }
        if ($added) N::toAdmins('案件から新しい技術を技術マスタに追加しました', '「'.$v['title'].'」に、マスタに無い技術がありました：'.implode('、', $added)."\n分類（言語・フレームワーク・DB など）と別名を確かめてください。", '/admin/skills', false);
        if ($id) {
            DB::table('cases')->where('id', $id)->update($v + ['updated_at' => now_str()]);
            N::audit($user->id, 'case-update', $v['title']);
            return $id;
        }
        $v['source_ses_id'] = $user->role === 'ses' ? $user->ses_id : int_or_null($d['source_ses_id'] ?? null);
        $newId = DB::table('cases')->insertGetId($v + ['registered_by' => $user->id, 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::audit($user->id, 'case-create', $v['title']);
        if ($user->role === 'ses') N::toAdmins('SES から案件が登録されました', "「{$v['title']}」が登録されました。マッチングして配信してください。", "/cases/$newId", true, 'case');
        return $newId;
    }

    // 選んだエンジニアに案件を配信し、所属の SES にも知らせる
    public static function distribute(int $caseId, array $engineerIds, $actor, string $message = ''): int
    {
        $c = DB::table('cases')->find($caseId);
        $bySes = []; $count = 0;
        foreach (array_map('intval', $engineerIds) as $eid) {
            $e = DB::table('engineers')->find($eid);
            if (!$e || DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $eid)->exists()) continue;
            $s = Matcher::score($c, $e);
            DB::table('case_offers')->insert(['case_id' => $caseId, 'engineer_id' => $eid, 'score' => $s['score'], 'reasons' => implode(' / ', $s['reasons']),
                'missing' => implode(' / ', $s['missing']), 'sent_by' => $actor->id, 'sent_at' => now_str()]);
            N::toEg($eid, "あなたに合う案件が届きました：{$c->title}",
                "{$c->title}\n単価：".($c->price ? man($c->price) : '相談').'／開始：'.($c->start_date ? fmt_date($c->start_date) : '相談').'／'.lbl('workStyle', $c->work_style)
                ."\n合う点：".(implode('、', array_slice($s['reasons'], 0, 4)) ?: '—').($message ? "\n\n管理者より：$message" : '')."\n\n条件を確認のうえ、応募するか見送るかを選んでください。",
                "/cases/$caseId", true, 'offer');
            $bySes[$e->ses_id][] = "{$e->name}（{$s['score']}点）";
            $count++;
        }
        foreach ($bySes as $sesId => $names) {
            if (self::isDirect($sesId)) continue; // 直属は、操作した管理者への知らせが要らない
            N::toSes($sesId, "貴社のエンジニアに案件を配信しました：{$c->title}", "次の方に「{$c->title}」を配信しました。\n".implode("\n", $names)."\n\nご本人が応募を決めるほか、貴社から個別にご案内いただくこともできます。", "/cases/$caseId", true, 'offer-ses');
        }
        N::audit($actor->id, 'distribute', "{$c->title} → {$count}名");
        return $count;
    }

    // ---------- 応募と選考 ----------
    public static function addEvent(int $appId, string $status, ?string $note, $actor): void
    {
        DB::table('application_events')->insert(['application_id' => $appId, 'status' => $status, 'note' => $note ?? '', 'user_id' => $actor->id ?? null, 'at' => now_str()]);
    }

    public static function apply(int $caseId, int $engineerId, array $d, $actor): int
    {
        $c = DB::table('cases')->find($caseId);
        $e = DB::table('engineers')->find($engineerId);
        if (!$c || !$e) throw new \RuntimeException('案件またはエンジニアが見つかりません');
        if ($c->status !== 'open') throw new \RuntimeException('この案件は募集を終えています');
        if (!$e->self_confirmed_at) throw new \RuntimeException('エンジニアご本人が、まだ登録の内容を確認していません。ご本人がホームの「登録の内容の確認」を済ませてから応募してください');
        // GP の中での二重応募は受け付けない（同じ人が別の SES 経由で出るのを防ぐ）
        if (DB::table('applications')->where('case_id', $caseId)->where('engineer_id', $engineerId)->exists()) throw new \RuntimeException('この案件には応募済みです');
        $appId = DB::table('applications')->insertGetId([
            'case_id' => $caseId, 'engineer_id' => $engineerId, 'ses_id' => $e->ses_id, 'status' => 'applied',
            'proposed_price' => num_or_null($d['proposed_price'] ?? null) ?? $c->price,
            'start_ok' => !empty($d['start_ok']), 'price_ok' => !empty($d['price_ok']), 'style_ok' => !empty($d['style_ok']),
            'condition_note' => str_in($d['condition_note'] ?? ''), 'applied_by' => $actor->id, 'applied_at' => now_str(), 'updated_at' => now_str(),
        ]);
        self::addEvent($appId, 'applied', str_in($d['condition_note'] ?? ''), $actor);
        DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $engineerId)->update(['status' => 'applied']);
        if ($e->status === 'available') DB::table('engineers')->where('id', $engineerId)->update(['status' => 'proposing']);
        $who = ['eg' => 'ご本人', 'ses' => '所属の SES', 'admin' => '管理者'][$actor->role];
        N::toAdmins("応募がありました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました（{$who}から）。スキルシートを案件に合わせて整え、提出してください。", "/applications/$appId", true, 'apply');
        if (!self::isDirect($e->ses_id)) N::toSes($e->ses_id, "貴社のエンジニアが応募しました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました。選考の進み具合は PARK でご確認いただけます。", "/applications/$appId", true, 'apply');
        if ($actor->role !== 'eg') N::toEg($engineerId, "案件に応募しました：{$c->title}", "「{$c->title}」に、{$who}からあなたの応募を出しました。条件に問題があれば、すぐにご連絡ください。", "/applications/$appId", true, 'apply');
        N::audit($actor->id, 'apply', "{$e->name} → {$c->title}");
        return $appId;
    }

    public static function refreshEngineerStatus(int $engineerId): void
    {
        $e = DB::table('engineers')->find($engineerId);
        if (!$e || $e->status === 'unavailable') return;
        if (DB::table('contracts')->where('engineer_id', $engineerId)->where('status', 'active')->exists()) { DB::table('engineers')->where('id', $engineerId)->update(['status' => 'working']); return; }
        $active = DB::table('applications')->where('engineer_id', $engineerId)->whereNotIn('status', ['won', 'rejected', 'withdrawn'])->exists();
        DB::table('engineers')->where('id', $engineerId)->update(['status' => $active ? 'proposing' : 'available']);
    }

    public static function loadApp(int $appId): ?object
    {
        return DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 'a.ses_id')->where('a.id', $appId)
            ->select('a.*', 'c.title', 'c.price as case_price', 'c.start_date as case_start', 'c.headcount', 'e.name as eg_name', 's.name as ses_name')->first();
    }

    public static function setStatus(int $appId, string $status, $actor, string $reason = '', string $note = ''): void
    {
        $a = self::loadApp($appId);
        if (!$a) throw new \RuntimeException('応募が見つかりません');
        $upd = ['status' => $status, 'updated_at' => now_str()];
        if ($status === 'rejected') { $upd['reject_reason'] = $reason ?: 'その他'; $upd['reject_note'] = $note; }
        DB::table('applications')->where('id', $appId)->update($upd);
        self::addEvent($appId, $status, implode('：', array_filter([$reason, $note])), $actor);
        $label = lbl('appStatus', $status);
        $msgs = [
            'submitted' => 'スキルシートを提出しました。書類の結果をお待ちください。',
            'doc_passed' => '書類選考を通過しました。面談の日程を調整します。',
            'rejected' => '今回は見送りとなりました。'.($reason ? "理由：{$reason}。" : '').($note ? "{$note}。" : '').'次の案件に活かします。',
            'withdrawn' => '応募を取り下げました。',
        ];
        if (isset($msgs[$status])) {
            N::toEg($a->engineer_id, "{$label}：{$a->title}", $msgs[$status], "/applications/$appId", true, 'status');
            N::toSes($a->ses_id, "{$a->eg_name} さん {$label}：{$a->title}", $msgs[$status], "/applications/$appId", true, 'status');
        }
        // 直属の応募は、上の toSes が管理者に届くので、ここでは重ねて出さない
        if ($status === 'withdrawn' && !(isset($msgs[$status]) && self::isDirect($a->ses_id))) N::toAdmins("応募の取り下げ：{$a->title}", "{$a->eg_name} さんが応募を取り下げました。$note", "/applications/$appId", false);
        N::audit($actor->id, 'app-status', "{$a->eg_name} / {$a->title} → $label");
        self::refreshEngineerStatus($a->engineer_id);
    }

    public static function scheduleInterview(int $appId, array $d, $actor): int
    {
        $a = self::loadApp($appId);
        $round = (int) DB::table('interviews')->where('application_id', $appId)->max('round') + 1;
        $when = str_replace('T', ' ', (string) ($d['scheduled_at'] ?? ''));
        $id = DB::table('interviews')->insertGetId(['application_id' => $appId, 'round' => $round, 'scheduled_at' => $when ?: null, 'place' => str_in($d['place'] ?? ''),
            'request_note' => str_in($d['request_note'] ?? ''),
            'qa_json' => json_encode(Qa::generate($a->case_id, $a->engineer_id, str_in($d['request_note'] ?? '')), JSON_UNESCAPED_UNICODE), 'created_at' => now_str()]);
        DB::table('applications')->where('id', $appId)->update(['status' => 'interview', 'updated_at' => now_str()]);
        self::addEvent($appId, 'interview', "{$round}回目 $when ".($d['place'] ?? ''), $actor);
        $whenText = ($when ?: '日時調整中').'／'.(str_in($d['place'] ?? '') ?? '場所・URL は追ってご連絡');
        N::toEg($a->engineer_id, "面談が決まりました：{$a->title}（{$round}回目）", "面談：{$whenText}\n\nこの案件に合わせた「面談対策の Q&A」を用意しました。面談の前に目を通し、自分の言葉で答えられるようにしておいてください。", "/interviews/$id", true, 'interview');
        N::toSes($a->ses_id, "面談が決まりました：{$a->title}", "面談：{$whenText}\n面談スクリプト（想定質問と答え方）を作りました。", "/interviews/$id", true, 'interview');
        if (($actor->role ?? '') === 'ses') N::toAdmins("SES から面談依頼の報告：{$a->title}", "{$a->eg_name} さん／面談：{$whenText}", "/interviews/$id", false, 'interview');
        N::audit($actor->id, 'interview', "{$a->title} {$round}回目");
        return $id;
    }

    public static function interviewResult(int $ivId, string $result, ?string $feedback, $actor): void
    {
        $iv = DB::table('interviews')->find($ivId);
        DB::table('interviews')->where('id', $ivId)->update(['result' => $result, 'feedback' => str_in($feedback)]);
        if ($result === 'pass') {
            DB::table('applications')->where('id', $iv->application_id)->update(['status' => 'interviewed', 'updated_at' => now_str()]);
            self::addEvent($iv->application_id, 'interviewed', "{$iv->round}回目 通過。".$feedback, $actor);
            $a = self::loadApp($iv->application_id);
            N::toEg($a->engineer_id, "面談を通過しました：{$a->title}", "{$iv->round}回目の面談を通過しました。".($feedback ? "\n感想：$feedback" : '')."\n次のご連絡をお待ちください。", "/applications/{$a->id}", true, 'status');
            N::toSes($a->ses_id, "面談通過：{$a->title}", "{$iv->round}回目の面談を通過しました。", "/applications/{$a->id}", true, 'status');
        } else {
            self::setStatus($iv->application_id, 'rejected', $actor, '面談の受け答え', (string) $feedback);
        }
    }

    public static function makeContract(int $appId, array $d, $actor): int
    {
        $a = self::loadApp($appId);
        if (DB::table('contracts')->where('application_id', $appId)->exists()) throw new \RuntimeException('この応募はすでに契約になっています');
        $start = str_in($d['start_date'] ?? '') ?? today_str();
        // 契約は3か月ごと（初めの契約の終わりは、開始から3か月目の月末）
        $end = str_in($d['end_date'] ?? '') ?? month_end($start, 2);
        $price = num_or_null($d['price'] ?? null) ?? $a->proposed_price ?? $a->case_price;
        if (!$price) throw new \RuntimeException('契約の金額を入れてください');
        $kid = DB::table('contracts')->insertGetId(['application_id' => $appId, 'case_id' => $a->case_id, 'engineer_id' => $a->engineer_id, 'ses_id' => $a->ses_id,
            'price' => $price, 'start_date' => $start, 'end_date' => $end, 'status' => 'active', 'created_at' => now_str()]);
        DB::table('applications')->where('id', $appId)->update(['status' => 'won', 'updated_at' => now_str()]);
        self::addEvent($appId, 'won', "契約 {$start}〜{$end}／".man($price), $actor);
        DB::table('engineers')->where('id', $a->engineer_id)->update(['status' => 'working']);
        $won = DB::table('applications')->where('case_id', $a->case_id)->where('status', 'won')->count();
        if ($won >= ($a->headcount ?: 1)) DB::table('cases')->where('id', $a->case_id)->update(['status' => 'filled', 'updated_at' => now_str()]);
        N::toEg($a->engineer_id, "成約おめでとうございます：{$a->title}", "「{$a->title}」に決まりました。開始は {$start} です。\n最初の1か月に、困りごとがないか短くお話を聞かせてください（PARK のホームから記入できます）。\nこの案件を、経験票にも追加してください（ホームの「経歴の更新」から）。", "/eg", true, 'won');
        N::toSes($a->ses_id, "成約しました：{$a->title}", "{$a->eg_name} さんが「{$a->title}」に決まりました。\n契約：{$start}〜{$end}／".man($price).(self::isDirect($a->ses_id) ? '' : "\n成功報酬（初期費用）は、稼働開始の月の請求に含まれます。"), "/contracts/$kid", true, 'won');
        N::audit($actor->id, 'contract', "{$a->eg_name} / {$a->title} ".man($price));
        return $kid;
    }

    public static function extendContract(int $id, int $months, $actor): void
    {
        $k = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.id', $id)->select('k.*', 'c.title')->first();
        $newEnd = month_end($k->end_date ?: today_str(), $months ?: 3);
        DB::table('contracts')->where('id', $id)->update(['end_date' => $newEnd, 'extensions' => $k->extensions + 1, 'status' => 'active']);
        N::toSes($k->ses_id, "契約が延長されました：{$k->title}", "契約の終わりが {$newEnd} になりました。", "/contracts/$id", true, 'contract');
        N::toEg($k->engineer_id, "契約が延長されました：{$k->title}", "契約の終わりが {$newEnd} になりました。引き続きよろしくお願いします。", "/contracts/$id", true, 'contract');
        N::audit($actor->id, 'contract-extend', "{$k->title} → $newEnd");
    }

    public static function endContract(int $id, array $d, $actor): void
    {
        $k = DB::table('contracts')->find($id);
        DB::table('contracts')->where('id', $id)->update(['status' => 'ended', 'end_date' => str_in($d['end_date'] ?? '') ?? ($k->end_date ?: today_str()), 'end_reason' => str_in($d['end_reason'] ?? '')]);
        N::audit($actor->id, 'contract-end', "#$id ".($d['end_reason'] ?? ''));
        self::refreshEngineerStatus($k->engineer_id);
        $title = DB::table('cases')->where('id', $k->case_id)->value('title');
        N::toEg($k->engineer_id, "経歴を更新してください：{$title}", "「{$title}」が終わりました。おつかれさまでした。\n終わった月、担当したこと、成果を経験票に書いてください（ホームの「経歴の更新」から）。次の案件のスキルシートに使います。", '/eg', true, 'history');
    }
}
