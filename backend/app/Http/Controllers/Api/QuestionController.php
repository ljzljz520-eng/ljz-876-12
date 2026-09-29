<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\QuestionVersion;
use App\Services\QuestionImpactService;
use Illuminate\Http\Request;
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

        // status: all / active(默认) / withdrawn
        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->where('status', 1);
        } elseif ($status === 'withdrawn') {
            $query->where('status', 0);
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

        // 每道题都有 v1 快照，作为后续版本锁定的基准
        QuestionVersion::create([
            'question_id' => $question->id,
            'version' => 1,
            'type' => $question->type,
            'title' => $question->title,
            'options' => $question->options,
            'answer' => $question->answer,
            'analysis' => $question->analysis,
            'difficulty' => $question->difficulty,
            'created_by' => $request->user()->id,
            'change_summary' => '初始版本',
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => '创建成功',
            'question' => $question,
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = [
            'category_id' => $request->category_id,
            'type' => $request->type,
            'title' => $request->title,
            'options' => $request->options,
            'answer' => $request->answer,
            'analysis' => $request->analysis,
            'difficulty' => $request->difficulty,
            'score' => $request->score,
        ];

        // 题干 / 选项 / 答案 / 解析发生变化 -> 生成新版本；不影响已发布、已开考的试卷
        $contentChanged = Question::contentChanged($question, $data);
        $newVersion = null;
        if ($contentChanged) {
            $newVersion = $question->createNewVersion($data, $request->user()->id);
        }

        $question->update($data);

        // 该题已被发布的试卷引用时，提醒教师：老考试继续用旧版，需"重新发布"补考卷才会用新版
        $publishedPapers = $question->examPapers()
            ->where('exam_papers.status', 1)
            ->whereNotNull('exam_papers.published_at')
            ->get();

        $warning = null;
        if ($contentChanged && $publishedPapers->isNotEmpty()) {
            $warning = '题目已保存为第 ' . $newVersion->version . ' 版。'
                . '已有 ' . $publishedPapers->count() . ' 份发布过的试卷仍锁定在旧版本，'
                . '已开考的考试继续使用旧版；如需补考使用新版，请在试卷管理中"重新发布"。';
        }

        return response()->json([
            'message' => $contentChanged ? '更新成功，已生成新版本 v' . $newVersion->version : '更新成功',
            'version_bumped' => $contentChanged,
            'new_version' => $newVersion ? $newVersion->version : null,
            'warning' => $warning,
            'question' => $question,
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

        $question->delete();

        return response()->json([
            'message' => '删除成功',
        ]);
    }

    /**
     * 题目版本历史
     */
    public function versions(Request $request, Question $question)
    {
        $versions = $question->versions()
            ->with('editor:id,username,real_name')
            ->get()
            ->map(function ($v) {
                return [
                    'version' => $v->version,
                    'type' => $v->type,
                    'title' => $v->title,
                    'options' => $v->options,
                    'answer' => $v->answer,
                    'analysis' => $v->analysis,
                    'difficulty' => $v->difficulty,
                    'change_summary' => $v->change_summary,
                    'editor' => $v->editor ? ($v->editor->real_name ?: $v->editor->username) : null,
                    'created_at' => $v->created_at,
                ];
            });

        return response()->json([
            'question_id' => $question->id,
            'current_version' => $question->current_version,
            'versions' => $versions,
        ]);
    }

    /**
     * 撤回前预览：哪些试卷、哪些历史考试使用了此题
     */
    public function impact(Request $request, Question $question)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限查看题目影响面'], 403);
        }

        return response()->json([
            'impact' => QuestionImpactService::forQuestion($question->id),
        ]);
    }

    /**
     * 撤回题目（不删除历史版本与考试快照）
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

        $impact = QuestionImpactService::forQuestion($question->id);

        $question->update(['status' => 0]);

        $message = '题目已撤回，不会再出现在新组卷/抽题中；已开考的历史考试仍使用当时的版本快照。';
        if ($impact['graded_exam_count'] > 0 || $impact['in_progress_exam_count'] > 0) {
            $message = '题目已撤回。检测到 '
                . $impact['graded_exam_count'] . ' 场已完成、'
                . $impact['in_progress_exam_count'] . ' 场进行中的历史考试使用过此题，这些考试仍保留撤回时使用的版本，不受影响。';
        }

        return response()->json([
            'message' => $message,
            'question' => $question,
            'impact' => $impact,
        ]);
    }

    /**
     * 恢复已撤回的题目
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

        $question->update(['status' => 1]);

        return response()->json([
            'message' => '题目已恢复',
            'question' => $question,
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
}
