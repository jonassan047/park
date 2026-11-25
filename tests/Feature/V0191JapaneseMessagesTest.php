<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// 入力の検査の文が日本語で出る（v0.19.1 の追加）
class V0191JapaneseMessagesTest extends TestCase
{
    public function test_locale_is_japanese(): void
    {
        $this->assertSame('ja', app()->getLocale());
    }

    public function test_confirmed_mismatch_is_japanese(): void
    {
        $v = Validator::make(['password' => 'abcd1234', 'password_confirmation' => 'zzzz9999'], ['password' => 'required|min:8|confirmed'], [], ['password' => 'パスワード']);
        $this->assertSame('パスワードと確認用の入力が一致しません。', $v->errors()->first());
    }

    public function test_common_rules_are_japanese_even_without_attribute_names(): void
    {
        $v = Validator::make(['email' => 'x', 'password' => 'a'], ['email' => 'required|email', 'password' => 'min:8', 'name' => 'required']);
        $all = $v->errors()->all();
        $this->assertCount(3, $all);
        foreach ($all as $m) $this->assertDoesNotMatchRegularExpression('/\b(The|field|must|is required)\b/', $m);
        $this->assertContains('メールアドレスは、正しいメールアドレスの形式で入力してください。', $all);
        $this->assertContains('名前を入力してください。', $all);
    }

    public function test_every_english_key_has_a_japanese_line(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $ja = require base_path('lang/ja/validation.php');
        $missing = array_diff(array_keys($en), array_keys($ja));
        $this->assertSame([], array_values($missing));
        foreach (['between', 'gt', 'gte', 'lt', 'lte', 'max', 'min', 'size'] as $k) {
            $this->assertSame(array_keys($en[$k]), array_keys($ja[$k]), $k);
        }
    }
}
