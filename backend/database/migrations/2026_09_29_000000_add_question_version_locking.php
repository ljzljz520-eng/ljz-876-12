<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 题目版本锁定：
     * 1. questions 增加 current_version / withdrawn_at
     * 2. 新增 question_versions（题目版本历史）
     * 3. 新增 exam_record_questions（开考时锁定的题目快照）
     * 4. exam_record_answers 增加 question_version
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (!Schema::hasColumn('questions', 'current_version')) {
                $table->unsignedInteger('current_version')->default(1)->comment('当前版本号')->after('status');
            }
            if (!Schema::hasColumn('questions', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->comment('撤回时间')->after('current_version');
            }
        });

        if (!Schema::hasTable('question_versions')) {
            Schema::create('question_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('question_id')->comment('题目ID');
                $table->unsignedInteger('version')->comment('版本号');
                $table->unsignedBigInteger('category_id')->nullable()->comment('分类ID');
                $table->string('type', 50)->comment('题目类型');
                $table->text('title')->comment('题干(该版本)');
                $table->json('options')->nullable()->comment('选项(JSON格式,该版本)');
                $table->text('answer')->nullable()->comment('正确答案(该版本)');
                $table->text('analysis')->nullable()->comment('解析(该版本)');
                $table->unsignedTinyInteger('difficulty')->default(1)->comment('难度');
                $table->decimal('score', 5, 2)->default(1.00)->comment('默认分值(该版本)');
                $table->string('change_summary')->nullable()->comment('版本变更说明');
                $table->unsignedBigInteger('created_by')->nullable()->comment('操作人ID');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['question_id', 'version'], 'uk_question_version');
                $table->index('question_id');
            });
        }

        if (!Schema::hasTable('exam_record_questions')) {
            Schema::create('exam_record_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('exam_record_id')->comment('考试记录ID');
                $table->unsignedBigInteger('question_id')->comment('题目ID');
                $table->unsignedInteger('question_version')->default(1)->comment('锁定的题目版本号');
                $table->string('type', 50)->comment('题目类型快照');
                $table->text('title')->comment('题干快照');
                $table->json('options')->nullable()->comment('选项快照(JSON格式)');
                $table->text('answer')->nullable()->comment('正确答案快照');
                $table->text('analysis')->nullable()->comment('解析快照');
                $table->decimal('score', 5, 2)->default(0.00)->comment('该题分值快照');
                $table->integer('sort_order')->default(0)->comment('排序');
                $table->timestamp('created_at')->useCurrent();
                $table->index('exam_record_id');
                $table->index('question_id');
            });
        }

        Schema::table('exam_record_answers', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_record_answers', 'question_version')) {
                $table->unsignedInteger('question_version')->nullable()->comment('考试时锁定的题目版本号')->after('question_id');
            }
        });

        // 为历史题目补齐 v1 版本记录
        DB::statement("
            INSERT INTO question_versions (question_id, version, category_id, type, title, options, answer, analysis, difficulty, score, change_summary, created_by, created_at)
            SELECT q.id, 1, q.category_id, q.type, q.title, q.options, q.answer, q.analysis, q.difficulty, q.score, '初始版本', q.created_by, NOW()
            FROM questions q
            WHERE NOT EXISTS (
                SELECT 1 FROM question_versions v WHERE v.question_id = q.id AND v.version = 1
            )
        ");

        // 为已存在的考试记录补齐题目版本锁定快照
        DB::statement("
            INSERT INTO exam_record_questions (exam_record_id, question_id, question_version, type, title, options, answer, analysis, score, sort_order, created_at)
            SELECT r.id, epq.question_id, q.current_version, q.type, q.title, q.options, q.answer, q.analysis, epq.score, epq.sort_order, NOW()
            FROM exam_records r
            JOIN exam_paper_questions epq ON epq.exam_paper_id = r.exam_paper_id
            JOIN questions q ON q.id = epq.question_id
            WHERE NOT EXISTS (
                SELECT 1 FROM exam_record_questions s WHERE s.exam_record_id = r.id
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_record_questions');
        Schema::dropIfExists('question_versions');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['current_version', 'withdrawn_at']);
        });

        Schema::table('exam_record_answers', function (Blueprint $table) {
            $table->dropColumn('question_version');
        });
    }
};
