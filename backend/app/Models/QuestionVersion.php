<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionVersion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'question_id',
        'version',
        'category_id',
        'type',
        'title',
        'options',
        'answer',
        'analysis',
        'difficulty',
        'score',
        'change_summary',
        'created_by',
    ];

    protected $casts = [
        'question_id' => 'integer',
        'version' => 'integer',
        'category_id' => 'integer',
        'options' => 'array',
        'difficulty' => 'integer',
        'score' => 'decimal:2',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
