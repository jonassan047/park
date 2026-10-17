<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

// ログインする人（管理者・SES の担当者・エンジニア）。role で画面が分かれる
class User extends Authenticatable
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'mail_opt_in' => 'boolean',
            'ses_id' => 'integer',
            'engineer_id' => 'integer',
        ];
    }

    public function home(): string
    {
        return ['admin' => '/admin', 'ses' => '/ses', 'eg' => '/eg'][$this->role] ?? '/';
    }
}
