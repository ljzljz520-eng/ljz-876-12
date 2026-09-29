<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\QuestionVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $query = Question::with('category');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }

        if ($request->filled('keyword')) {
            $query->where('title', 'like', '%' . $request->keyword . '%');
        }

        $perPage = $request->input('per_page', 15);
        $questions = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'questions' => $questions,
        ]);
    }

    public function store(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限创建题目'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:question_categories,id',
            'type' => 'required|in:single_choice,multiple_choice,true_false,fill_blank,essay',
            'title' => 'required|string',
            'options' => 'nullable|array',
            'answer' => 'required|string',
            'analysis' => 'nullable|string',
            'difficulty' => 'nullable|integer|min:1|max:3',
            'score' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $question = DB::transaction(function () use ($request) {
            $question = Question::create([
                'category_id' => $request->category_id,
                'type' => $request->type,
                'title' => $request->title,
                'options' => $request->options,
                'answer' => $request->answer,
                'analysis' => $request->analysis,
                'difficulty' => $request->difficulty ?? 1,
                'score' => $request->score ?? 1,
                'created_by' => $request->user()->id,
                'status' => 1,
                'current_version' => 1,
            ]);

            // 初始版本 v1
            QuestionVersion::create([
                'question_id' => $question->id,
                'version' => 1,
                'category_id' => $question->category_id,
                'type' => $question->type,
                'title' => $question->title,
                'options' => $question->options,
                'answer' => $question->answer,
                'analysis' => $question->analysis,
                'difficulty' => $question->difficulty,
                'score' => $question->score,
                'change_summary' => '初始版本',
                'created_by' => $request->user()->id,
            ]);

            return $question;
        });

        return response()->json([
            'message' => '创建成功',
            'question' => $question,
            'version' => 1,
        ], 201);
    }

    public function show(Question $question)
    {
        return response()->json([
            'question' => $question->load('category'),
        ]);
    }

    public function update(Request $request, Question $question)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限更新题目'], 403);
        }

        if ($request->user()->role !== 'admin' && $question->created_by !== $request->user()->id) {
            return response()->json(['error' => '只能编辑自己创建的题目'], 403);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:question_categories,id',
            'type' => 'required|in:single_choice,multiple_choice,true_false,fill_blank,essay',
            'title' => 'required|string',
            'options' => 'nullable|array',
            'answer' => 'required|string',
            'analysis' => 'nullable|string',
            'difficulty' => 'nullable|integer|min:1|max:3',
            'score' => 'nullable|numeric|min:0',
            'change_summary' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $newVersion = $question->current_version + 1;

        DB::transaction(function () use ($request, $question, $newVersion) {
            // 新内容写入版本历史
            QuestionVersion::create([
                'question_id' => $question->id,
                'version' => $newVersion,
                'category_id' => $request->category_id,
                'type' => $request->type,
                'title' => $request->title,
                'options' => $request->options,
                'answer' => $request->answer,
                'analysis' => $request->analysis,
                'difficulty' => $request->difficulty ?? $question->difficulty,
                'score' => $request->score ?? $question->score,
                'change_summary' => $request->input('change_summary'),
                'created_by' => $request->user()->id,
            ]);

            $question->update([
                'category_id' => $request->category_id,
                'type' => $request->type,
                'title' => $request->title,
                'options' => $request->options,
                'answer' => $request->answer,
                'analysis' => $request->analysis,
                'difficulty' => $request->difficulty,
                'score' => $request->score,
                'current_version' => $newVersion,
            ]);
        });

        // 已开考（进行中）的考试仍锁定旧版本，提示老师不受影响
        $lockedExams = $this->collectLockedExams($question);

        return response()->json([
            'message' => "更新成功，已生成新版本 v{$newVersion}。已开考的试卷继续使用原版本，不受影响。",
            'question' => $question->fresh(),
            'version' => $newVersion,
            'locked_exams' => $lockedExams,
        ]);
    }

    /**
     * 题目版本历史
     */
    public function versions(Question $question)
    {
        $versions = $question->versions()
            ->with('creator:id,username,real_name')
            ->orderBy('version', 'desc')
            ->get();

        return response()->json([
            'question_id' => $question->id,
            'current_version' => $question->current_version,
            'withdrawn_at' => $question->withdrawn_at,
            'versions' => $versions,
        ]);
    }

    /**
     * 撤回预览：返回受影响的历史考试（不执行撤回）
     */
    public function affectedExams(Question $question)
    {
        return response()->json([
            'question_id' => $question->id,
            'affected_exams' => $this->collectAffectedExams($question),
        ]);
    }

    /**
     * 撤回题目：停用并提醒受影响的历史考试
     */
    public function withdraw(Request $request, Question $question)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限撤回题目'], 403);
        }

        if ($user->role !== 'admin' && $question->created_by !== $user->id) {
            return response()->json(['error' => '只能撤回自己创建的题目'], 403);
        }

        if ($question->isWithdrawn()) {
            return response()->json(['error' => '该题目已处于撤回状态'], 422);
        }

        // 先收集受影响的历史考试，再执行撤回
        $affectedExams = $this->collectAffectedExams($question);

        $question->update([
            'status' => 0,
            'withdrawn_at' => now(),
        ]);

        return response()->json([
            'message' => '题目已撤回。已开考的考试仍使用锁定版本不受影响；新考试将不再使用该题。',
            'question' => $question->fresh(),
            'affected_exams' => $affectedExams,
        ]);
    }

    /**
     * 恢复已撤回题目
     */
    public function restore(Request $request, Question $question)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限恢复题目'], 403);
        }

        if ($user->role !== 'admin' && $question->created_by !== $user->id) {
            return response()->json(['error' => '只能恢复自己创建的题目'], 403);
        }

        if (!$question->isWithdrawn()) {
            return response()->json(['error' => '该题目未被撤回'], 422);
        }

        $question->update([
            'status' => 1,
            'withdrawn_at' => null,
        ]);

        return response()->json([
            'message' => '题目已恢复',
            'question' => $question->fresh(),
        ]);
    }

    public function destroy(Question $question)
    {
        $user = request()->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限删除题目'], 403);
        }

        if ($user->role !== 'admin' && $question->created_by !== $user->id) {
            return response()->json(['error' => '只能删除自己创建的题目'], 403);
        }

        // 已被试卷或考试记录引用的题目禁止物理删除，引导使用撤回
        $usedInPapers = DB::table('exam_paper_questions')->where('question_id', $question->id)->exists();
        $usedInRecords = DB::table('exam_record_questions')->where('question_id', $question->id)->exists();

        if ($usedInPapers || $usedInRecords) {
            return response()->json([
                'error' => '该题目已被试卷或历史考试引用，无法删除。如需停用请使用「撤回」功能，历史考试将保留当时版本。',
            ], 422);
        }

        $question->versions()->delete();
        $question->delete();

        return response()->json([
            'message' => '删除成功',
        ]);
    }

    public function categories()
    {
        $categories = QuestionCategory::where('status', 1)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    public function storeCategory(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限创建分类'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'parent_id' => 'nullable|exists:question_categories,id',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = QuestionCategory::create([
            'name' => $request->name,
            'parent_id' => $request->parent_id ?? 0,
            'sort_order' => $request->sort_order ?? 0,
            'status' => 1,
        ]);

        return response()->json([
            'message' => '创建成功',
            'category' => $category,
        ], 201);
    }

    /**
     * 引用该题且存在进行中考试的试卷（这些考试已锁定旧版本）
     */
    protected function collectLockedExams(Question $question)
    {
        return DB::table('exam_records')
            ->join('exam_paper_questions', 'exam_records.exam_paper_id', '=', 'exam_paper_questions.exam_paper_id')
            ->join('exam_papers', 'exam_papers.id', '=', 'exam_records.exam_paper_id')
            ->where('exam_paper_questions.question_id', $question->id)
            ->where('exam_records.status', 'in_progress')
            ->groupBy('exam_papers.id', 'exam_papers.title')
            ->select(
                'exam_papers.id as exam_paper_id',
                'exam_papers.title',
                DB::raw('COUNT(exam_records.id) as in_progress_records')
            )
            ->get();
    }

    /**
     * 受该题影响的历史考试（按试卷聚合，含锁定版本分布）
     */
    protected function collectAffectedExams(Question $question)
    {
        // 引用该题的试卷
        $papers = DB::table('exam_paper_questions')
            ->join('exam_papers', 'exam_papers.id', '=', 'exam_paper_questions.exam_paper_id')
            ->where('exam_paper_questions.question_id', $question->id)
            ->select('exam_papers.id as exam_paper_id', 'exam_papers.title')
            ->get()
            ->keyBy('exam_paper_id');

        // 已开考记录中锁定过该题的统计
        $recordStats = DB::table('exam_record_questions')
            ->join('exam_records', 'exam_record_questions.exam_record_id', '=', 'exam_records.id')
            ->where('exam_record_questions.question_id', $question->id)
            ->groupBy('exam_records.exam_paper_id')
            ->select(
                'exam_records.exam_paper_id',
                DB::raw('COUNT(DISTINCT exam_records.id) as total_records'),
                DB::raw("SUM(IF(exam_records.status = 'in_progress', 1, 0)) as in_progress_records"),
                DB::raw('GROUP_CONCAT(DISTINCT exam_record_questions.question_version ORDER BY exam_record_questions.question_version) as versions_used')
            )
            ->get()
            ->keyBy('exam_paper_id');

        return $papers->map(function ($paper) use ($recordStats) {
            $stat = $recordStats->get($paper->exam_paper_id);
            return [
                'exam_paper_id' => $paper->exam_paper_id,
                'title' => $paper->title,
                'total_records' => $stat ? (int) $stat->total_records : 0,
                'in_progress_records' => $stat ? (int) $stat->in_progress_records : 0,
                'versions_used' => $stat && $stat->versions_used
                    ? array_map('intval', explode(',', $stat->versions_used))
                    : [],
            ];
        })->values();
    }
}
