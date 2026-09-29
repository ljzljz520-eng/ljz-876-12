<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">试卷管理</h1>
      <button @click="openAddModal" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">创建试卷</button>
    </div>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-2 text-gray-500">加载中...</p>
    </div>
    <div v-else-if="examPapers.length === 0" class="text-center py-8 text-gray-500 bg-white rounded-lg shadow">
      暂无试卷，请点击"创建试卷"创建
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">题目数</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">总分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">时长</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="paper in examPapers" :key="paper.id" class="hover:bg-gray-50">
            <td class="px-6 py-4">{{ paper.id }}</td>
            <td class="px-6 py-4">
              {{ paper.title }}
              <span
                v-if="paper.published && paper.outdated_question_count > 0"
                class="ml-2 inline-block text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700 border border-amber-200"
                :title="`有 ${paper.outdated_question_count} 道题被教师修订过，重新发布后补考卷使用新版`"
              >
                {{ paper.outdated_question_count }} 题有新版
              </span>
            </td>
            <td class="px-6 py-4">
              <span v-if="paper.published" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">已发布·版本锁定</span>
              <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">草稿</span>
            </td>
            <td class="px-6 py-4">{{ paper.question_count }} 题</td>
            <td class="px-6 py-4">{{ paper.total_score }} 分</td>
            <td class="px-6 py-4">{{ paper.total_time }} 分钟</td>
            <td class="px-6 py-4 space-x-2 whitespace-nowrap">
              <button @click="openQuestionModal(paper)" class="text-green-600 hover:text-green-900">管理题目</button>
              <button
                v-if="!paper.published"
                @click="publishPaper(paper)"
                class="text-indigo-600 hover:text-indigo-900"
              >发布</button>
              <button
                v-else
                @click="republishPaper(paper)"
                class="text-amber-600 hover:text-amber-900"
              >{{ paper.outdated_question_count > 0 ? '重新发布(用新版)' : '重新发布' }}</button>
              <button @click="openEditModal(paper)" class="text-indigo-600 hover:text-indigo-900">编辑</button>
              <button @click="deletePaper(paper)" class="text-red-600 hover:text-red-900">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 添加/编辑试卷模态框 -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
            <div class="px-6 py-4 border-b">
              <h3 class="text-lg font-semibold">{{ isEditing ? '编辑试卷' : '创建试卷' }}</h3>
            </div>
            <div class="px-6 py-4">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">试卷标题</label>
                  <input v-model="form.title" type="text" class="w-full border rounded px-3 py-2" placeholder="请输入试卷标题" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">试卷描述</label>
                  <textarea v-model="form.description" rows="3" class="w-full border rounded px-3 py-2" placeholder="请输入试卷描述（可选）"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">考试时长（分钟）</label>
                    <input v-model="form.total_time" type="number" min="1" class="w-full border rounded px-3 py-2" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">出题方式</label>
                    <select v-model="form.type" class="w-full border rounded px-3 py-2">
                      <option value="fixed">固定</option>
                      <option value="random">随机</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end space-x-3">
              <button @click="closeModal" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
              <button @click="savePaper" :disabled="saving" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
                {{ saving ? '保存中...' : '保存' }}
              </button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="closeModal"></div>
      </div>
    </Teleport>

    <!-- 管理题目模态框 -->
    <Teleport to="body">
      <div v-if="showQuestionModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b flex justify-between items-center">
              <h3 class="text-lg font-semibold">管理题目 - {{ currentPaper?.title }}</h3>
              <span class="text-sm text-gray-500">
                已选 {{ paperQuestions.length }} 题，共 {{ paperTotalScore }} 分
              </span>
            </div>

            <!-- 版本锁定提示条 -->
            <div class="px-6 py-3 border-b text-sm"
                 :class="paperOutdatedCount > 0 ? 'bg-amber-50 text-amber-800' : (currentPaper?.published ? 'bg-green-50 text-green-800' : 'bg-gray-50 text-gray-600')">
              <template v-if="currentPaper?.published">
                <span v-if="paperOutdatedCount > 0">
                  本试卷已发布并锁定版本，其中 {{ paperOutdatedCount }} 道题有教师修订的新版本。已开考的考试仍用旧版；补考卷要点
                  <button @click="republishCurrent" class="underline font-semibold mx-0.5">重新发布</button>
                  才会引用新版。
                </span>
                <span v-else>本试卷已发布，题目版本已锁定，之后教师改题不影响已开考的考试。</span>
              </template>
              <span v-else>当前为草稿，添加题目后请返回列表点击"发布"，发布时锁定题目版本。</span>
            </div>

            <div class="flex-1 overflow-hidden flex">
              <!-- 左侧：已选题目 -->
              <div class="w-1/2 border-r flex flex-col">
                <div class="px-4 py-2 bg-gray-50 border-b font-medium text-sm">已选题目</div>
                <div class="flex-1 overflow-y-auto p-4">
                  <div v-if="paperQuestions.length === 0" class="text-center text-gray-500 py-8">
                    暂无题目，请从右侧添加
                  </div>
                  <div v-else class="space-y-2">
                    <div v-for="(q, index) in paperQuestions" :key="q.id" class="p-3 border rounded hover:bg-gray-50 flex justify-between items-start">
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                          <span class="text-xs bg-gray-200 px-2 py-0.5 rounded">{{ index + 1 }}</span>
                          <span class="text-xs text-indigo-600">{{ getTypeName(q.type) }}</span>
                          <span class="text-xs text-orange-600">{{ q.pivot?.score || q.score }}分</span>
                          <span
                            class="text-xs px-1.5 py-0.5 rounded border"
                            :class="isPaperQuestionOutdated(q) ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-blue-50 text-blue-700 border-blue-200'"
                            :title="isPaperQuestionOutdated(q) ? `已锁定 v${lockedVersion(q)}，题库最新为 v${q.current_version || 1}，重新发布后更新` : `当前锁定的就是最新版本 v${lockedVersion(q)}`"
                          >
                            锁 v{{ lockedVersion(q) }}<template v-if="isPaperQuestionOutdated(q)"> → 新 v{{ q.current_version || 1 }}</template>
                          </span>
                        </div>
                        <p class="mt-1 text-sm truncate">{{ q.title }}</p>
                      </div>
                      <button @click="removeQuestion(q.id)" class="ml-2 text-red-500 hover:text-red-700 text-sm">移除</button>
                    </div>
                  </div>
                </div>
              </div>
              <!-- 右侧：题库 -->
              <div class="w-1/2 flex flex-col">
                <div class="px-4 py-2 bg-gray-50 border-b font-medium text-sm flex justify-between items-center">
                  <span>题库</span>
                  <select v-model="questionFilter.category_id" class="text-xs border rounded px-2 py-1">
                    <option value="">全部分类</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                  </select>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                  <div v-if="loadingQuestions" class="text-center py-8">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mx-auto"></div>
                  </div>
                  <div v-else-if="availableQuestions.length === 0" class="text-center text-gray-500 py-8">
                    暂无可用题目
                  </div>
                  <div v-else class="space-y-2">
                    <div v-for="q in availableQuestions" :key="q.id" class="p-3 border rounded hover:bg-gray-50 flex justify-between items-start">
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                          <span class="text-xs text-indigo-600">{{ getTypeName(q.type) }}</span>
                          <span class="text-xs text-orange-600">{{ q.score }}分</span>
                          <span class="text-xs text-gray-400">难度{{ q.difficulty }}</span>
                          <span class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">v{{ q.current_version || 1 }}</span>
                        </div>
                        <p class="mt-1 text-sm truncate">{{ q.title }}</p>
                      </div>
                      <button
                        v-if="!isQuestionInPaper(q.id)"
                        @click="addQuestion(q.id)"
                        class="ml-2 text-green-500 hover:text-green-700 text-sm"
                      >添加</button>
                      <span v-else class="ml-2 text-gray-400 text-sm">已添加</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end">
              <button @click="closeQuestionModal" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">完成</button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="closeQuestionModal"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const { confirm } = useModal()
const toast = useToast()
const shouldUseGlobalErrorModal = (status) => status === 422 || status === 403 || status >= 500

const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const saving = ref(false)
const examPapers = ref([])
const loading = ref(true)

// 题目管理相关
const showQuestionModal = ref(false)
const currentPaper = ref(null)
const paperQuestions = ref([])
const allQuestions = ref([])
const categories = ref([])
const loadingQuestions = ref(false)
const questionFilter = ref({ category_id: '' })

const defaultForm = {
  title: '',
  description: '',
  total_time: 60,
  type: 'fixed'
}

const form = ref({ ...defaultForm })

const typeNames = {
  single_choice: '单选题',
  multiple_choice: '多选题',
  true_false: '判断题',
  fill_blank: '填空题',
  essay: '问答题'
}

const getTypeName = (type) => typeNames[type] || type

// 计算已选题目总分
const paperTotalScore = computed(() => {
  return paperQuestions.value.reduce((sum, q) => sum + parseFloat(q.pivot?.score || q.score || 0), 0).toFixed(2)
})

// 题目被试卷锁定的版本（后端详情返回 locked_version；兼容旧字段 pivot.question_version）
const lockedVersion = (q) => q.locked_version ?? q.pivot?.question_version ?? 1
const isPaperQuestionOutdated = (q) => {
  if (q.has_newer_version !== undefined) return q.has_newer_version
  return Number(lockedVersion(q)) < Number(q.current_version || 1)
}
const paperOutdatedCount = computed(() => paperQuestions.value.filter(q => isPaperQuestionOutdated(q)).length)

// 过滤可用题目（排除已选；题库选择器只显示启用中的题）
const availableQuestions = computed(() => {
  let questions = allQuestions.value.filter(q => q.status !== 0)
  if (questionFilter.value.category_id) {
    questions = questions.filter(q => q.category_id == questionFilter.value.category_id)
  }
  return questions
})

// 判断题目是否已在试卷中
const isQuestionInPaper = (questionId) => {
  return paperQuestions.value.some(q => q.id === questionId)
}

const fetchExamPapers = async () => {
  try {
    const response = await api.get('/exam-papers')
    examPapers.value = response.data.exam_papers.data
  } catch (e) {
    console.error('Failed to fetch exam papers:', e)
  } finally {
    loading.value = false
  }
}

const fetchCategories = async () => {
  try {
    const response = await api.get('/questions/categories')
    categories.value = response.data.categories
  } catch (e) {
    console.error('Failed to fetch categories:', e)
  }
}

const fetchAllQuestions = async () => {
  loadingQuestions.value = true
  try {
    const response = await api.get('/questions', { params: { per_page: 1000, status: 'all' } })
    allQuestions.value = response.data.questions.data
  } catch (e) {
    console.error('Failed to fetch questions:', e)
  } finally {
    loadingQuestions.value = false
  }
}

const fetchPaperDetail = async (paperId) => {
  try {
    const response = await api.get(`/exam-papers/${paperId}`)
    paperQuestions.value = response.data.exam_paper.questions || []
  } catch (e) {
    console.error('Failed to fetch paper detail:', e)
  }
}

const openAddModal = () => {
  isEditing.value = false
  editingId.value = null
  form.value = { ...defaultForm }
  showModal.value = true
}

const openEditModal = (paper) => {
  isEditing.value = true
  editingId.value = paper.id
  form.value = {
    title: paper.title,
    description: paper.description || '',
    total_time: paper.total_time,
    type: paper.type
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const openQuestionModal = async (paper) => {
  currentPaper.value = paper
  showQuestionModal.value = true
  await Promise.all([
    fetchPaperDetail(paper.id),
    fetchAllQuestions(),
    fetchCategories()
  ])
}

const closeQuestionModal = () => {
  showQuestionModal.value = false
  currentPaper.value = null
  paperQuestions.value = []
  fetchExamPapers() // 刷新列表以更新题目数和总分
}

const addQuestion = async (questionId) => {
  try {
    await api.post(`/exam-papers/${currentPaper.value.id}/questions`, {
      question_ids: [questionId]
    })
    await fetchPaperDetail(currentPaper.value.id)
  } catch (e) {
    console.error('Failed to add question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '添加失败')
    }
  }
}

const removeQuestion = async (questionId) => {
  try {
    await api.delete(`/exam-papers/${currentPaper.value.id}/questions/${questionId}`)
    await fetchPaperDetail(currentPaper.value.id)
  } catch (e) {
    console.error('Failed to remove question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '移除失败')
    }
  }
}

const savePaper = async () => {
  if (!form.value.title) {
    toast.warning('请填写试卷标题')
    return
  }
  saving.value = true
  try {
    if (isEditing.value) {
      await api.put(`/exam-papers/${editingId.value}`, form.value)
    } else {
      await api.post('/exam-papers', form.value)
    }
    closeModal()
    await fetchExamPapers()
  } catch (e) {
    console.error('Failed to save exam paper:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '保存失败')
    }
  } finally {
    saving.value = false
  }
}

const deletePaper = async (paper) => {
  const confirmed = await confirm(`确定要删除试卷 "${paper.title}" 吗？`, '删除确认')
  if (!confirmed) return
  try {
    await api.delete(`/exam-papers/${paper.id}`)
    await fetchExamPapers()
  } catch (e) {
    console.error('Failed to delete exam paper:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '删除失败')
    }
  }
}

// 发布：锁定题目版本（补考场景重新发布会引用新版题，已开考考试不受影响）
const publishPaper = async (paper) => {
  try {
    const res = await api.post(`/exam-papers/${paper.id}/publish`)
    await fetchExamPapers()
    toast.success(res.data.message || '发布成功')
  } catch (e) {
    console.error('Failed to publish paper:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.message || e.response?.data?.error || '发布失败')
    }
  }
}

const republishPaper = async (paper) => {
  const tip = paper.outdated_question_count > 0
    ? `有 ${paper.outdated_question_count} 道题存在新版本。重新发布后，新的考试（含补考）将使用最新版题；已经开考或交卷的历史考试仍使用当时版本。确认重新发布？`
    : '重新发布会把所有题目刷新到最新版本。已开考/已交卷的历史考试不受影响。确认继续？'
  const ok = await confirm(tip, '重新发布确认')
  if (!ok) return
  await publishPaper(paper)
}

// 管理题目弹窗内的"重新发布"
const republishCurrent = async () => {
  if (!currentPaper.value) return
  await publishPaper(currentPaper.value)
  await fetchPaperDetail(currentPaper.value.id)
  currentPaper.value = examPapers.value.find(p => p.id === currentPaper.value.id) || currentPaper.value
}

onMounted(() => {
  fetchExamPapers()
})
</script>
