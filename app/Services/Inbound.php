<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Support\MailParser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// 案内メールへの返信を受け取って、どの案内メールへの返事かを探し、担当者に知らせる（v0.12.0）。
// 受信箱から読む部分は MailFetch、ここは「1通のメール（.eml の生の文）」を扱う。
// 設定（.env の PARK_IMAP_*）が空の間は、受信の取り込みは何もしない（ingest は手で渡せば動く）。
class Inbound
{
    const KIND = 'inbound';                    // お知らせ・記録の種類
    const MAX_RAW_BYTES = 20 * 1024 * 1024;    // これより大きいメールは、本文を読まない
    const PREVIEW_LEN = 200;                   // お知らせに載せる、返信の頭の字数
    const MASK_TEXT = '（伏せています）';
    const STATUSES = ['linked' => '紐付け済み', 'unlinked' => '未紐付け', 'ignored' => '対象外'];
    const MATCH_BY = ['token' => '返信先のトークン', 'in_reply_to' => '返信元のメール', 'subject' => '件名の案内番号', 'body' => '本文の案内番号・リンク・案件 ID', 'manual' => '管理者が手で'];

    // ---------- 設定 ----------

    // 受信が使えるか：host・user・password がそろっているとき
    public static function enabled(): bool
    {
        $c = (array) config('park.inbound');
        return !empty($c['host']) && !empty($c['user']) && !empty($c['password']);
    }

    // 返信先の [@ の前, ドメイン]。reply_base が空なら MAIL_FROM_ADDRESS
    private static function base(): array
    {
        $addr = (string) (config('park.inbound.reply_base') ?: config('mail.from.address'));
        $p = strrpos($addr, '@');
        return $p === false ? [$addr, ''] : [substr($addr, 0, $p), substr($addr, $p + 1)];
    }

    // 案内メールの返信先：park+<トークン>@jona.jp の形
    public static function replyAddress(string $token): string
    {
        [$local, $domain] = self::base();
        return $local.'+'.$token.'@'.$domain;
    }

    // ---------- 1通を受け取る ----------

    // 戻り：['status' => 'saved'|'duplicate'|'self'|'too_large', 'id' => ?int]
    public static function ingest(string $raw, string $source, ?int $uidvalidity = null, ?int $uid = null): array
    {
        $size = strlen($raw);
        $tooLarge = $size > self::MAX_RAW_BYTES;
        $p = MailParser::parse($raw, $tooLarge);

        // PARK 自身が送ったメール（見本宛ての差し替え・テストメールが受信箱に戻ってくる）は記録しない
        if ($p['from_email'] !== '' && $p['from_email'] === strtolower((string) config('mail.from.address'))) return ['status' => 'self', 'id' => null];

        $hash = hash('sha256', $raw);
        $mid = $p['message_id'] ? mb_substr($p['message_id'], 0, 255) : null;
        if (DB::table('inbound_mails')->where('raw_hash', $hash)->exists() || ($mid && DB::table('inbound_mails')->where('message_id', $mid)->exists())) {
            return ['status' => 'duplicate', 'id' => null];
        }

        $now = now_str();
        try {
            $id = (int) DB::table('inbound_mails')->insertGetId([
                'source' => $source, 'imap_uidvalidity' => $uidvalidity, 'imap_uid' => $uid, 'message_id' => $mid, 'raw_hash' => $hash, 'raw_size' => $size,
                'fetched_at' => $now, 'received_at' => $p['date'] ?? $now,
                'from_email' => mb_substr($p['from_email'], 0, 255), 'from_name' => mb_substr($p['from_name'], 0, 255), 'to_addrs' => implode(', ', $p['to']),
                'subject' => $p['subject'], 'in_reply_to' => $p['in_reply_to'], 'references' => $p['references'],
                'body_text' => $tooLarge ? '大きすぎるため本文を読んでいません。' : $p['body_text'],
                'attachments' => json_encode($p['attachments'], JSON_UNESCAPED_UNICODE), 'auto' => self::autoKind($p), 'status' => 'unlinked',
            ]);
        } catch (QueryException $e) {
            // 同時に同じメールが入った（unique に当たった）ときだけ、重複として扱う
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) return ['status' => 'duplicate', 'id' => null];
            throw $e;
        }

        // 元のメールを、公開しない場所に残す
        $path = 'inbound/'.date('Y/m').'/'.$id.'.eml';
        Storage::disk('local')->put($path, $raw);
        DB::table('inbound_mails')->where('id', $id)->update(['raw_path' => $path]);

        if (!$tooLarge && ($found = self::match($p))) {
            DB::table('inbound_mails')->where('id', $id)->update($found);
            ProviderReply::draftFromInbound($id); // エンジニアの返信なら、プロバイダーへの返信の下書きを作る（v0.13.0）
        }
        self::notify($id);
        return ['status' => $tooLarge ? 'too_large' : 'saved', 'id' => $id];
    }

    // 不達の通知（bounce）か、自動返信（auto）か
    private static function autoKind(array $p): ?string
    {
        $h = $p['headers'];
        if (preg_match('/^(mailer-daemon|postmaster)@/', $p['from_email']) || str_contains($p['content_type'], 'multipart/report')) return 'bounce';
        $auto = strtolower(trim(MailParser::header($h, 'auto-submitted')));
        if (($auto !== '' && $auto !== 'no') || isset($h['x-autoreply']) || isset($h['x-autorespond'])) return 'auto';
        return null;
    }

    // ---------- 紐付け ----------

    // 見つかれば、inbound_mails に入れる項目（status・match_by・案件など）を返す。なければ null
    public static function match(array $p): ?array
    {
        $from = $p['from_email'];
        $body = $p['body_text'];

        // 1. 宛先のトークン（park+<トークン>@…）
        [$local] = self::base();
        foreach ($p['to'] as $addr) {
            if (preg_match('/^'.preg_quote(strtolower($local), '/').'\+([a-z0-9]{'.ReplyTokens::LENGTH.'})@/', $addr, $m) && ($t = ReplyTokens::find($m[1]))) {
                return self::linkFields('token', $t, null, $from);
            }
        }
        // 2. In-Reply-To / References の Message-ID
        $ids = array_merge(MailParser::messageIds($p['in_reply_to']), MailParser::messageIds($p['references']));
        if ($ids) {
            $t = DB::table('reply_tokens')->whereIn('message_id', $ids)->orderByDesc('id')->first();
            // 他社のエンジニアあてで、返信先を PARK に向けていなかったもの（v0.12.0 より前など）は、自動では紐付けない
            if ($t && !self::groupWithoutPark($t, null)) return self::linkFields('in_reply_to', $t, null, $from);
        }
        // 3・4 は「返信の形」のメールだけ（案内番号の入った案内メールを、転送や新しい宛先で PARK に送っても、案件の担当者に届かないように）
        if (!self::isReplyShape($p)) return null;
        // プロバイダーの返事：件名の [No.xxxxxxxxxx]・本文の「お問い合わせ番号：No.xxxxxxxxxx」（差出人がそのプロバイダーのアドレスのときだけ。v0.13.0）
        $plen = ReplyTokens::TAG_LENGTH;
        if (preg_match('/\[No\.([a-z0-9]{'.$plen.'})\]/i', $p['subject'], $m) && ($t = self::providerTokenByTag(strtolower($m[1]), $from))) {
            return self::linkFields('subject', $t, null, $from);
        }
        if (preg_match('/お問い合わせ番号[:：]\s*No\.([a-z0-9]{'.$plen.'})/iu', $body, $m) && ($t = self::providerTokenByTag(strtolower($m[1]), $from))) {
            return self::linkFields('body', $t, null, $from);
        }
        // 3. 件名の [PARK-xxxxxxxxxx]（差出人が エンジニアの登録アドレスのときだけ）
        // （追加の宛先 v0.15.0：同じ印で、差出人がその宛先のアドレスのときだけ）
        if (preg_match('/\[PARK-([a-z0-9]{'.ReplyTokens::TAG_LENGTH.'})\]/i', $p['subject'], $m) && ($t = self::tokenByTag(strtolower($m[1]), $from) ?: self::extraTokenByTag(strtolower($m[1]), $from))) {
            return self::linkFields('subject', $t, null, $from);
        }
        // 4. 本文
        if (preg_match('/案内番号[:：]\s*PARK-([a-z0-9]{'.ReplyTokens::TAG_LENGTH.'})/iu', $body, $m) && ($t = self::tokenByTag(strtolower($m[1]), $from) ?: self::extraTokenByTag(strtolower($m[1]), $from))) {
            return self::linkFields('body', $t, null, $from);
        }
        // 返事のリンク /r/<32 桁>（v0.12.0 より前の案内メールへの返信も、これで拾える。128 ビットなので差出人は見ない）
        if (preg_match('#/r/([0-9a-f]{32})#i', $body, $m) && ($cm = DB::table('case_mails')->where('token', strtolower($m[1]))->first())) {
            $t = DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
            return self::groupWithoutPark($t, $cm) ? null : self::linkFields('body', $t, $cm, $from);
        }
        // 案件 ID（CA-…）＋差出人が エンジニアの登録アドレス、その案件・その エンジニアの案内メールが 1 件だけのとき
        if (preg_match('/\bCA-\d{6}\b/', $body, $m) && ($case = DB::table('cases')->where('code', $m[0])->first())) {
            $eids = self::engineerIdsByEmail($from);
            if ($eids) {
                $cms = DB::table('case_mails')->where('case_id', $case->id)->whereIn('engineer_id', $eids)->get();
                if ($cms->count() === 1) {
                    $t = DB::table('reply_tokens')->where('case_mail_id', $cms[0]->id)->first();
                    if (!self::groupWithoutPark($t, $cms[0])) return self::linkFields('body', $t, $cms[0], $from);
                }
            }
        }
        return null;
    }

    // 返信の形か：In-Reply-To がある、または件名が Re:／返信 で始まる。転送（Fwd:／FW:／転送）は返信の形ではない
    public static function isReplyShape(array $p): bool
    {
        $subject = trim((string) $p['subject']);
        if (preg_match('/^(fwd?\s*(\[\d+\])?\s*[:：]|転送\s*[:：]?)/iu', $subject)) return false;
        if (trim((string) $p['in_reply_to']) !== '') return true;
        return (bool) preg_match('/^(re\s*(\[\d+\])?\s*[:：]|返信\s*[:：]?)/iu', $subject);
    }

    // 他社のエンジニアあて（route=group）で、送ったとき返信先を PARK に向けていなかった（トークン無しの v0.12.0 より前も含む）案内メールか
    private static function groupWithoutPark(?object $token, ?object $cm): bool
    {
        if (!$cm && $token && $token->case_mail_id) $cm = DB::table('case_mails')->find($token->case_mail_id);
        if (!$cm || $cm->route !== 'group') return false;
        return !($token && $token->via_park);
    }

    // 頭 10 字が合うトークンが 1 件だけで、差出人がその エンジニアの登録アドレスのとき、そのトークン
    private static function tokenByTag(string $tag, string $from): ?object
    {
        $rows = DB::table('reply_tokens')->where('kind', ReplyTokens::KIND_CASE_MAIL)->where('token', 'like', $tag.'%')->limit(2)->get();
        if ($rows->count() !== 1) return null;
        $t = $rows[0];
        return $t->engineer_id && self::fromIsEngineer((int) $t->engineer_id, $from) ? $t : null;
    }

    // 追加の宛先へ送ったメールのトークンで、頭 10 字が合うものが 1 件だけ、かつ差出人がその宛先のアドレスのとき
    private static function extraTokenByTag(string $tag, string $from): ?object
    {
        $rows = DB::table('reply_tokens')->where('kind', ReplyTokens::KIND_EXTRA)->where('token', 'like', $tag.'%')->limit(2)->get();
        if ($rows->count() !== 1) return null;
        $t = $rows[0];
        return self::fromIsExtra($t, $from) ? $t : null;
    }

    // 差出人が、そのトークンのメールを送った追加の宛先のアドレスか
    private static function fromIsExtra(object $token, string $from): bool
    {
        if ($from === '' || !$token->search_extra_id) return false;
        $mail = strtolower(trim((string) DB::table('case_search_extras')->where('id', $token->search_extra_id)->value('email')));
        return $mail !== '' && strtolower($from) === $mail;
    }

    // プロバイダーへ送ったメールのトークンで、頭 10 字が合うものが 1 件だけ、かつ差出人がそのプロバイダーのアドレスのとき
    private static function providerTokenByTag(string $tag, string $from): ?object
    {
        $rows = DB::table('reply_tokens')->where('kind', ReplyTokens::KIND_PROVIDER)->where('token', 'like', $tag.'%')->limit(2)->get();
        if ($rows->count() !== 1) return null;
        $t = $rows[0];
        return self::fromIsProvider($t, $from) ? $t : null;
    }

    // 差出人が、そのトークンのメールを送った相手のプロバイダーか（送ったときの宛先と、案件の今のプロバイダーのアドレス）
    private static function fromIsProvider(object $token, string $from): bool
    {
        if ($from === '') return false;
        $pm = $token->provider_mail_id ? DB::table('provider_mails')->find($token->provider_mail_id) : null;
        $mails = [strtolower(trim((string) ($pm->to_email ?? '')))];
        $pid = $pm->provider_id ?? DB::table('cases')->where('id', $token->case_id)->value('provider_id');
        if ($pid) $mails[] = strtolower(trim((string) DB::table('providers')->where('id', $pid)->value('email')));
        return in_array(strtolower($from), array_filter($mails), true);
    }

    // 見つかった案内メール（トークンの行か、case_mails の行）から、記録に入れる項目を作る
    private static function linkFields(string $by, ?object $token, ?object $cm, string $from): ?array
    {
        // プロバイダーへ送ったメールへの返事：エンジニアと案内メールは、送ったメール（provider_mails）から引く
        if ($token && $token->kind === ReplyTokens::KIND_PROVIDER) {
            $pm = $token->provider_mail_id ? DB::table('provider_mails')->find($token->provider_mail_id) : null;
            $caseId = (int) ($token->case_id ?: ($pm->case_id ?? 0));
            if (!$caseId) return null;
            $eid = (int) ($token->engineer_id ?: ($pm->engineer_id ?? 0)) ?: null;
            $case = DB::table('cases')->find($caseId);
            $ses = $token->ses_id ?: ($pm->ses_id ?? null) ?: ($case->source_ses_id ?? null);
            return [
                'status' => 'linked', 'match_by' => $by, 'reply_token_id' => $token->id, 'case_mail_id' => $pm->case_mail_id ?? null,
                'case_id' => $caseId, 'ses_id' => $ses ? (int) $ses : null, 'engineer_id' => $eid, 'sender_kind' => 'provider', 'provider_mail_id' => $token->provider_mail_id,
                'from_mismatch' => !self::fromIsProvider($token, $from), 'linked_at' => now_str(),
            ];
        }
        // 追加の宛先（PARK に登録していない人）への案内への返事：案内メール・エンジニアは無い
        if ($token && $token->kind === ReplyTokens::KIND_EXTRA) {
            $caseId = (int) ($token->case_id ?? 0);
            if (!$caseId) return null;
            $case = DB::table('cases')->find($caseId);
            $ses = $token->ses_id ?: ($case->source_ses_id ?? null);
            return [
                'status' => 'linked', 'match_by' => $by, 'reply_token_id' => $token->id, 'case_mail_id' => null,
                'case_id' => $caseId, 'ses_id' => $ses ? (int) $ses : null, 'engineer_id' => null, 'sender_kind' => 'extra', 'search_extra_id' => $token->search_extra_id,
                'from_mismatch' => !self::fromIsExtra($token, $from), 'linked_at' => now_str(),
            ];
        }
        if (!$cm && $token && $token->case_mail_id) $cm = DB::table('case_mails')->find($token->case_mail_id);
        $caseId = (int) ($cm->case_id ?? $token->case_id ?? 0);
        if (!$caseId) return null;
        $eid = (int) ($cm->engineer_id ?? $token->engineer_id ?? 0) ?: null;
        $case = DB::table('cases')->find($caseId);
        $ses = ($cm->ses_id ?? null) ?: ($token->ses_id ?? null) ?: ($case->source_ses_id ?? null);
        return [
            'status' => 'linked', 'match_by' => $by, 'reply_token_id' => $token->id ?? null, 'case_mail_id' => $cm->id ?? ($token->case_mail_id ?? null),
            'case_id' => $caseId, 'ses_id' => $ses ? (int) $ses : null, 'engineer_id' => $eid,
            'from_mismatch' => $eid ? !self::fromIsEngineer($eid, $from) : true, 'linked_at' => now_str(),
        ];
    }

    // エンジニアの登録のメールアドレス（本人の登録と、ログインのアドレス）。小文字
    public static function engineerEmails(int $engineerId): array
    {
        $a = DB::table('engineers')->where('id', $engineerId)->value('email');
        $b = DB::table('users')->where('engineer_id', $engineerId)->pluck('email')->all();
        return array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim((string) $x)), array_merge([$a], $b)))));
    }

    public static function fromIsEngineer(int $engineerId, string $from): bool
    {
        return $from !== '' && in_array(strtolower($from), self::engineerEmails($engineerId), true);
    }

    // このメールアドレスで登録されているエンジニアの id
    private static function engineerIdsByEmail(string $email): array
    {
        if ($email === '') return [];
        $a = DB::table('engineers')->whereRaw('lower(email) = ?', [strtolower($email)])->pluck('id')->all();
        $b = DB::table('users')->whereRaw('lower(email) = ?', [strtolower($email)])->whereNotNull('engineer_id')->pluck('engineer_id')->all();
        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }

    // ---------- 知らせ ----------

    // 紐付いた返信を、みなもと SES の担当者全員に知らせる（同じ記録に 2 回は出さない）。未紐付けは管理者に（メールなし）
    public static function notify(int $id): void
    {
        $m = DB::table('inbound_mails')->find($id);
        if (!$m || $m->auto) return; // 不達・自動返信は、知らせない
        $claim = fn () => DB::table('inbound_mails')->where('id', $id)->whereNull('notified_at')->update(['notified_at' => now_str()]) > 0;
        if ($m->status === 'unlinked') {
            if ($claim()) {
                N::toAdmins('どの案内メールへの返信か分からない受信メールがあります', "受信メールを案内メールに紐付けられませんでした。\n件名：".mb_substr((string) $m->subject, 0, 80), "/inbound/$id", false, self::KIND);
            }
            return;
        }
        if ($m->status !== 'linked' || !$m->ses_id || !$claim()) return;

        $case = DB::table('cases')->find($m->case_id);
        $tail = '：'.($case->title ?? '案件').Ids::tag($case->code ?? null);
        $e = $m->engineer_id ? DB::table('engineers')->find($m->engineer_id) : null;
        // 下書きができていれば、お知らせの最後に一文足す（返信の中身は入れない）
        $draftNote = DB::table('provider_mails')->where('inbound_mail_id', $id)->where('status', 'draft')->exists() ? "\n\nプロバイダーへの返信の下書きを作りました。確かめて送ってください。" : '';
        if (($m->sender_kind ?? 'eg') === 'provider') {
            // プロバイダーの返事：返信先に担当者のアドレスもあるので、お知らせだけ（メールなし）
            $who = $e ? ($e->initials ?: 'エンジニア') : 'エンジニア';
            N::toSes((int) $m->ses_id, "プロバイダーから返信：".($case->title ?? '案件').Ids::tag($case->code ?? null)."（{$who}）", mb_substr(trim((string) $m->body_text), 0, self::PREVIEW_LEN), "/inbound/$id", false, self::KIND);
        } elseif (self::fromStaff((string) $m->from_email, (int) $m->ses_id)) {
            // 差出人がその会社の担当者（担当者が エンジニアへの案内に「全員に返信」して、返信先が残った）：エンジニアからの返信ではないので、題を変える
            N::toSes((int) $m->ses_id, '担当者から案内メールへの返信'.$tail, mb_substr(trim((string) $m->body_text), 0, self::PREVIEW_LEN), "/inbound/$id", false, self::KIND);
        } elseif (($m->sender_kind ?? 'eg') === 'extra') {
            // 追加の宛先（PARK に登録していない人）の返事：返信先に担当者のアドレスもあるので、お知らせだけ（メールなし）
            N::toSes((int) $m->ses_id, '追加の宛先から案内メールへの返信'.$tail, mb_substr(trim((string) $m->body_text), 0, self::PREVIEW_LEN), "/inbound/$id", false, self::KIND);
        } elseif ($e && (int) $e->ses_id === (int) $m->ses_id) {
            // 自社のエンジニア：本人のメールが担当者に直接届いているので、お知らせだけ
            N::toSes((int) $m->ses_id, "{$e->name} さんから案内メールへの返信".$tail, mb_substr(trim((string) $m->body_text), 0, self::PREVIEW_LEN).$draftNote, "/inbound/$id", false, self::KIND);
        } else {
            // 他社のエンジニア：お知らせとメール。メールに返信の中身は入れない
            $who = $e ? CaseThread::egName((object) ['role' => 'ses', 'ses_id' => (int) $m->ses_id], $e) : 'エンジニア';
            N::toSes((int) $m->ses_id, "{$who} さんから案内メールへの返信".$tail, '返信の内容は PARK の画面で見てください。'.$draftNote, "/inbound/$id", true, self::KIND);
        }
    }

    // 差出人のアドレスが、その会社の担当者（users.role=ses かつ ses_id が同じ）のものか
    public static function fromStaff(string $from, int $sesId): bool
    {
        if ($from === '' || !$sesId) return false;
        return DB::table('users')->where('role', 'ses')->where('ses_id', $sesId)->whereRaw('lower(email) = ?', [strtolower($from)])->exists();
    }

    // ---------- 見える範囲 ----------

    // 一覧・詳細の元の問い合わせ。管理者はすべて、SES・担当者は「紐付け済みで、案件の今のみなもと SES が自社」のものだけ。エンジニアは何も見えない
    public static function query($u)
    {
        $q = DB::table('inbound_mails as i')->leftJoin('cases as c', 'c.id', '=', 'i.case_id')->leftJoin('engineers as e', 'e.id', '=', 'i.engineer_id')
            ->leftJoin('ses_companies as es', 'es.id', '=', 'e.ses_id')->leftJoin('ses_companies as s', 's.id', '=', 'i.ses_id')
            ->leftJoin('providers as pv', 'pv.id', '=', 'c.provider_id')
            ->leftJoin('case_search_extras as x', 'x.id', '=', 'i.search_extra_id')
            ->select('i.*', 'c.title as case_title', 'c.code as case_code', 'c.source_ses_id as case_source', 'e.name as eg_name', 'e.initials as eg_initials', 'e.code as eg_code',
                'e.ses_id as eg_ses_id', 'es.name as eg_ses_name', 's.name as ses_name', 'pv.name as provider_name', 'x.name as extra_name', 'x.email as extra_email');
        if ($u && $u->role === 'ses') $q->where('i.status', 'linked')->where('c.source_ses_id', (int) $u->ses_id);
        elseif (!$u || $u->role !== 'admin') $q->whereRaw('1 = 0');
        return $q;
    }

    public static function visible($u, int $id): ?object
    {
        return self::query($u)->where('i.id', $id)->first();
    }

    // 一覧に出す差出人：管理者は氏名、自社のエンジニアは氏名、他社のエンジニアは「イニシャル（会社名）」
    public static function senderLabel($u, object $row): string
    {
        // プロバイダーの返事：企業名（プロバイダー）。行が消えていれば「プロバイダー」
        if (($row->sender_kind ?? 'eg') === 'provider') return ($row->provider_name ?? null) ? $row->provider_name.'（プロバイダー）' : 'プロバイダー';
        // 追加の宛先：名前（アドレス）。名前が無ければアドレス。行が消えていれば「追加の宛先」
        if (($row->sender_kind ?? 'eg') === 'extra') {
            $mail = (string) ($row->extra_email ?? '');
            if ($mail === '') return '追加の宛先';
            return ($row->extra_name ?? null) ? $row->extra_name.'（'.$mail.'）' : $mail;
        }
        if (!$row->engineer_id || $row->eg_name === null) {
            return $u->role === 'admin' ? trim(($row->from_name ?: '').' '.$row->from_email) : 'エンジニア';
        }
        return CaseThread::egNameRow($u->role === 'ses' ? (int) $u->ses_id : null,
            (object) ['eg_name' => $row->eg_name, 'eg_initials' => $row->eg_initials, 'ses_id' => $row->eg_ses_id, 'ses_name' => $row->eg_ses_name]);
    }

    // 詳細に出す中身（差出人・件名・本文・添付）。SES・担当者が他社のエンジニアの返信を見るときは、そのエンジニアの氏名・ふりがな・メール・電話を伏せる
    public static function masked($u, object $row): object
    {
        $out = clone $row;
        $out->masked = false;
        $out->sender = self::senderLabel($u, $row);
        $out->files = json_decode((string) $row->attachments, true) ?: [];
        if (!$u || $u->role !== 'ses') return $out;
        // 追加の宛先は、自社が入れた宛先（エンジニアではない）。伏せない（見えるのはみなもと SES と管理者だけ）
        // ただし、宛先と違うアドレスから届いた返信（第三者かもしれない）は、エンジニアと同じに伏せる
        $extra = ($row->sender_kind ?? 'eg') === 'extra';
        if ($extra && empty($row->from_mismatch)) return $out;
        if ($extra) $out->sender = '追加の宛先';
        // エンジニアが分からない（行が無い・紐付いていない）ときは、自社と確かめられないので、伏せる側に倒す
        $e = $row->engineer_id ? DB::table('engineers')->find($row->engineer_id) : null;
        if ($e && (int) $e->ses_id === (int) $u->ses_id) return $out;

        $pairs = $e ? self::secrets($e, CaseThread::egName($u, $e)) : [];
        $provider = ($row->sender_kind ?? 'eg') === 'provider';
        // プロバイダーの返事は、差出人（プロバイダーの名前・アドレス）を伏せない。本文の中の他社のエンジニアの氏名などだけ伏せる
        if (!$provider) {
            foreach ([[$row->from_name, $out->sender], [$row->from_email, self::MASK_TEXT], [strtolower((string) $row->from_email), self::MASK_TEXT]] as [$v, $to]) {
                $v = trim((string) $v);
                if (mb_strlen($v) >= 2) $pairs[$v] = $e ? $to : self::MASK_TEXT; // エンジニアが分からないときは、名前も伏せ言葉に
            }
        }
        uksort($pairs, fn ($a, $b) => strlen($b) <=> strlen($a));
        $out->subject = self::scrub((string) $row->subject, $pairs);
        $out->body_text = self::scrub((string) $row->body_text, $pairs);
        foreach ($out->files as &$f) $f['name'] = self::scrub((string) ($f['name'] ?? ''), $pairs);
        unset($f);
        if (!$provider) {
            $out->from_email = self::MASK_TEXT;
            $out->from_name = $e ? $out->sender : self::MASK_TEXT;
        }
        $out->masked = true;
        return $out;
    }

    // エンジニアの氏名・ふりがな・メール・電話を、$label（氏名）と「（伏せています）」に置き換える。プロバイダーに出す文面にも使う（v0.13.0）
    public static function scrubEngineer(object $e, string $text, string $label): string
    {
        return self::scrub($text, self::secrets($e, $label));
    }

    // 本文に残っている、エンジニアの登録の値の種類（氏名・ふりがな・メール・電話）。判定は scrubEngineer と同じ値・同じ比べ方
    public static function leakKinds(object $e, string $text): array
    {
        $out = [];
        $probe = "\u{1}";
        $users = DB::table('users')->where('engineer_id', $e->id)->get(['email', 'phone']);
        $has = fn (?string $v) => mb_strlen(trim((string) $v)) >= 2 && stripos($text, trim((string) $v)) !== false;
        // 氏名：secrets が氏名として置き換えるもの（敬称つきの 1 字の姓も含む）
        foreach (self::secrets($e, $probe) as $v => $to) if ($to === $probe && str_contains($text, (string) $v)) $out['氏名'] = true;
        foreach ([$e->kana ?? null, $e->kana_sei ?? null, $e->kana_mei ?? null] as $k) {
            if ($has($k) || $has(preg_replace('/[\s　]+/u', '', (string) $k))) $out['ふりがな'] = true;
        }
        foreach (array_merge([$e->email ?? null], $users->pluck('email')->all()) as $mail) if ($has($mail)) $out['メール'] = true;
        // 電話は、数字だけにそろえて比べる（ハイフン・全角の違いを越える）
        $found = [];
        if (preg_match_all('/\+?[0-9０-９][0-9０-９\-－ー‐\s().（）]{6,}[0-9０-９]/u', $text, $mm)) {
            foreach ($mm[0] as $s) {
                $d = preg_replace('/\D/', '', mb_convert_kana($s, 'n'));
                $found[] = $d;
                if (str_starts_with($d, '81')) $found[] = '0'.substr($d, 2);
            }
        }
        foreach (array_merge([$e->phone ?? null], $users->pluck('phone')->all()) as $tel) {
            $d = preg_replace('/\D/', '', (string) $tel);
            if (strlen($d) >= 8 && in_array($d, $found, true)) $out['電話'] = true;
        }
        return array_keys($out);
    }

    // 伏せる言葉を置き換える。そのままの一致のあと、メールは大文字小文字を問わず、電話は数字だけにそろえて比べる（ハイフン・全角の違いを越える）
    private static function scrub(string $text, array $pairs): string
    {
        $text = strtr($text, $pairs);
        $phones = [];
        foreach ($pairs as $k => $to) {
            $k = (string) $k;
            if (str_contains($k, '@')) $text = str_ireplace($k, $to, $text);
            elseif (preg_match('/^\d{8,}$/', $k)) $phones[$k] = $to;
        }
        if ($phones) {
            $text = preg_replace_callback('/\+?[0-9０-９][0-9０-９\-－ー‐\s().（）]{6,}[0-9０-９]/u', function ($m) use ($phones) {
                $digits = preg_replace('/\D/', '', mb_convert_kana($m[0], 'n'));
                if (str_starts_with($digits, '81')) $alt = '0'.substr($digits, 2); // +81 の形
                return $phones[$digits] ?? (isset($alt) ? ($phones[$alt] ?? $m[0]) : $m[0]);
            }, $text) ?? $text;
        }
        return $text;
    }

    // 伏せる言葉 => 置き換える言葉（長いものから先に置き換わる）
    private static function secrets(object $e, string $label): array
    {
        $pairs = [];
        $add = function (?string $v, string $to) use (&$pairs) {
            $v = trim((string) $v);
            if (mb_strlen($v) >= 2) $pairs[$v] = $to;
        };
        foreach (array_filter([$e->name ?? null, $e->nickname ?? null, $e->name_sei ?? null, $e->name_mei ?? null]) as $n) {
            $add($n, $label);
            $add(preg_replace('/[\s　]+/u', '', $n), $label);
            $add(preg_replace('/[\s　]+/u', ' ', $n), $label);
        }
        // 姓が 1 字（林・森など）で、姓と名がそろっているときは、「林さん」のように敬称が付いた形だけ置き換える（1 字だけでは他の語に当たりすぎる）
        if (mb_strlen(trim((string) ($e->name_sei ?? ''))) === 1 && trim((string) ($e->name_mei ?? '')) !== '') {
            foreach (['さん', '様', '君', 'くん', 'クン'] as $suffix) $pairs[trim($e->name_sei).$suffix] = $label;
        }
        foreach ([$e->kana ?? null, $e->kana_sei ?? null, $e->kana_mei ?? null] as $k) {
            $add($k, self::MASK_TEXT);
            $add(preg_replace('/[\s　]+/u', '', (string) $k), self::MASK_TEXT);
        }
        $users = DB::table('users')->where('engineer_id', $e->id)->get(['email', 'phone']);
        foreach (array_merge([$e->email ?? null], $users->pluck('email')->all()) as $mail) {
            $add($mail, self::MASK_TEXT);
            $add(strtolower((string) $mail), self::MASK_TEXT);
        }
        foreach (array_merge([$e->phone ?? null], $users->pluck('phone')->all()) as $tel) {
            $add($tel, self::MASK_TEXT);
            $digits = preg_replace('/\D/', '', (string) $tel);
            if (strlen($digits) >= 8) $add($digits, self::MASK_TEXT);
        }
        uksort($pairs, fn ($a, $b) => strlen($b) <=> strlen($a));
        return $pairs;
    }

    // 担当者が開いたとき：会社で 1 つの「読んだ」印
    public static function markRead($u, object $row): void
    {
        if ($u && $u->role === 'ses' && $row->status === 'linked' && !$row->ses_read_at) {
            DB::table('inbound_mails')->where('id', $row->id)->whereNull('ses_read_at')->update(['ses_read_at' => now_str()]);
        }
    }

    // 「今やること」用：その会社が読んでいない返信（自動のものは除く）
    public static function unreadForSes(int $sesId)
    {
        return self::query((object) ['role' => 'ses', 'ses_id' => $sesId])->whereNull('i.ses_read_at')->whereNull('i.auto')->orderBy('i.id')->get();
    }

    public static function unlinkedCount(): int
    {
        return DB::table('inbound_mails')->where('status', 'unlinked')->count();
    }

    // ---------- 管理者が手で直す ----------

    // 未紐付けの 1 件を、案内メールに紐付ける。戻り：['type' => 'ok'|'bad', 'msg' => 文]
    // $caseId は、画面で探した案件の id。渡されたとき、その案件の案内メールでなければ断る
    public static function link($admin, int $id, int $caseMailId, ?int $caseId = null): array
    {
        $m = DB::table('inbound_mails')->find($id);
        if (!$m) return ['type' => 'bad', 'msg' => '受信メールが見つかりません。'];
        if ($m->status === 'linked') return ['type' => 'bad', 'msg' => 'もう紐付いています。'];
        if ($m->status === 'ignored') return ['type' => 'bad', 'msg' => '対象外のメールです。先に「未紐付けに戻す」を押してください。'];
        $cm = DB::table('case_mails')->find($caseMailId);
        if (!$cm) return ['type' => 'bad', 'msg' => '案内メールが見つかりません。'];
        if ($caseId && (int) $cm->case_id !== $caseId) return ['type' => 'bad', 'msg' => '探した案件の案内メールではありません。もう一度探してください。'];
        $case = DB::table('cases')->find($cm->case_id);
        $token = DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
        $ses = $cm->ses_id ?: ($case->source_ses_id ?? null);
        // 同時に 2 回押されても、1 回だけ紐付く
        $done = DB::table('inbound_mails')->where('id', $id)->where('status', 'unlinked')->update([
            'status' => 'linked', 'match_by' => 'manual', 'reply_token_id' => $token->id ?? null, 'case_mail_id' => $cm->id, 'case_id' => $cm->case_id,
            'ses_id' => $ses ? (int) $ses : null, 'engineer_id' => $cm->engineer_id, 'from_mismatch' => !self::fromIsEngineer((int) $cm->engineer_id, (string) $m->from_email),
            'linked_by' => $admin->id, 'linked_at' => now_str(), 'notified_at' => null,
        ]);
        if (!$done) return ['type' => 'bad', 'msg' => 'もう紐付いています。'];
        N::audit($admin->id, 'inbound-link', "#$id → 案件 ".($case->code ?? $cm->case_id)." 案内メール #{$cm->id}");
        ProviderReply::draftFromInbound($id);
        self::notify($id);
        return ['type' => 'ok', 'msg' => '紐付けました。'.($ses ? 'みなもと SES の担当者に知らせました。' : '')];
    }

    public static function unlink($admin, int $id): array
    {
        $done = DB::table('inbound_mails')->where('id', $id)->where('status', 'linked')->update([
            'status' => 'unlinked', 'match_by' => null, 'reply_token_id' => null, 'case_mail_id' => null, 'case_id' => null, 'ses_id' => null, 'engineer_id' => null,
            'from_mismatch' => false, 'linked_by' => null, 'linked_at' => null, 'notified_at' => null, 'ses_read_at' => null,
            'sender_kind' => 'eg', 'provider_mail_id' => null, 'search_extra_id' => null,
        ]);
        if (!$done) return ['type' => 'bad', 'msg' => '紐付いていません。'];
        // その受信メールから作った下書きは取りやめにする（送ったものは残す）
        DB::table('provider_mails')->where('inbound_mail_id', $id)->where('status', 'draft')->update(['status' => 'discarded', 'note' => '受信の紐付けを外したため', 'updated_at' => now_str()]);
        N::audit($admin->id, 'inbound-unlink', "#$id");
        return ['type' => 'ok', 'msg' => '紐付けを外しました。'];
    }

    public static function ignore($admin, int $id, ?string $note): array
    {
        $done = DB::table('inbound_mails')->where('id', $id)->where('status', 'unlinked')->update(['status' => 'ignored', 'note' => $note]);
        if (!$done) return ['type' => 'bad', 'msg' => '未紐付けのメールだけ、対象外にできます。'];
        N::audit($admin->id, 'inbound-ignore', "#$id".($note ? '：'.mb_substr($note, 0, 200) : ''));
        return ['type' => 'ok', 'msg' => '対象外にしました。'];
    }

    public static function restore($admin, int $id): array
    {
        $done = DB::table('inbound_mails')->where('id', $id)->where('status', 'ignored')->update(['status' => 'unlinked']);
        if (!$done) return ['type' => 'bad', 'msg' => '対象外のメールではありません。'];
        N::audit($admin->id, 'inbound-restore', "#$id");
        return ['type' => 'ok', 'msg' => '未紐付けに戻しました。'];
    }
}
