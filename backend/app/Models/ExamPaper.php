<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ExamPaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'total_score',
        'total_time',
        'question_count',
        'type',
        'created_by',
        'status',
        'published_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'total_time' => 'integer',
        'question_count' => 'integer',
        'type' => 'string',
        'created_by' => 'integer',
        'status' => 'boolean',
        'published_at' => 'datetime',
    ];

    public const TYPE_FIXED = 'fixed';
    public const TYPE_RANDOM = 'random';

    public const TYPES = [
        self::TYPE_FIXED => '固定题目',
        self::TYPE_RANDOM => '随机抽题',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'exam_paper_questions')
            ->withPivot('id', 'sort_order', 'score', 'question_version')
            ->orderBy('exam_paper_questions.sort_order');
    }

    public function examRecords()
    {
        return $this->hasMany(ExamRecord::class, 'exam_paper_id');
    }

    public function isPublished(): bool
    {
        return (int) $this->status === 1 && $this->published_at !== null;
    }

    /**
     * 发布 / 重新发布：把每道题锁定到当前最新版本。
     * 首次发布记录发布时间；重新发布只更新版本引用，供补考使用新版题。
     */
    public function publish(): void
    {
        DB::transaction(function () {
            $wasPublished = $this->isPublished();

            $this->status = 1;
            if (!$wasPublished) {
                $this->published_at = now();
            }
            $this->save();

            $rows = DB::table('exam_paper_questions')
                ->where('exam_paper_id', $this->id)
                ->get();

            foreach ($rows as $row) {
                $currentVersion = DB::table('questions')
                    ->where('id', $row->question_id)
                    ->value('current_version');

                DB::table('exam_paper_questions')
                    ->where('id', $row->id)
                    ->update([
                        'question_version' => $currentVersion ?: 1,
                    ]);
            }
        });
    }

    public function updateQuestionCountAndScore()
    {
        $this->question_count = $this->questions()->count();
        $this->total_score = $this->questions()->sum('exam_paper_questions.score');
        $this->save();
    }
}
