<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\ExamRecordSnapshot;
use App\Models\Question;
use App\Models\QuestionVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        // 只有已发布的试卷对考生可见
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->whereNotNull('published_at')
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        if (!$examPaper->isPublished()) {
            return response()->json(['message' => '试卷尚未发布，无法开始考试'], 422);
        }

        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            $snapshots = $this->buildSnapshotsIfMissing($existingRecord, $examPaper);

            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
                'exam_paper' => $this->paperPayload($examPaper),
                'questions' => $this->questionPayload($snapshots),
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        // 关键：开考瞬间把题目按试卷锁定版本写入快照，之后题目再改也不影响本次考试
        $snapshots = $this->buildSnapshotsIfMissing($record, $examPaper);

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => $this->paperPayload($examPaper),
            'questions' => $this->questionPayload($snapshots),
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 进行中的考试始终读取开考快照（版本锁定）
        $snapshots = $this->buildSnapshotsIfMissing($record, $examPaper);

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => $this->paperPayload($examPaper),
            'questions' => $this->questionPayload($snapshots),
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 判分严格使用开考时锁定的题目版本
        $snapshots = $this->buildSnapshotsIfMissing($record, $examPaper);
        $snapshotMap = $snapshots->keyBy('question_id');

        $totalScore = 0;

        foreach ($request->answers as $answerData) {
            $snapshot = $snapshotMap->get($answerData['question_id']);
            if (!$snapshot) {
                // 不属于本次考试快照的题目直接忽略
                continue;
            }

            $isCorrect = $this->checkAnswer($snapshot->type, $snapshot->answer, $answerData['answer']);
            $score = $isCorrect ? (float) $snapshot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $snapshot->question_id,
                'question_version' => $snapshot->question_version,
                'answer' => $answerData['answer'],
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $totalScore += $score;
        }

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => 'graded',
        ]);

        return response()->json([
            'message' => '提交成功',
            'score' => $totalScore,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        // 成绩列表：标明每份答卷使用的题目版本，以及是否已有更新的版本
        $records->getCollection()->transform(function ($record) {
            $total = (int) DB::table('exam_record_snapshots')
                ->where('exam_record_id', $record->id)
                ->count();

            $distinctVersions = (int) DB::table('exam_record_snapshots')
                ->where('exam_record_id', $record->id)
                ->distinct()
                ->count('question_version');

            $outdated = DB::table('exam_record_snapshots as ers')
                ->join('questions as q', 'q.id', '=', 'ers.question_id')
                ->where('ers.exam_record_id', $record->id)
                ->whereColumn('ers.question_version', '<', 'q.current_version')
                ->count();

            $record->question_count = $total;
            $record->outdated_question_count = (int) $outdated;
            $record->uses_mixed_versions = $distinctVersions > 1;

            return $record;
        });

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper', 'answers']);

        $snapshots = $this->buildSnapshotsIfMissing($record, $record->examPaper);
        $answerMap = $record->answers->keyBy('question_id');

        $questions = $snapshots->map(function ($snapshot) use ($answerMap) {
            $currentVersion = (int) (Question::where('id', $snapshot->question_id)->value('current_version') ?: 1);
            $userAnswer = $answerMap->get($snapshot->question_id);

            return [
                'question_id' => $snapshot->question_id,
                'type' => $snapshot->type,
                'title' => $snapshot->title,
                'options' => $snapshot->options,
                'score' => $snapshot->score,
                'correct_answer' => $snapshot->answer,
                'analysis' => $snapshot->analysis,
                'used_version' => $snapshot->question_version,
                'current_version' => $currentVersion,
                'has_newer_version' => $snapshot->question_version < $currentVersion,
                'user_answer' => $userAnswer ? $userAnswer->answer : null,
                'is_correct' => $userAnswer ? (bool) $userAnswer->is_correct : null,
                'awarded_score' => $userAnswer ? $userAnswer->score : null,
            ];
        });

        $outdatedCount = $questions->where('has_newer_version', true)->count();

        return response()->json([
            'record' => [
                'id' => $record->id,
                'score' => $record->score,
                'status' => $record->status,
                'start_time' => $record->start_time,
                'end_time' => $record->end_time,
                'created_at' => $record->created_at,
                'exam_paper' => $record->examPaper ? [
                    'id' => $record->examPaper->id,
                    'title' => $record->examPaper->title,
                ] : null,
            ],
            'version_summary' => [
                'question_count' => $questions->count(),
                'outdated_question_count' => $outdatedCount,
                'all_latest' => $outdatedCount === 0,
                'note' => $outdatedCount > 0
                    ? "本答卷开考时锁定了题目版本；其中 {$outdatedCount} 题之后被教师修订过，显示与判分均按当时版本执行。"
                    : '本答卷使用的题目均为最新版本。',
            ],
            'questions' => $questions,
        ]);
    }

    /**
     * 若该考试记录还没有快照（历史数据兼容），按试卷锁定版本生成；有则直接返回。
     */
    protected function buildSnapshotsIfMissing(ExamRecord $record, ExamPaper $paper)
    {
        $existing = ExamRecordSnapshot::where('exam_record_id', $record->id)
            ->orderBy('sort_order')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        // 取试卷-题目关联（含锁定版本、分值、排序）
        $pivots = DB::table('exam_paper_questions')
            ->where('exam_paper_id', $paper->id)
            ->orderBy('sort_order')
            ->get(['question_id', 'question_version', 'score', 'sort_order']);

        $rows = [];
        $now = now();
        foreach ($pivots as $pivot) {
            $versionNo = (int) ($pivot->question_version ?: 1);

            $version = QuestionVersion::where('question_id', $pivot->question_id)
                ->where('version', $versionNo)
                ->first();

            // 极端情况下历史版本缺失（如旧数据），回退到当前题面
            if (!$version) {
                $question = Question::find($pivot->question_id);
                if (!$question) {
                    continue;
                }
                $rows[] = [
                    'exam_record_id' => $record->id,
                    'question_id' => $question->id,
                    'question_version' => $question->current_version ?: 1,
                    'type' => $question->type,
                    'title' => $question->title,
                    'options' => json_encode($question->options, JSON_UNESCAPED_UNICODE),
                    'answer' => $question->answer,
                    'analysis' => $question->analysis,
                    'score' => $pivot->score,
                    'sort_order' => $pivot->sort_order,
                    'created_at' => $now,
                ];
                continue;
            }

            $rows[] = [
                'exam_record_id' => $record->id,
                'question_id' => $pivot->question_id,
                'question_version' => $versionNo,
                'type' => $version->type,
                'title' => $version->title,
                'options' => $version->options !== null
                    ? json_encode($version->options, JSON_UNESCAPED_UNICODE)
                    : null,
                'answer' => $version->answer,
                'analysis' => $version->analysis,
                'score' => $pivot->score,
                'sort_order' => $pivot->sort_order,
                'created_at' => $now,
            ];
        }

        if ($rows) {
            ExamRecordSnapshot::insert($rows);
        }

        return ExamRecordSnapshot::where('exam_record_id', $record->id)
            ->orderBy('sort_order')
            ->get();
    }

    protected function paperPayload(ExamPaper $paper): array
    {
        return [
            'id' => $paper->id,
            'title' => $paper->title,
            'total_time' => $paper->total_time,
            'total_score' => $paper->total_score,
            'published_at' => $paper->published_at,
        ];
    }

    protected function questionPayload($snapshots)
    {
        return $snapshots->map(function ($s) {
            return [
                'id' => $s->question_id,
                'type' => $s->type,
                'title' => $s->title,
                'options' => $s->options,
                'score' => $s->score,
                'question_version' => $s->question_version,
            ];
        })->values();
    }

    protected function checkAnswer(string $type, string $correctAnswer, string $userAnswer): bool
    {
        switch ($type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
