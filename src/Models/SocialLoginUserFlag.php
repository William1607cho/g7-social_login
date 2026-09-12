<?php

namespace Plugins\G7\SocialLogin\Models;

use Illuminate\Database\Eloquent\Model;

class SocialLoginUserFlag extends Model
{
    protected $table = 'g7_social_login_user_flags';

    protected $fillable = [
        'user_id',
        'has_real_password',
    ];

    protected function casts(): array
    {
        return [
            'has_real_password' => 'boolean',
        ];
    }
}
