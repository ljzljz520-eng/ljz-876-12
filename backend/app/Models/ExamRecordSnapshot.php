<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamRecordSnapshot extends Model
{
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
        'created_at',
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
}
