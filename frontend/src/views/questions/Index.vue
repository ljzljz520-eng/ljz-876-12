<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">题库管理</h1>
      <button @click="openAddModal" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">添加题目</button>
    </div>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-2 text-gray-500">加载中...</p>
    </div>
    <div v-else-if="questions.length === 0" class="text-center py-8 text-gray-500 bg-white rounded-lg shadow">
      暂无题目，请点击"添加题目"创建
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">题目</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">类型</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">版本</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">分值</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="q in questions" :key="q.id" class="hover:bg-gray-50" :class="{'bg-red-50/40': isWithdrawn(q)}">
            <td class="px-6 py-4">{{ q.id }}</td>
            <td class="px-6 py-4 truncate max-w-xs">
              {{ q.title }}
              <span v-if="isWithdrawn(q)" class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">已撤回</span>
            </td>
            <td class="px-6 py-4">{{ getTypeName(q.type) }}</td>
            <td class="px-6 py-4">
              <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800">v{{ q.current_version || 1 }}</span>
            </td>
            <td class="px-6 py-4">{{ q.score }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <button @click="openEditModal(q)" class="text-indigo-600 hover:text-indigo-900 mr-3">编辑</button>
              <button @click="openVersionsModal(q)" class="text-gray-600 hover:text-gray-900 mr-3">历史</button>
              <button v-if="!isWithdrawn(q)" @click="openWithdrawModal(q)" class="text-orange-600 hover:text-orange-900 mr-3">撤回</button>
              <button v-else @click="restoreQuestion(q)" class="text-green-600 hover:text-green-900 mr-3">恢复</button>
              <button @click="deleteQuestion(q)" class="text-red-600 hover:text-red-900">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 添加/编辑题目模态框 (Business Modal - z-50) -->
    <Teleport to="body">
      <Transition
        enter-active-class="ease-out duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm transition-opacity" @click="closeModal"></div>

          <!-- Modal Panel -->
          <div class="flex min-h-full items-center justify-center p-4">
            <Transition
              enter-active-class="ease-out duration-200"
              enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enter-to-class="opacity-100 translate-y-0 sm:scale-100"
              leave-active-class="ease-in duration-150"
              leave-from-class="opacity-100 translate-y-0 sm:scale-100"
              leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <div class="relative w-full max-w-2xl transform overflow-hidden rounded-2xl bg-white shadow-2xl transition-all border border-gray-100">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                  <h3 class="text-lg font-bold text-gray-900">{{ isEditing ? '编辑题目' : '添加题目' }}</h3>
                  <button @click="closeModal" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100 transition-colors">
                    <span class="sr-only">Close</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <!-- Body -->
                <div class="px-6 py-6 max-h-[calc(100vh-14rem)] overflow-y-auto custom-scrollbar">
                  <div v-if="isEditing" class="mb-5 p-3 rounded-lg bg-blue-50 border border-blue-100 text-sm text-blue-800">
                    保存后将生成新版本 v{{ (editingVersion || 1) + 1 }}；已经开考的试卷会继续使用当时锁定的版本，不受本次修改影响。
                  </div>
                  <div class="space-y-5">
                    <!-- Category -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">分类 <span class="text-red-500">*</span></label>
                      <select v-model="form.category_id" class="input-base">
                        <option value="">请选择分类</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                      </select>
                    </div>

                    <!-- Type -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">题目类型</label>
                      <select v-model="form.type" class="input-base">
                        <option value="single_choice">单选题</option>
                        <option value="multiple_choice">多选题</option>
                        <option value="true_false">判断题</option>
                        <option value="fill_blank">填空题</option>
                        <option value="essay">问答题</option>
                      </select>
                    </div>

                    <!-- Content -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">题目内容 <span class="text-red-500">*</span></label>
                      <textarea v-model="form.title" rows="3" class="input-base resize-y" placeholder="请输入题目详细描述..."></textarea>
                    </div>

                    <!-- Options -->
                    <div v-if="['single_choice', 'multiple_choice'].includes(form.type)" class="bg-gray-50 p-4 rounded-xl border border-gray-200/60">
                      <label class="block text-sm font-semibold text-gray-700 mb-3">选项设置</label>
                      <div class="space-y-3">
                        <div v-for="(opt, key) in ['A', 'B', 'C', 'D']" :key="key" class="flex items-center gap-3">
                          <span class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 text-sm font-medium text-gray-500 shadow-sm">{{ opt }}</span>
                          <input v-model="form.options[opt]" type="text" class="input-base flex-1" :placeholder="`输入选项 ${opt} 的内容`" />
                        </div>
                      </div>
                    </div>

                    <!-- Answer -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">正确答案 <span class="text-red-500">*</span></label>
                      <input v-model="form.answer" type="text" class="input-base" :placeholder="getAnswerPlaceholder()" />
                      <p class="mt-1 text-xs text-gray-500">提示: {{ getAnswerPlaceholder() }}</p>
                    </div>

                    <!-- Analysis -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">解析</label>
                      <textarea v-model="form.analysis" rows="2" class="input-base resize-y" placeholder="请输入题目解析（选填）"></textarea>
                    </div>

                    <!-- Meta -->
                    <div class="grid grid-cols-2 gap-5">
                      <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">难度</label>
                        <select v-model="form.difficulty" class="input-base">
                          <option :value="1">简单</option>
                          <option :value="2">中等</option>
                          <option :value="3">困难</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">分值</label>
                        <input v-model="form.score" type="number" min="0" step="0.5" class="input-base" />
                      </div>
                    </div>

                    <!-- Change summary (edit only) -->
                    <div v-if="isEditing">
                      <label class="block text-sm font-semibold text-gray-700 mb-2">版本说明</label>
                      <input v-model="form.change_summary" type="text" maxlength="255" class="input-base" placeholder="简要说明本次修改内容（选填），如：修正选项C错别字" />
                    </div>
                  </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 md:flex md:flex-row-reverse md:gap-3">
                  <button
                    @click="saveQuestion"
                    :disabled="saving"
                    class="btn-primary w-full md:w-auto min-w-[100px]"
                  >
                    <span v-if="saving" class="flex items-center gap-2">
                       <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                       保存中...
                    </span>
                    <span v-else>保存</span>
                  </button>
                  <button
                    @click="closeModal"
                    class="btn-secondary w-full md:w-auto mt-3 md:mt-0 min-w-[80px]"
                  >
                    取消
                  </button>
                </div>
              </div>
            </Transition>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- 版本历史模态框 -->
    <Teleport to="body">
      <div v-if="showVersionsModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b flex justify-between items-center">
              <h3 class="text-lg font-semibold">
                版本历史 - 题目 #{{ versionsQuestion?.id }}
                <span class="ml-2 text-sm font-normal text-gray-500">当前版本 v{{ versionsQuestion?.current_version || 1 }}</span>
              </h3>
              <button @click="showVersionsModal = false" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="flex-1 overflow-y-auto p-6">
              <div v-if="loadingVersions" class="text-center py-8">
                <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mx-auto"></div>
              </div>
              <div v-else class="space-y-4">
                <div v-for="v in versionsList" :key="v.id" class="border rounded-lg p-4" :class="{'border-indigo-300 bg-indigo-50/40': v.version === versionsQuestion?.current_version}">
                  <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                      <span class="px-2 py-0.5 text-xs font-semibold rounded-full" :class="v.version === versionsQuestion?.current_version ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'">v{{ v.version }}</span>
                      <span v-if="v.version === versionsQuestion?.current_version" class="text-xs text-indigo-600 font-medium">当前版本</span>
                      <span v-if="v.change_summary" class="text-xs text-gray-500">— {{ v.change_summary }}</span>
                    </div>
                    <span class="text-xs text-gray-400">{{ formatTime(v.created_at) }}<template v-if="v.creator"> · {{ v.creator.real_name || v.creator.username }}</template></span>
                  </div>
                  <p class="text-sm text-gray-900 font-medium">{{ v.title }}</p>
                  <div v-if="v.options" class="mt-2 grid grid-cols-2 gap-1 text-xs text-gray-600">
                    <div v-for="(label, key) in v.options" :key="key">{{ key }}. {{ label }}</div>
                  </div>
                  <div class="mt-2 text-xs text-gray-500 space-x-4">
                    <span>答案: <span class="font-medium text-gray-700">{{ v.answer }}</span></span>
                    <span v-if="v.analysis">解析: {{ v.analysis }}</span>
                  </div>
                </div>
                <div v-if="versionsList.length === 0" class="text-center text-gray-500 py-8">暂无版本记录</div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end">
              <button @click="showVersionsModal = false" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">关闭</button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showVersionsModal = false"></div>
      </div>
    </Teleport>

    <!-- 撤回确认模态框（展示受影响的历史考试） -->
    <Teleport to="body">
      <div v-if="showWithdrawModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b">
              <h3 class="text-lg font-semibold text-gray-900">撤回题目 #{{ withdrawTarget?.id }}</h3>
              <p class="mt-1 text-sm text-gray-500 truncate">{{ withdrawTarget?.title }}</p>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-4">
              <div v-if="loadingAffected" class="text-center py-8">
                <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mx-auto"></div>
                <p class="mt-2 text-sm text-gray-500">正在分析受影响的历史考试...</p>
              </div>
              <template v-else>
                <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800 mb-4">
                  撤回后：新考试（含补考卷）将不再使用该题；<strong>已经开考的试卷不受影响</strong>，仍使用开考时锁定的版本。
                </div>
                <div v-if="affectedExams.length > 0">
                  <h4 class="text-sm font-semibold text-gray-900 mb-2">以下历史考试引用了该题（共 {{ affectedExams.length }} 份试卷）：</h4>
                  <div class="border rounded-lg divide-y">
                    <div v-for="exam in affectedExams" :key="exam.exam_paper_id" class="px-4 py-3 flex items-center justify-between">
                      <div>
                        <p class="text-sm font-medium text-gray-900">{{ exam.title }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                          成绩记录 {{ exam.total_records }} 条
                          <span v-if="exam.in_progress_records > 0" class="text-orange-600">（{{ exam.in_progress_records }} 场进行中）</span>
                        </p>
                      </div>
                      <div v-if="exam.versions_used.length > 0" class="flex gap-1">
                        <span v-for="v in exam.versions_used" :key="v" class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600">v{{ v }}</span>
                      </div>
                      <span v-else class="text-xs text-gray-400">尚未开考</span>
                    </div>
                  </div>
                </div>
                <p v-else class="text-sm text-gray-500 text-center py-4">该题目未被任何试卷引用，可安全撤回。</p>
              </template>
            </div>
            <div class="px-6 py-4 border-t flex justify-end space-x-3">
              <button @click="showWithdrawModal = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
              <button @click="confirmWithdraw" :disabled="withdrawing || loadingAffected" class="px-4 py-2 bg-orange-600 text-white rounded hover:bg-orange-700 disabled:opacity-50">
                {{ withdrawing ? '撤回中...' : '确认撤回' }}
              </button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showWithdrawModal = false"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const { confirm, alert } = useModal()
const toast = useToast()
const shouldUseGlobalErrorModal = (status) => status === 422 || status === 403 || status >= 500

const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const editingVersion = ref(1)
const saving = ref(false)
const questions = ref([])
const categories = ref([])
const loading = ref(true)

// 版本历史
const showVersionsModal = ref(false)
const versionsQuestion = ref(null)
const versionsList = ref([])
const loadingVersions = ref(false)

// 撤回
const showWithdrawModal = ref(false)
const withdrawTarget = ref(null)
const affectedExams = ref([])
const loadingAffected = ref(false)
const withdrawing = ref(false)

const defaultForm = {
  category_id: '',
  type: 'single_choice',
  title: '',
  options: { A: '', B: '', C: '', D: '' },
  answer: '',
  analysis: '',
  difficulty: 1,
  score: 1,
  change_summary: ''
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

const isWithdrawn = (q) => !!q.withdrawn_at || q.status === 0 || q.status === false

const formatTime = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString('zh-CN')
}

const getAnswerPlaceholder = () => {
  if (form.value.type === 'single_choice') return '如：A'
  if (form.value.type === 'multiple_choice') return '如：ABD'
  if (form.value.type === 'true_false') return '如：true 或 false'
  return '请输入答案'
}

const fetchQuestions = async () => {
  try {
    const response = await api.get('/questions')
    questions.value = response.data.questions.data
  } catch (e) {
    console.error('Failed to fetch questions:', e)
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

const openAddModal = () => {
  isEditing.value = false
  editingId.value = null
  editingVersion.value = 1
  form.value = { ...defaultForm, options: { A: '', B: '', C: '', D: '' } }
  showModal.value = true
}

const openEditModal = (question) => {
  isEditing.value = true
  editingId.value = question.id
  editingVersion.value = question.current_version || 1
  form.value = {
    category_id: question.category_id,
    type: question.type,
    title: question.title,
    options: question.options || { A: '', B: '', C: '', D: '' },
    answer: question.answer,
    analysis: question.analysis || '',
    difficulty: question.difficulty,
    score: question.score,
    change_summary: ''
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveQuestion = async () => {
  if (!form.value.category_id || !form.value.title || !form.value.answer) {
    alert('请填写必填字段：分类、题目内容、正确答案', '提示', 'warning')
    return
  }
  saving.value = true
  try {
    const data = { ...form.value }
    if (!['single_choice', 'multiple_choice'].includes(data.type)) {
      data.options = null
    }
    if (isEditing.value) {
      const response = await api.put(`/questions/${editingId.value}`, data)
      const locked = response.data.locked_exams || []
      const lockedCount = locked.reduce((sum, e) => sum + (e.in_progress_records || 0), 0)
      toast.success(
        lockedCount > 0
          ? `已生成新版本 v${response.data.version}；${lockedCount} 场进行中的考试仍锁定旧版本，不受影响`
          : `已生成新版本 v${response.data.version}，新考试将使用新版题目`
      )
    } else {
      await api.post('/questions', data)
      toast.success('题目创建成功（v1）')
    }
    closeModal()
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to save question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '保存失败')
    }
  } finally {
    saving.value = false
  }
}

const openVersionsModal = async (question) => {
  versionsQuestion.value = question
  versionsList.value = []
  loadingVersions.value = true
  showVersionsModal.value = true
  try {
    const response = await api.get(`/questions/${question.id}/versions`)
    versionsList.value = response.data.versions
  } catch (e) {
    console.error('Failed to fetch versions:', e)
  } finally {
    loadingVersions.value = false
  }
}

const openWithdrawModal = async (question) => {
  withdrawTarget.value = question
  affectedExams.value = []
  loadingAffected.value = true
  showWithdrawModal.value = true
  try {
    const response = await api.get(`/questions/${question.id}/affected-exams`)
    affectedExams.value = response.data.affected_exams
  } catch (e) {
    console.error('Failed to fetch affected exams:', e)
  } finally {
    loadingAffected.value = false
  }
}

const confirmWithdraw = async () => {
  if (!withdrawTarget.value) return
  withdrawing.value = true
  try {
    const response = await api.post(`/questions/${withdrawTarget.value.id}/withdraw`)
    const affected = response.data.affected_exams || []
    const totalRecords = affected.reduce((sum, e) => sum + (e.total_records || 0), 0)
    toast.success(
      totalRecords > 0
        ? `题目已撤回，${affected.length} 份试卷的 ${totalRecords} 条历史成绩保留原版本不受影响`
        : '题目已撤回'
    )
    showWithdrawModal.value = false
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to withdraw question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '撤回失败')
    }
  } finally {
    withdrawing.value = false
  }
}

const restoreQuestion = async (question) => {
  const confirmed = await confirm(`确定要恢复题目 "${question.title.substring(0, 20)}..." 吗？恢复后新考试可继续使用该题。`, '恢复确认')
  if (!confirmed) return
  try {
    await api.post(`/questions/${question.id}/restore`)
    toast.success('题目已恢复')
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to restore question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '恢复失败')
    }
  }
}

const deleteQuestion = async (question) => {
  const confirmed = await confirm(`确定要删除题目 "${question.title.substring(0, 20)}..." 吗？`, '删除确认')
  if (!confirmed) return
  try {
    await api.delete(`/questions/${question.id}`)
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to delete question:', e)
  }
}

onMounted(() => {
  fetchQuestions()
  fetchCategories()
})
</script>
