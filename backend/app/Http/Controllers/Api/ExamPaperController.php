<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamPaperController extends Controller
{
    public function index(Request $request)
    {
        $query = ExamPaper::with('creator');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('keyword')) {
            $query->where('title', 'like', '%' . $request->keyword . '%');
        }

        $perPage = $request->input('per_page', 15);
        $examPapers = $query->orderBy('id', 'desc')->paginate($perPage);

        $examPapers->getCollection()->transform(function ($paper) {
            $paper->published = $paper->isPublished();

            $outdated = DB::table('exam_paper_questions as epq')
                ->join('questions as q', 'q.id', '=', 'epq.question_id')
                ->where('epq.exam_paper_id', $paper->id)
                ->whereColumn('epq.question_version', '<', 'q.current_version')
                ->count();
            $paper->outdated_question_count = $outdated;

            return $paper;
        });

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function store(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限创建试卷'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'total_time' => 'nullable|integer|min:1',
            'type' => 'nullable|in:fixed,random',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $examPaper = ExamPaper::create([
            'title' => $request->title,
            'description' => $request->description,
            'total_score' => 0,
            'total_time' => $request->total_time ?? 60,
            'question_count' => 0,
            'type' => $request->type ?? 'fixed',
            'created_by' => $request->user()->id,
            'status' => 0,
            'published_at' => null,
        ]);

        return response()->json([
            'message' => '试卷创建成功（草稿，发布后学生才能看到并锁定题目版本）',
            'exam_paper' => $examPaper,
        ], 201);
    }

    public function show(ExamPaper $examPaper)
    {
        $examPaper->load(['questions', 'creator']);

        return response()->json([
            'exam_paper' => $this->withVersionInfo($examPaper),
        ]);
    }

    /**
     * 为试卷附上发布状态及每题的锁定版本 / 最新版本对比
     */
    protected function withVersionInfo(ExamPaper $examPaper): array
    {
        $data = $examPaper->toArray();
        $data['published'] = $examPaper->isPublished();

        $outdatedCount = 0;
        $data['questions'] = array_map(function ($q) use (&$outdatedCount) {
            $lockedVersion = (int) ($q['pivot']['question_version'] ?? 1);
            $currentVersion = (int) ($q['current_version'] ?? 1);
            $isOutdated = $lockedVersion < $currentVersion;
            if ($isOutdated) {
                $outdatedCount++;
            }
            $q['locked_version'] = $lockedVersion;
            $q['current_version'] = $currentVersion;
            $q['has_newer_version'] = $isOutdated;
            return $q;
        }, $data['questions'] ?? []);

        $data['outdated_question_count'] = $outdatedCount;

        return $data;
    }

    /**
     * 发布 / 重新发布试卷：锁定题目版本
     */
    public function publish(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限发布试卷'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能发布自己创建的试卷'], 403);
        }

        if ($examPaper->questions()->count() === 0) {
            return response()->json(['message' => '试卷还没有关联任何题目，无法发布'], 422);
        }

        $wasPublished = $examPaper->isPublished();
        $examPaper->publish();

        return response()->json([
            'message' => $wasPublished
                ? '重新发布成功，补考卷将引用题目的最新版本；已开考的历史考试不受影响，仍使用当时版本。'
                : '发布成功，题目版本已锁定。之后修改题目不会影响本试卷，需要时可重新发布以引用新版本。',
            'republished' => $wasPublished,
            'exam_paper' => $this->withVersionInfo($examPaper->fresh()->load('questions')),
        ]);
    }

    /**
     * 取消发布（退回草稿，学生不可见）
     */
    public function unpublish(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限取消发布'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能操作自己创建的试卷'], 403);
        }

        $examPaper->update(['status' => 0]);

        return response()->json([
            'message' => '已取消发布',
            'exam_paper' => $examPaper,
        ]);
    }

    public function update(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限更新试卷'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能编辑自己创建的试卷'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'total_time' => 'nullable|integer|min:1',
            'type' => 'nullable|in:fixed,random',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 发布状态请通过 发布/取消发布 接口变更，以保证版本锁定逻辑执行
        $examPaper->update([
            'title' => $request->title,
            'description' => $request->description,
            'total_time' => $request->total_time,
            'type' => $request->type,
        ]);

        return response()->json([
            'message' => '更新成功',
            'exam_paper' => $examPaper,
        ]);
    }

    public function destroy(ExamPaper $examPaper)
    {
        $user = request()->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限删除试卷'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能删除自己创建的试卷'], 403);
        }

        $examPaper->questions()->detach();
        $examPaper->delete();

        return response()->json([
            'message' => '删除成功',
        ]);
    }

    public function addQuestions(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限添加题目'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能操作自己创建的试卷'], 403);
        }

        $validator = Validator::make($request->all(), [
            'question_ids' => 'required|array',
            'question_ids.*' => 'exists:questions,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existingIds = $examPaper->questions->pluck('id')->toArray();
        $newIds = array_diff($request->question_ids, $existingIds);

        $sortOrder = $examPaper->questions()->max('exam_paper_questions.sort_order') ?? 0;

        foreach ($newIds as $questionId) {
            $question = \App\Models\Question::find($questionId);

            if ((int) $question->status !== 1) {
                return response()->json([
                    'message' => '题目「' . mb_substr($question->title, 0, 30) . '」已被撤回，不能加入试卷',
                ], 422);
            }

            // 记录引用时的题目版本；若试卷已发布，该版本保持锁定，需"重新发布"才会更新
            $examPaper->questions()->attach($questionId, [
                'sort_order' => ++$sortOrder,
                'score' => $question->score,
                'question_version' => $question->current_version ?: 1,
            ]);
        }

        $examPaper->updateQuestionCountAndScore();

        return response()->json([
            'message' => '添加成功',
            'exam_paper' => $this->withVersionInfo($examPaper->fresh()->load('questions')),
        ]);
    }

    public function removeQuestion(ExamPaper $examPaper, $questionId)
    {
        $user = request()->user();

        if (!in_array($user->role, ['admin', 'teacher'])) {
            return response()->json(['error' => '无权限移除题目'], 403);
        }

        if ($user->role !== 'admin' && $examPaper->created_by !== $user->id) {
            return response()->json(['error' => '只能操作自己创建的试卷'], 403);
        }

        $examPaper->questions()->detach($questionId);
        $examPaper->updateQuestionCountAndScore();

        return response()->json([
            'message' => '移除成功',
            'exam_paper' => $this->withVersionInfo($examPaper->fresh()->load('questions')),
        ]);
    }
}
