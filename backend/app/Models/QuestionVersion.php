<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'question_id',
        'version',
        'type',
        'title',
        'options',
        'answer',
        'analysis',
        'difficulty',
        'created_by',
        'change_summary',
        'created_at',
    ];

    protected $casts = [
        'question_id' => 'integer',
        'version' => 'integer',
        'options' => 'array',
        'difficulty' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
