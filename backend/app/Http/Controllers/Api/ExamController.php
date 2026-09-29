<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\ExamRecordQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            // 已开考：继续返回当时锁定的题目版本快照
            $snapshot = $this->getOrCreateSnapshot($existingRecord, $examPaper);

            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
                'questions' => $this->mapSnapshotForTaking($snapshot),
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        // 开考瞬间锁定题目版本：把当前版本完整快照下来。
        // 之后老师修改题干/选项/解析，本卷仍使用当时版本；
        // 已撤回的题目不再进入新考试（补考等新场次自动引用新版题）。
        $questions = $examPaper->questions()->get();
        $excluded = [];

        foreach ($questions as $q) {
            if ($q->isWithdrawn()) {
                $excluded[] = [
                    'question_id' => $q->id,
                    'title' => $q->title,
                    'reason' => '题目已撤回',
                ];
                continue;
            }

            ExamRecordQuestion::create([
                'exam_record_id' => $record->id,
                'question_id' => $q->id,
                'question_version' => $q->current_version,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'answer' => $q->answer,
                'analysis' => $q->analysis,
                'score' => $q->pivot->score,
                'sort_order' => $q->pivot->sort_order,
            ]);
        }

        $snapshot = $record->snapshotQuestions()->orderBy('sort_order')->get();

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => round((float) $snapshot->sum('score'), 2),
            ],
            'questions' => $this->mapSnapshotForTaking($snapshot),
            'excluded_withdrawn_questions' => $excluded,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 从锁定快照读取，保证开考后题目被修改也不影响本场考试
        $snapshot = $this->getOrCreateSnapshot($record, $examPaper);

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => round((float) $snapshot->sum('score'), 2),
            ],
            'questions' => $this->mapSnapshotForTaking($snapshot),
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

        // 用开考时锁定的快照判分，不受题目后续修改影响
        $snapshot = $this->getOrCreateSnapshot($record, $examPaper);
        $snapshotMap = $snapshot->keyBy('question_id');

        $totalScore = 0;

        foreach ($request->answers as $answerData) {
            $snap = $snapshotMap->get($answerData['question_id']);
            if (!$snap) {
                continue;
            }

            $isCorrect = $this->checkAnswer($snap->type, $snap->answer, $answerData['answer']);
            $score = $isCorrect ? (float) $snap->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'question_version' => $snap->question_version,
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

        // 为每条记录附上锁定的题目版本摘要（成绩页标明使用的是哪个版本）
        $versionMap = $this->versionSummaryForRecords($records->getCollection()->pluck('id')->all());

        $records->getCollection()->transform(function ($record) use ($versionMap) {
            $record->question_versions = $versionMap[$record->id] ?? [];
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

        // 成绩复查展示开考时锁定的版本快照（题干/答案/解析均为当时版本）
        $snapshot = $record->snapshotQuestions()->orderBy('sort_order')->get();
        $answersMap = $record->answers->keyBy('question_id');

        $questions = $snapshot->map(function ($snap) use ($answersMap) {
            $answer = $answersMap->get($snap->question_id);
            return [
                'question_id' => $snap->question_id,
                'question_version' => $snap->question_version,
                'type' => $snap->type,
                'title' => $snap->title,
                'options' => $snap->options,
                'score' => (float) $snap->score,
                'correct_answer' => $snap->answer,
                'analysis' => $snap->analysis,
                'my_answer' => $answer->answer ?? null,
                'is_correct' => $answer ? (bool) $answer->is_correct : null,
                'got_score' => $answer ? (float) $answer->score : null,
            ];
        });

        return response()->json([
            'record' => $record,
            'questions' => $questions,
        ]);
    }

    /**
     * 获取记录的锁定快照；历史数据若无快照则按当前题目内容补建
     */
    protected function getOrCreateSnapshot(ExamRecord $record, ExamPaper $examPaper)
    {
        $snapshot = $record->snapshotQuestions()->orderBy('sort_order')->get();

        if ($snapshot->isNotEmpty()) {
            return $snapshot;
        }

        foreach ($examPaper->questions()->get() as $q) {
            ExamRecordQuestion::create([
                'exam_record_id' => $record->id,
                'question_id' => $q->id,
                'question_version' => $q->current_version,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'answer' => $q->answer,
                'analysis' => $q->analysis,
                'score' => $q->pivot->score,
                'sort_order' => $q->pivot->sort_order,
            ]);
        }

        return $record->snapshotQuestions()->orderBy('sort_order')->get();
    }

    /**
     * 快照 -> 考试作答页数据结构
     */
    protected function mapSnapshotForTaking($snapshot)
    {
        return $snapshot->map(function ($snap) {
            return [
                'id' => $snap->question_id,
                'question_version' => $snap->question_version,
                'type' => $snap->type,
                'title' => $snap->title,
                'options' => $snap->options,
                'score' => (float) $snap->score,
            ];
        })->values();
    }

    /**
     * 批量查询多条考试记录的题目版本摘要
     * 返回: [record_id => [['version' => 1, 'count' => 2], ...]]
     */
    protected function versionSummaryForRecords(array $recordIds)
    {
        if (empty($recordIds)) {
            return [];
        }

        return DB::table('exam_record_questions')
            ->whereIn('exam_record_id', $recordIds)
            ->select('exam_record_id', 'question_version', DB::raw('COUNT(*) as cnt'))
            ->groupBy('exam_record_id', 'question_version')
            ->orderBy('question_version')
            ->get()
            ->groupBy('exam_record_id')
            ->map(function ($rows) {
                return $rows->map(function ($row) {
                    return ['version' => (int) $row->question_version, 'count' => (int) $row->cnt];
                })->values()->all();
            })
            ->all();
    }

    protected function checkAnswer(string $type, ?string $correctAnswer, string $userAnswer): bool
    {
        $correctAnswer = $correctAnswer ?? '';

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
