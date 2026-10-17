<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 顧客からの推薦文。公開してよいかは allow_publish で本人が決める。 */
class Testimonial extends Model
{
    protected $fillable = [
        'company_id',
        'project_id',
        'user_id',
        'rating',
        'body',
        'display_name',
        'allow_publish',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'allow_publish' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
