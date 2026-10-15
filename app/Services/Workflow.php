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

    public static function invite(array $d, $actor, string $note = ''): ?object
    {
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!$email) return null;
        $u = DB::table('users')->where('email', $email)->first();
        if ($u && $u->password) return $u; // すでに使っている人は招待しない
        if (!$u) {
            $id = DB::table('users')->insertGetId(['email' => $email, 'name' => $d['name'], 'role' => $d['role'], 'ses_id' => $d['ses_id'] ?? null,
                'engineer_id' => $d['engineer_id'] ?? null, 'created_at' => now_str(), 'updated_at' => now_str()]);
            $u = DB::table('users')->find($id);
        }
        $t = self::issueToken($u->id);
        $what = ['eg' => 'エンジニアとして PARK に登録されました。', 'ses' => 'SES のご担当者として PARK にご招待します。', 'admin' => 'PARK の運営メンバーに招待されました。'][$d['role']];
        N::queueMail($email, $d['name'], 'ご招待：パスワードを設定してください',
            "{$d['name']} 様\n\n{$what}".($note ? "\n$note" : '')."\n\n次のリンクから、パスワードを設定してください（7日間有効）。\n".url("/invite/$t")."\n\n"
            .($d['role'] === 'eg' ? 'パスワードの設定のときに、個人情報の取り扱いへの同意をお願いしています。スキルシートは、事実にもとづいて一緒に作ります。' : ''), 'invite');
        N::audit($actor->id ?? null, 'invite', lbl('role', $d['role'])." {$d['name']} <$email>");
        return $u;
    }

    public static function createUser(string $email, string $name, string $role, string $password, array $extra = []): int
    {
        return DB::table('users')->insertGetId(['email' => strtolower($email), 'name' => $name, 'role' => $role, 'password' => Hash::make($password),
            'ses_id' => $extra['ses_id'] ?? null, 'engineer_id' => $extra['engineer_id'] ?? null, 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // ---------- SES ----------
    public static function applySes(array $d): int
    {
        $id = DB::table('ses_companies')->insertGetId([
            'name' => str_in($d['name'] ?? ''), 'rep_name' => str_in($d['rep_name'] ?? ''), 'contact_name' => str_in($d['contact_name'] ?? ''),
            'email' => strtolower((string) str_in($d['email'] ?? '')), 'phone' => str_in($d['phone'] ?? ''), 'address' => str_in($d['address'] ?? ''),
            'employees' => int_or_null($d['employees'] ?? null), 'strengths' => str_in($d['strengths'] ?? ''), 'message' => str_in($d['message'] ?? ''),
            'notes' => str_in($d['notes'] ?? ''), 'status' => 'pending', 'agreed_at' => !empty($d['agree']) ? now_str() : null, 'created_at' => now_str(), 'updated_at' => now_str(),
        ]);
        $name = str_in($d['name'] ?? ''); $contact = str_in($d['contact_name'] ?? '');
        N::toAdmins('SES から参加の申し込みがありました', "{$name}（{$contact} 様）から参加の申し込みがありました。内容を確認し、承認してください。", "/admin/ses/$id", true, 'ses-apply');
        N::queueMail(str_in($d['email'] ?? ''), $contact, '参加のお申し込みを受け付けました', "{$contact} 様\n\nPARK への参加のお申し込みを受け付けました。運営で内容を確認のうえ、ご連絡します。\n\n会社名：{$name}", 'ses-apply');
        return $id;
    }

    public static function approveSes(int $sesId, $actor): void
    {
        $s = DB::table('ses_companies')->find($sesId);
        DB::table('ses_companies')->where('id', $sesId)->update(['status' => 'active', 'joined_at' => $s->joined_at ?: today_str(), 'updated_at' => now_str()]);
        N::audit($actor->id, 'ses-approve', $s->name);
        if ($s->email) self::invite(['email' => $s->email, 'name' => $s->contact_name ?: ($s->rep_name ?: $s->name), 'role' => 'ses', 'ses_id' => $sesId], $actor, "{$s->name} の PARK への参加を承認しました。");
    }

    // ---------- エンジニア ----------
    const ENG_FIELDS = ['name', 'kana', 'initials', 'email', 'birth_year', 'nearest_station', 'years_exp', 'summary', 'strengths', 'desired_price', 'min_price', 'available_from', 'work_style', 'days_per_week', 'status',
        // 履歴書・業務経歴書の項目（2026-10-16）
        'gender', 'phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education', 'graduated_ym', 'career_summary', 'desired_roles', 'english_level', 'appeal', 'wishes', 'birth_date'];

    private static function engValues(array $d, ?object $old = null): array
    {
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
    }

    public static function saveSkills(int $engineerId, array $d): void
    {
        $names = (array) ($d['skill_name'] ?? []); $cats = (array) ($d['skill_cat'] ?? []);
        $years = (array) ($d['skill_years'] ?? []); $levels = (array) ($d['skill_level'] ?? []);
        DB::transaction(function () use ($engineerId, $names, $cats, $years, $levels) {
            DB::table('engineer_skills')->where('engineer_id', $engineerId)->delete();
            foreach ($names as $i => $n) {
                if (trim((string) $n) === '') continue;
                // 技術マスタにある言葉は正式な名前・分類にそろえる。無い言葉はマスタに足す（運営が分類を確かめる）
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
        // 必須・尚可の技術を正式な名前にそろえ、マスタに無いものは足して運営に知らせる
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
                ."\n合う点：".(implode('、', array_slice($s['reasons'], 0, 4)) ?: '—').($message ? "\n\n運営より：$message" : '')."\n\n条件を確認のうえ、応募するか見送るかを選んでください。",
                "/cases/$caseId", true, 'offer');
            $bySes[$e->ses_id][] = "{$e->name}（{$s['score']}点）";
            $count++;
        }
        foreach ($bySes as $sesId => $names) {
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
        $who = ['eg' => 'ご本人', 'ses' => '所属の SES', 'admin' => '運営'][$actor->role];
        N::toAdmins("応募がありました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました（{$who}から）。スキルシートを案件に合わせて整え、提出してください。", "/applications/$appId", true, 'apply');
        N::toSes($e->ses_id, "貴社のエンジニアが応募しました：{$c->title}", "{$e->name} さんが「{$c->title}」に応募しました。選考の進み具合は PARK でご確認いただけます。", "/applications/$appId", true, 'apply');
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
        if ($status === 'withdrawn') N::toAdmins("応募の取り下げ：{$a->title}", "{$a->eg_name} さんが応募を取り下げました。$note", "/applications/$appId", false);
        N::audit($actor->id, 'app-status', "{$a->eg_name} / {$a->title} → $label");
        self::refreshEngineerStatus($a->engineer_id);
    }

    public static function scheduleInterview(int $appId, array $d, $actor): int
    {
        $a = self::loadApp($appId);
        $round = (int) DB::table('interviews')->where('application_id', $appId)->max('round') + 1;
        $when = str_replace('T', ' ', (string) ($d['scheduled_at'] ?? ''));
        $id = DB::table('interviews')->insertGetId(['application_id' => $appId, 'round' => $round, 'scheduled_at' => $when ?: null, 'place' => str_in($d['place'] ?? ''),
            'qa_json' => json_encode(Qa::generate($a->case_id, $a->engineer_id), JSON_UNESCAPED_UNICODE), 'created_at' => now_str()]);
        DB::table('applications')->where('id', $appId)->update(['status' => 'interview', 'updated_at' => now_str()]);
        self::addEvent($appId, 'interview', "{$round}回目 $when ".($d['place'] ?? ''), $actor);
        $whenText = ($when ?: '日時調整中').'／'.(str_in($d['place'] ?? '') ?? '場所・URL は追ってご連絡');
        N::toEg($a->engineer_id, "面談が決まりました：{$a->title}（{$round}回目）", "面談：{$whenText}\n\nこの案件に合わせた「面談対策の Q&A」を用意しました。面談の前に目を通し、自分の言葉で答えられるようにしておいてください。", "/interviews/$id", true, 'interview');
        N::toSes($a->ses_id, "面談が決まりました：{$a->title}", "面談：{$whenText}", "/applications/$appId", true, 'interview');
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
        N::toEg($a->engineer_id, "成約おめでとうございます：{$a->title}", "「{$a->title}」に決まりました。開始は {$start} です。\n最初の1か月に、困りごとがないか短くお話を聞かせてください（PARK のホームから記入できます）。", "/applications/$appId", true, 'won');
        N::toSes($a->ses_id, "成約しました：{$a->title}", "{$a->eg_name} さんが「{$a->title}」に決まりました。\n契約：{$start}〜{$end}／".man($price)."\n成功報酬（初期費用）は、稼働開始の月の請求に含まれます。", "/contracts/$kid", true, 'won');
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
    }
}
