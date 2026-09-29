<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 考试题目版本锁定快照
 * 开考时把试卷中每道题的当时版本完整快照下来，
 * 之后题目被修改/撤回都不影响该次考试。
 */
class ExamRecordQuestion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'exam_record_id',
        'question_id',
        'question_version',
        'type',
        'title',
        'options',
        'answer',
        'analysis',
        'score',
        'sort_order',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'question_id' => 'integer',
        'question_version' => 'integer',
        'options' => 'array',
        'score' => 'decimal:2',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
