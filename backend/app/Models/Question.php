<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'type',
        'title',
        'options',
        'answer',
        'analysis',
        'difficulty',
        'score',
        'created_by',
        'status',
        'current_version',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'type' => 'string',
        'options' => 'array',
        'difficulty' => 'integer',
        'score' => 'decimal:2',
        'created_by' => 'integer',
        'status' => 'boolean',
        'current_version' => 'integer',
    ];

    public const TYPE_SINGLE_CHOICE = 'single_choice';
    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const TYPE_TRUE_FALSE = 'true_false';
    public const TYPE_FILL_BLANK = 'fill_blank';
    public const TYPE_ESSAY = 'essay';

    public const TYPES = [
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE,
        self::TYPE_FILL_BLANK,
        self::TYPE_ESSAY,
    ];

    public const DIFFICULTY_EASY = 1;
    public const DIFFICULTY_MEDIUM = 2;
    public const DIFFICULTY_HARD = 3;

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY => '简单',
        self::DIFFICULTY_MEDIUM => '中等',
        self::DIFFICULTY_HARD => '困难',
    ];

    public function category()
    {
        return $this->belongsTo(QuestionCategory::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function examPapers()
    {
        return $this->belongsToMany(ExamPaper::class, 'exam_paper_questions')
            ->withPivot('sort_order', 'score', 'question_version');
    }

    public function versions()
    {
        return $this->hasMany(QuestionVersion::class, 'question_id')->orderByDesc('version');
    }

    /**
     * 判断本次修改是否涉及需要锁定的内容（题干/选项/答案/解析）
     */
    public static function contentChanged(Question $question, array $data): bool
    {
        $contentFields = ['title', 'options', 'answer', 'analysis'];

        foreach ($contentFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $newValue = $field === 'options'
                ? json_encode($data[$field], JSON_UNESCAPED_UNICODE)
                : (string) $data[$field];
            $oldValue = $field === 'options'
                ? json_encode($question->options, JSON_UNESCAPED_UNICODE)
                : (string) $question->{$field};

            if ($newValue !== $oldValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * 为题目生成一个新版本快照，并把题目表更新为最新版本。
     * 仅在题干/选项/答案/解析变化时调用。
     */
    public function createNewVersion(array $data, int $editorId, ?string $summary = null): QuestionVersion
    {
        $nextVersion = ($this->current_version ?? 1) + 1;

        return DB::transaction(function () use ($data, $editorId, $summary, $nextVersion) {
            $version = QuestionVersion::create([
                'question_id' => $this->id,
                'version' => $nextVersion,
                'type' => $data['type'] ?? $this->type,
                'title' => $data['title'] ?? $this->title,
                'options' => $data['options'] ?? $this->options,
                'answer' => $data['answer'] ?? $this->answer,
                'analysis' => $data['analysis'] ?? $this->analysis,
                'difficulty' => $data['difficulty'] ?? $this->difficulty,
                'created_by' => $editorId,
                'change_summary' => $summary ?? ('第 ' . $nextVersion . ' 版：教师修订了题干/选项/答案/解析'),
                'created_at' => now(),
            ]);

            $this->forceFill(['current_version' => $nextVersion])->save();

            return $version;
        });
    }
}
