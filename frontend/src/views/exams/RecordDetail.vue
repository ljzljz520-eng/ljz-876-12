<template>
  <div class="space-y-6">
    <div class="flex items-center gap-3">
      <router-link to="/records" class="text-sm text-gray-500 hover:text-gray-800">← 返回成绩列表</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="record">
      <!-- 成绩与版本总览 -->
      <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ record.exam_paper?.title }}</h1>
            <p class="text-sm text-gray-500 mt-1">开考时间：{{ formatTime(record.start_time) }} · 交卷时间：{{ formatTime(record.end_time) }}</p>
          </div>
          <div class="text-right">
            <div class="text-3xl font-bold" :class="record.score >= 60 ? 'text-green-600' : 'text-red-600'">{{ record.score }} 分</div>
            <div class="text-xs text-gray-400 mt-1">状态：已评分</div>
          </div>
        </div>

        <!-- 版本说明条 -->
        <div class="mt-4 rounded-lg px-4 py-3 text-sm flex items-start gap-2"
             :class="versionSummary?.all_latest ? 'bg-blue-50 text-blue-800 border border-blue-200' : 'bg-amber-50 text-amber-800 border border-amber-200'">
          <svg class="h-5 w-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
          <span>
            {{ versionSummary?.note }}
            <span class="font-semibold">本卷共 {{ versionSummary?.question_count }} 题</span><template v-if="versionSummary && versionSummary.outdated_question_count > 0">，其中 <span class="font-semibold">{{ versionSummary.outdated_question_count }}</span> 题在开考后被修订过，显示与判分均严格采用开考时锁定的版本。</template>
          </span>
        </div>
      </div>

      <!-- 逐题回放 -->
      <div v-for="(q, index) in questions" :key="q.question_id" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-start mb-4">
          <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded mr-3">{{ index + 1 }}</span>
          <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2 mb-2">
              <h3 class="text-lg font-medium text-gray-900">{{ q.title }}</h3>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs mb-3">
              <span class="text-gray-500">{{ questionTypeLabel(q.type) }} · {{ q.score }}分</span>
              <!-- 版本徽标 -->
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded border"
                    :class="q.has_newer_version ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-blue-50 text-blue-700 border-blue-200'">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                本卷使用 v{{ q.used_version }}
                <span v-if="q.has_newer_version">· 题库最新 v{{ q.current_version }}</span>
              </span>
              <span v-if="q.is_correct" class="px-2 py-0.5 rounded bg-green-100 text-green-700 font-semibold">回答正确 +{{ q.awarded_score }}分</span>
              <span v-else class="px-2 py-0.5 rounded bg-red-100 text-red-700 font-semibold">回答错误 0分</span>
            </div>

            <div class="space-y-2 mb-4">
              <template v-if="q.type === 'single_choice'">
                <div v-for="(label, key) in q.options" :key="key"
                     class="flex items-center p-2 border rounded text-sm"
                     :class="{'border-green-400 bg-green-50 font-medium': key === q.correct_answer,
                              'border-red-400 bg-red-50': key === q.user_answer && q.user_answer !== q.correct_answer}">
                  <span class="ml-1">{{ key }}. {{ label }}</span>
                  <span v-if="key === q.correct_answer" class="ml-auto text-green-600 text-xs">正确答案</span>
                  <span v-if="key === q.user_answer" class="ml-auto" :class="key === q.correct_answer ? 'text-green-600' : 'text-red-600 text-xs'">你的选择</span>
                </div>
              </template>
              <template v-else-if="q.type === 'true_false'">
                <p class="text-sm text-gray-700">正确答案：<span class="font-semibold">{{ q.correct_answer === 'true' ? '正确' : '错误' }}</span></p>
                <p class="text-sm" :class="q.is_correct ? 'text-green-700' : 'text-red-700'">你的答案：{{ q.user_answer === 'true' ? '正确' : (q.user_answer === 'false' ? '错误' : '未作答') }}</p>
              </template>
              <template v-else>
                <p class="text-sm"><span class="text-gray-400">你的答案：</span><span :class="q.is_correct ? 'text-green-700' : 'text-red-700'">{{ q.user_answer || '未作答' }}</span></p>
                <p class="text-sm"><span class="text-gray-400">正确答案：</span><span class="text-green-700 font-medium">{{ q.correct_answer }}</span></p>
              </template>
            </div>

            <div v-if="q.analysis" class="text-sm bg-gray-50 rounded-lg p-3 border border-gray-100">
              <span class="text-gray-400">解析（v{{ q.used_version }}）：</span>{{ q.analysis }}
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'

const route = useRoute()
const loading = ref(true)
const record = ref(null)
const questions = ref([])
const versionSummary = ref(null)

const formatTime = (t) => t ? new Date(t).toLocaleString() : '-'
const questionTypeLabel = (type) => ({
  single_choice: '单选题',
  multiple_choice: '多选题',
  true_false: '判断题',
  fill_blank: '填空题',
  essay: '问答题'
}[type] || type)

onMounted(async () => {
  try {
    const res = await api.get(`/exams/records/${route.params.id}`)
    record.value = res.data.record
    questions.value = res.data.questions
    versionSummary.value = res.data.version_summary
  } catch (e) {
    console.error('Failed to fetch record detail:', e)
  } finally {
    loading.value = false
  }
})
</script>
