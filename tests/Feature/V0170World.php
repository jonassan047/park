<?php

namespace Tests\Feature;

use App\Services\Ids;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// v0.17.0 のテストで共通の道具：A 社（受注 SES）・B 社（パートナー）・C 社（関係ない会社）と、それぞれの担当者・EG。
// 名前・アドレスはすべて架空（@park.example / @example.test）。テストのクラスは RefreshDatabase と $seed = true を自分で使い、setUp で setUpWorld() を呼ぶ
trait V0170World
{
    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';   // A 社（受注 SES）
    private const SES2 = 'ses2@park.example';   // B 社（パートナー）
    private const SES3 = 'ses3@park.example';   // C 社（関係ない会社）
    private const BX = 'bx17@example.test';     // B 社の担当者 X（SES＝代表者にする）
    private const BY = 'by17@example.test';     // B 社の担当者 Y
    private const AS2 = 'as17@example.test';    // A 社の別の担当者
    private const RAW = "架空のメール本文PROVRAW-MARKER-17\n■必須\n・Java\n・SQL";
    private const PROV_NAME = '架空プロバイダー株式会社';
    private const PROV_MAIL = 'prov17@example.test';
    private const PROV_TEL = '03-0000-1717';

    protected function setUpWorld(): void
    {
        Storage::fake('local');
        config(['mail.from.address' => 'park@example.test']);
        DB::table('engineers')->update(['status' => 'unavailable']); // テストで作る人だけで確かめる
        $b = $this->sesId(self::SES2);
        foreach ([[self::BX, '架空 担当X', $b], [self::BY, '架空 担当Y', $b], [self::AS2, '架空 担当A', $this->sesId(self::SES1)]] as [$mail, $name, $ses]) {
            $this->completeProfile(W::createUser($mail, $name, 'ses', 'park1234', ['ses_id' => $ses]));
        }
        DB::table('ses_companies')->where('id', $b)->update(['rep_user_id' => $this->user(self::BX)->id]); // B 社の SES（代表者）は X
        \App\Services\EgStaff::fillMissing(); // 見本の EG の担当者もそろえる（担当者が空の数を、テストで数えやすくする）
    }

    protected function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    protected function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    // プロバイダーを作る
    protected function mkProvider(string $name = self::PROV_NAME, string $email = self::PROV_MAIL): int
    {
        return (int) DB::table('providers')->insertGetId(['code' => Ids::next('PV'), 'name' => $name, 'sei' => '架空', 'mei' => '提供', 'email' => $email, 'phone' => self::PROV_TEL,
            'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // 案件（受注 SES は $by の会社）。プロバイダー・元請け・金額のメモ・原文の目印を入れる
    protected function mkCase(array $over = [], string $by = self::SES1, ?int $provider = null): int
    {
        $cid = W::saveCase($over + ['title' => '十七テスト案件', 'price' => 60, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS',
            'description' => '架空の作業内容', 'status' => 'open', 'raw_text' => self::RAW], $this->user($by));
        DB::table('cases')->where('id', $cid)->update(['client_label' => '元請け架空ZZ', 'price_note' => '精算幅あり架空', 'provider_id' => $provider ?? $this->mkProvider(self::PROV_NAME.'-'.$cid, "p{$cid}@example.test")]);
        return $cid;
    }

    protected function mkEng(string $sesEmail, string $name, string $initials, array $over = []): int
    {
        $id = W::createEngineer($this->sesId($sesEmail), ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => $initials,
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        SkillChecks::saveFromNames($id, [['Java', 'lang', 12], ['SQL', 'db', 12]], 'seed');
        return $id;
    }

    // エンジニアのログイン（本登録済み）を作り、そのメールを返す
    protected function egLogin(int $engineerId, string $email): string
    {
        $this->completeProfile(W::createUser($email, '架空 EG', 'eg', 'park1234', ['engineer_id' => $engineerId]));
        return $email;
    }

    protected function search(int $cid, string $as = self::SES1): int
    {
        $this->as($as)->post("/cases/$cid/search", [])->assertRedirect();
        return (int) DB::table('case_searches')->where('case_id', $cid)->where('created_by', $this->user($as)->id)->value('id');
    }

    // A 社の EG（a1）と B 社の EG（b1）がいる、募集中の案件と検索
    protected function world(array $caseOver = []): array
    {
        $cid = $this->mkCase($caseOver);
        $a1 = $this->mkEng(self::SES1, '自社 太郎', 'A.A');
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $sid = $this->search($cid);
        return compact('cid', 'a1', 'b1', 'sid');
    }

    protected function bodyOf(string $as, int $sid): string
    {
        $html = $this->as($as)->get("/searches/$sid/compose")->assertOk()->getContent();
        preg_match('/<textarea name="body"[^>]*>(.*?)<\/textarea>/s', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }

    // A 社から B 社の担当者へ、紹介の依頼を送る（単価を $price に直す）。case_search_staff の id を返す
    protected function sendReferral(array $w, string $price = '50万円'): int
    {
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['b1']]])->assertRedirect("/searches/{$w['sid']}/compose");
        $stid = (int) DB::table('case_search_staff')->where('search_id', $w['sid'])->value('id');
        $body = str_replace('■単価：60万円', '■単価：'.$price, $this->bodyOf(self::SES1, $w['sid']));
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/staff/$stid", ['subject' => '【紹介のお願い】十七テスト案件', 'body' => $body])->assertRedirect();
        return $stid;
    }

    // B 社の担当者 X が、自社の EG（b1）に案内を送る。案内メール（mode=partner）の行を返す
    protected function partnerMail(array $w, string $as = self::BX): object
    {
        $stid = $this->sendReferral($w);
        $this->as($as)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user($as)->id)->value('id');
        $this->as($as)->post("/searches/$sid/compose", ['pick' => [$w['b1']]])->assertRedirect();
        $this->as($as)->post("/searches/$sid/compose/proper", ['subject' => '件名', 'body' => $this->bodyOf($as, $sid)])->assertRedirect();
        return DB::table('case_mails')->where('engineer_id', $w['b1'])->first();
    }
}
