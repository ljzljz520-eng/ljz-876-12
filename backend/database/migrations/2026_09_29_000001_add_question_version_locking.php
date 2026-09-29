<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 题目版本锁定特性：
     * - questions.current_version            当前版本号
     * - question_versions                    题目历史版本快照
     * - exam_papers.published_at             试卷发布时间
     * - exam_paper_questions.question_version 发布时锁定的题目版本
     * - exam_record_answers.question_version 作答依据的题目版本
     * - exam_record_snapshots                开考时的题目快照（版本锁定的核心）
     */
    public function up(): void
    {
        if (!Schema::hasTable('question_versions')) {
            Schema::create('question_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('question_id')->comment('题目ID');
                $table->unsignedInteger('version')->default(1)->comment('版本号');
                $table->string('type')->comment('题目类型快照');
                $table->text('title')->comment('题干快照');
                $table->json('options')->nullable()->comment('选项快照');
                $table->text('answer')->comment('正确答案快照');
                $table->text('analysis')->nullable()->comment('答案解析快照');
                $table->unsignedTinyInteger('difficulty')->default(1)->comment('难度快照');
                $table->unsignedBigInteger('created_by')->nullable()->comment('修订人ID');
                $table->text('change_summary')->nullable()->comment('版本说明');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['question_id', 'version'], 'uq_question_version');
                $table->index('question_id');
            });
        }

        if (!Schema::hasTable('exam_record_snapshots')) {
            Schema::create('exam_record_snapshots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('exam_record_id')->comment('考试记录ID');
                $table->unsignedBigInteger('question_id')->comment('题目ID');
                $table->unsignedInteger('question_version')->comment('锁定的题目版本');
                $table->string('type')->comment('题目类型快照');
                $table->text('title')->comment('题干快照');
                $table->json('options')->nullable()->comment('选项快照');
                $table->text('answer')->comment('正确答案快照');
                $table->text('analysis')->nullable()->comment('答案解析快照');
                $table->decimal('score', 5, 2)->default(0)->comment('该题分值快照');
                $table->unsignedInteger('sort_order')->default(0)->comment('排序');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['exam_record_id', 'question_id'], 'uq_record_question_snapshot');
                $table->index('exam_record_id');
            });
        }

        if (!Schema::hasColumn('questions', 'current_version')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->unsignedInteger('current_version')->default(1)->after('status')->comment('当前版本号');
            });
        }

        if (!Schema::hasColumn('exam_papers', 'published_at')) {
            Schema::table('exam_papers', function (Blueprint $table) {
                $table->timestamp('published_at')->nullable()->after('status')->comment('发布时间(版本锁定基准)');
            });
        }

        if (!Schema::hasColumn('exam_paper_questions', 'question_version')) {
            Schema::table('exam_paper_questions', function (Blueprint $table) {
                $table->unsignedInteger('question_version')->default(1)->after('question_id')->comment('发布时锁定的版本');
            });
        }

        if (!Schema::hasColumn('exam_record_answers', 'question_version')) {
            Schema::table('exam_record_answers', function (Blueprint $table) {
                $table->unsignedInteger('question_version')->default(1)->after('question_id')->comment('作答依据的版本');
            });
        }

        // 存量数据回填：为每个题目补建 v1 版本快照
        $questions = DB::table('questions')->orderBy('id')->get();
        foreach ($questions as $question) {
            $exists = DB::table('question_versions')
                ->where('question_id', $question->id)
                ->where('version', 1)
                ->exists();
            if (!$exists) {
                DB::table('question_versions')->insert([
                    'question_id' => $question->id,
                    'version' => 1,
                    'type' => $question->type,
                    'title' => $question->title,
                    'options' => $question->options,
                    'answer' => $question->answer,
                    'analysis' => $question->analysis,
                    'difficulty' => $question->difficulty,
                    'created_by' => $question->created_by,
                    'change_summary' => '初始版本',
                    'created_at' => $question->created_at ?? now(),
                ]);
            }
        }

        // 存量数据回填：试卷题目关联锁定为当前版本
        DB::table('exam_paper_questions')
            ->whereNull('question_version')
            ->orWhere('question_version', 0)
            ->update(['question_version' => 1]);

        // 存量数据回填：已启用试卷视为已发布
        if (Schema::hasColumn('exam_papers', 'published_at')) {
            DB::table('exam_papers')
                ->where('status', 1)
                ->whereNull('published_at')
                ->update(['published_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exam_record_answers', 'question_version')) {
            Schema::table('exam_record_answers', function (Blueprint $table) {
                $table->dropColumn('question_version');
            });
        }
        if (Schema::hasColumn('exam_paper_questions', 'question_version')) {
            Schema::table('exam_paper_questions', function (Blueprint $table) {
                $table->dropColumn('question_version');
            });
        }
        if (Schema::hasColumn('exam_papers', 'published_at')) {
            Schema::table('exam_papers', function (Blueprint $table) {
                $table->dropColumn('published_at');
            });
        }
        if (Schema::hasColumn('questions', 'current_version')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('current_version');
            });
        }
        Schema::dropIfExists('exam_record_snapshots');
        Schema::dropIfExists('question_versions');
    }
};
