<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">我的成绩</h1>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="records.length === 0" class="text-center py-8 text-gray-500">
      暂无考试记录
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">试卷</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">得分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">题目版本</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">考试时间</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="record in records" :key="record.id">
            <td class="px-6 py-4 whitespace-nowrap">{{ record.exam_paper?.title }}</td>
            <td class="px-6 py-4 whitespace-nowrap font-bold" :class="{'text-green-600': record.score >= 60, 'text-red-600': record.score < 60}">{{ record.score }} 分</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <div v-if="record.question_versions && record.question_versions.length > 0" class="flex flex-wrap gap-1">
                <span
                  v-for="vs in record.question_versions"
                  :key="vs.version"
                  class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800"
                  :title="`${vs.count} 道题使用 v${vs.version}`"
                >v{{ vs.version }}×{{ vs.count }}</span>
              </div>
              <span v-else class="text-xs text-gray-400">-</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                {{ record.status === 'graded' ? '已评分' : record.status }}
              </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ new Date(record.created_at).toLocaleString() }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <button @click="openDetail(record)" class="text-indigo-600 hover:text-indigo-900 text-sm">查看详情</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 成绩详情模态框：展示开考时锁定的题目版本快照 -->
    <Teleport to="body">
      <div v-if="showDetail" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b flex justify-between items-center">
              <div>
                <h3 class="text-lg font-semibold">{{ detailRecord?.exam_paper?.title }} - 成绩详情</h3>
                <p class="text-sm text-gray-500 mt-0.5">
                  得分 <span class="font-bold" :class="detailRecord?.score >= 60 ? 'text-green-600' : 'text-red-600'">{{ detailRecord?.score }}</span> 分
                  <span class="ml-2 text-xs">以下题目内容为本次考试开考时锁定的版本</span>
                </p>
              </div>
              <button @click="showDetail = false" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="flex-1 overflow-y-auto p-6">
              <div v-if="loadingDetail" class="text-center py-8">
                <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mx-auto"></div>
              </div>
              <div v-else class="space-y-4">
                <div v-for="(q, index) in detailQuestions" :key="q.question_id" class="border rounded-lg p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="bg-indigo-100 text-indigo-800 text-xs font-medium px-2 py-0.5 rounded">{{ index + 1 }}</span>
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-800 text-white">v{{ q.question_version }}</span>
                    <span class="text-xs text-gray-500">{{ questionTypeLabel(q.type) }} · {{ q.score }}分</span>
                    <span v-if="q.is_correct === true" class="ml-auto px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">正确 +{{ q.got_score }}分</span>
                    <span v-else-if="q.is_correct === false" class="ml-auto px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">错误</span>
                  </div>
                  <p class="text-sm font-medium text-gray-900">{{ q.title }}</p>
                  <div v-if="q.options" class="mt-2 grid grid-cols-2 gap-1 text-xs text-gray-600">
                    <div v-for="(label, key) in q.options" :key="key">{{ key }}. {{ label }}</div>
                  </div>
                  <div class="mt-3 text-xs space-y-1 bg-gray-50 rounded p-2">
                    <p><span class="text-gray-500">我的答案：</span><span class="font-medium" :class="q.is_correct ? 'text-green-700' : 'text-red-700'">{{ q.my_answer ?? '（未作答）' }}</span></p>
                    <p><span class="text-gray-500">正确答案：</span><span class="font-medium text-gray-800">{{ q.correct_answer }}</span></p>
                    <p v-if="q.analysis"><span class="text-gray-500">解析：</span><span class="text-gray-700">{{ q.analysis }}</span></p>
                  </div>
                </div>
                <div v-if="detailQuestions.length === 0" class="text-center text-gray-500 py-8">暂无题目快照</div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end">
              <button @click="showDetail = false" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">关闭</button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showDetail = false"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)

const showDetail = ref(false)
const detailRecord = ref(null)
const detailQuestions = ref([])
const loadingDetail = ref(false)

const questionTypeLabel = (type) => {
  const labels = {
    single_choice: '单选题',
    multiple_choice: '多选题',
    true_false: '判断题',
    fill_blank: '填空题',
    essay: '问答题'
  }
  return labels[type] || type
}

const openDetail = async (record) => {
  detailRecord.value = record
  detailQuestions.value = []
  loadingDetail.value = true
  showDetail.value = true
  try {
    const response = await api.get(`/exams/records/${record.id}`)
    detailRecord.value = response.data.record
    detailQuestions.value = response.data.questions || []
  } catch (e) {
    console.error('Failed to fetch record detail:', e)
  } finally {
    loadingDetail.value = false
  }
}

onMounted(async () => {
  try {
    const response = await api.get('/exams/records')
    records.value = response.data.records.data
  } catch (e) {
    console.error('Failed to fetch records:', e)
  } finally {
    loading.value = false
  }
})
</script>
