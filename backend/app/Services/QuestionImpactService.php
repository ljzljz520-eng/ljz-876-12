<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class QuestionImpactService
{
    /**
     * 统计一道题目被哪些试卷引用、影响了哪些历史考试。
     */
    public static function forQuestion(int $questionId): array
    {
        $papers = DB::table('exam_paper_questions as epq')
            ->join('exam_papers as ep', 'ep.id', '=', 'epq.exam_paper_id')
            ->where('epq.question_id', $questionId)
            ->select(
                'ep.id',
                'ep.title',
                'ep.status',
                'ep.published_at',
                'epq.question_version as locked_version'
            )
            ->get();

        $currentVersion = (int) DB::table('questions')
            ->where('id', $questionId)
            ->value('current_version');

        $affectedPapers = $papers->map(function ($paper) use ($currentVersion) {
            $attempts = DB::table('exam_records')
                ->where('exam_paper_id', $paper->id)
                ->selectRaw("SUM(status = 'graded') as graded_count")
                ->selectRaw("SUM(status = 'in_progress') as in_progress_count")
                ->first();

            return [
                'exam_paper_id' => (int) $paper->id,
                'title' => $paper->title,
                'published' => (int) $paper->status === 1 && $paper->published_at !== null,
                'locked_version' => (int) $paper->locked_version,
                'current_version' => $currentVersion,
                'has_newer_version' => (int) $paper->locked_version < $currentVersion,
                'graded_count' => (int) ($attempts->graded_count ?? 0),
                'in_progress_count' => (int) ($attempts->in_progress_count ?? 0),
            ];
        })->values();

        $gradedCount = $affectedPapers->sum('graded_count');
        $inProgressCount = $affectedPapers->sum('in_progress_count');

        // 已开考（含进行中、已交卷）的考试受影响明细
        $affectedRecords = DB::table('exam_record_snapshots as ers')
            ->join('exam_records as er', 'er.id', '=', 'ers.exam_record_id')
            ->join('exam_papers as ep', 'ep.id', '=', 'er.exam_paper_id')
            ->join('users as u', 'u.id', '=', 'er.user_id')
            ->where('ers.question_id', $questionId)
            ->select(
                'er.id as exam_record_id',
                'er.status',
                'er.score',
                'er.created_at as started_at',
                'ers.question_version',
                'ep.id as exam_paper_id',
                'ep.title as exam_paper_title',
                'u.username',
                'u.real_name'
            )
            ->orderByDesc('er.id')
            ->limit(100)
            ->get()
            ->map(function ($row) use ($currentVersion) {
                return [
                    'exam_record_id' => (int) $row->exam_record_id,
                    'exam_paper_id' => (int) $row->exam_paper_id,
                    'exam_paper_title' => $row->exam_paper_title,
                    'student' => $row->real_name ?: $row->username,
                    'status' => $row->status,
                    'score' => $row->score,
                    'started_at' => $row->started_at,
                    'used_version' => (int) $row->question_version,
                    'current_version' => $currentVersion,
                    'version_outdated' => (int) $row->question_version < $currentVersion,
                ];
            })->values();

        return [
            'question_id' => $questionId,
            'current_version' => $currentVersion,
            'paper_count' => $affectedPapers->count(),
            'graded_exam_count' => $gradedCount,
            'in_progress_exam_count' => $inProgressCount,
            'affected_record_count' => $affectedRecords->count(),
            'papers' => $affectedPapers,
            'records' => $affectedRecords,
        ];
    }
}
