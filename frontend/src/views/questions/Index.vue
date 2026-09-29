<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">题库管理</h1>
      <button @click="openAddModal" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">添加题目</button>
    </div>

    <!-- 状态筛选 -->
    <div class="flex gap-2">
      <button
        v-for="tab in statusTabs"
        :key="tab.value"
        @click="statusFilter = tab.value; fetchQuestions()"
        class="px-3 py-1.5 rounded-full text-sm border transition-colors"
        :class="statusFilter === tab.value
          ? 'bg-indigo-600 text-white border-indigo-600'
          : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'"
      >{{ tab.label }}</button>
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
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">分值</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">版本/状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="q in questions" :key="q.id" class="hover:bg-gray-50" :class="{'opacity-60 bg-gray-50': q.status === 0}">
            <td class="px-6 py-4">{{ q.id }}</td>
            <td class="px-6 py-4 max-w-xs">
              <div class="truncate">{{ q.title }}</div>
              <span v-if="q.status === 0" class="inline-block mt-1 text-xs px-2 py-0.5 rounded bg-gray-200 text-gray-600">已撤回</span>
            </td>
            <td class="px-6 py-4">{{ getTypeName(q.type) }}</td>
            <td class="px-6 py-4">{{ q.score }}</td>
            <td class="px-6 py-4">
              <span class="inline-flex items-center text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                v{{ q.current_version || 1 }}
              </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <button @click="openEditModal(q)" class="text-indigo-600 hover:text-indigo-900 mr-3">编辑</button>
              <button @click="openVersionsModal(q)" class="text-gray-600 hover:text-gray-900 mr-3">版本</button>
              <button v-if="q.status !== 0" @click="openWithdrawModal(q)" class="text-amber-600 hover:text-amber-900 mr-3">撤回</button>
              <button v-else @click="restoreQuestion(q)" class="text-green-600 hover:text-green-900 mr-3">恢复</button>
              <button @click="deleteQuestion(q)" class="text-red-600 hover:text-red-900">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 添加/编辑题目模态框 -->
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
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm transition-opacity" @click="closeModal"></div>

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
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                  <h3 class="text-lg font-bold text-gray-900">
                    {{ isEditing ? `编辑题目（当前 v${editingVersion}）` : '添加题目' }}
                  </h3>
                  <button @click="closeModal" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100 transition-colors">
                    <span class="sr-only">Close</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <div v-if="isEditing" class="px-6 pt-3">
                  <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    修改题干、选项、正确答案或解析会自动生成新版本；已发布/已开考的考试继续使用旧版本，互不影响。
                  </p>
                </div>

                <div class="px-6 py-6 max-h-[calc(100vh-14rem)] overflow-y-auto custom-scrollbar">
                  <div class="space-y-5">
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">分类 <span class="text-red-500">*</span></label>
                      <select v-model="form.category_id" class="input-base">
                        <option value="">请选择分类</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                      </select>
                    </div>

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

                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">题目内容 <span class="text-red-500">*</span></label>
                      <textarea v-model="form.title" rows="3" class="input-base resize-y" placeholder="请输入题目详细描述..."></textarea>
                    </div>

                    <div v-if="['single_choice', 'multiple_choice'].includes(form.type)" class="bg-gray-50 p-4 rounded-xl border border-gray-200/60">
                      <label class="block text-sm font-semibold text-gray-700 mb-3">选项设置</label>
                      <div class="space-y-3">
                        <div v-for="(opt, key) in ['A', 'B', 'C', 'D']" :key="key" class="flex items-center gap-3">
                          <span class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 text-sm font-medium text-gray-500 shadow-sm">{{ opt }}</span>
                          <input v-model="form.options[opt]" type="text" class="input-base flex-1" :placeholder="`输入选项 ${opt} 的内容`" />
                        </div>
                      </div>
                    </div>

                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">正确答案 <span class="text-red-500">*</span></label>
                      <input v-model="form.answer" type="text" class="input-base" :placeholder="getAnswerPlaceholder()" />
                      <p class="mt-1 text-xs text-gray-500">提示: {{ getAnswerPlaceholder() }}</p>
                    </div>

                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">解析</label>
                      <textarea v-model="form.analysis" rows="2" class="input-base resize-y" placeholder="请输入题目解析（选填）"></textarea>
                    </div>

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
                  </div>
                </div>

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

    <!-- 撤回影响确认弹窗 -->
    <Teleport to="body">
      <div v-if="showWithdrawModal" class="fixed inset-0 z-[60] overflow-y-auto">
        <div class="fixed inset-0 bg-gray-600/75" @click="showWithdrawModal = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
              </span>
              <div>
                <h3 class="text-lg font-bold text-gray-900">撤回题目 · 影响面确认</h3>
                <p class="text-sm text-gray-500 truncate max-w-md">{{ withdrawTarget?.title }}</p>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
              <div v-if="impactLoading" class="text-center py-8 text-gray-400">
                <div class="animate-spin rounded-full h-7 w-7 border-b-2 border-indigo-600 mx-auto"></div>
                <p class="mt-2 text-sm">正在分析受影响的历史考试...</p>
              </div>

              <template v-else-if="impact">
                <div class="grid grid-cols-3 gap-3 text-center">
                  <div class="rounded-xl bg-gray-50 p-3">
                    <div class="text-xl font-bold text-gray-900">{{ impact.paper_count }}</div>
                    <div class="text-xs text-gray-500 mt-1">引用试卷</div>
                  </div>
                  <div class="rounded-xl bg-green-50 p-3">
                    <div class="text-xl font-bold text-green-700">{{ impact.graded_exam_count }}</div>
                    <div class="text-xs text-gray-500 mt-1">历史完成考试</div>
                  </div>
                  <div class="rounded-xl bg-blue-50 p-3">
                    <div class="text-xl font-bold text-blue-700">{{ impact.in_progress_exam_count }}</div>
                    <div class="text-xs text-gray-500 mt-1">进行中考试</div>
                  </div>
                </div>

                <div class="rounded-lg bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-800">
                  撤回后该题不会再出现在新组卷中；已经开考或已交卷的考试已锁定当时版本，判分与成绩展示均不受影响。
                </div>

                <div>
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">受影响的试卷</h4>
                  <div v-if="impact.papers.length === 0" class="text-sm text-gray-400 py-3 text-center">暂无试卷引用此题</div>
                  <ul v-else class="divide-y border rounded-lg">
                    <li v-for="p in impact.papers" :key="p.exam_paper_id" class="px-4 py-3 flex items-center justify-between text-sm">
                      <div>
                        <div class="font-medium text-gray-800">{{ p.title }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">
                          锁定 v{{ p.locked_version }}
                          <span v-if="p.has_newer_version" class="text-amber-600">（最新 v{{ p.current_version }}，可重新发布更新）</span>
                          · 完成 {{ p.graded_count }} 场 · 进行中 {{ p.in_progress_count }} 场
                        </div>
                      </div>
                      <span class="text-xs px-2 py-0.5 rounded" :class="p.published ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                        {{ p.published ? '已发布' : '草稿' }}
                      </span>
                    </li>
                  </ul>
                </div>

                <div v-if="impact.records.length > 0">
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">使用过此题的历史考试（最多展示 100 条）</h4>
                  <ul class="divide-y border rounded-lg max-h-52 overflow-y-auto">
                    <li v-for="r in impact.records" :key="r.exam_record_id" class="px-4 py-2.5 flex items-center justify-between text-sm">
                      <div>
                        <span class="text-gray-800">{{ r.exam_paper_title }}</span>
                        <span class="text-gray-400 mx-2">·</span>
                        <span class="text-gray-500">{{ r.student }}</span>
                      </div>
                      <span class="text-xs px-2 py-0.5 rounded"
                            :class="r.version_outdated ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'">
                        使用 v{{ r.used_version }}<template v-if="r.version_outdated"> / 最新 v{{ r.current_version }}</template>
                      </span>
                    </li>
                  </ul>
                </div>
              </template>
            </div>

            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
              <button @click="showWithdrawModal = false" class="px-4 py-2 border rounded-lg hover:bg-gray-100 text-sm">取消</button>
              <button @click="confirmWithdraw" :disabled="impactLoading"
                      class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-500 text-sm disabled:opacity-50">
                确认撤回
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- 版本历史弹窗 -->
    <Teleport to="body">
      <div v-if="showVersionsModal" class="fixed inset-0 z-[60] overflow-y-auto">
        <div class="fixed inset-0 bg-gray-600/75" @click="showVersionsModal = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b">
              <h3 class="text-lg font-bold text-gray-900">版本历史</h3>
              <p class="text-sm text-gray-500 truncate mt-0.5">{{ versionsTarget?.title }}</p>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-4">
              <div v-if="versionsLoading" class="text-center py-8 text-gray-400">
                <div class="animate-spin rounded-full h-7 w-7 border-b-2 border-indigo-600 mx-auto"></div>
              </div>
              <ol v-else class="relative border-l-2 border-gray-200 ml-2 space-y-5">
                <li v-for="v in versions" :key="v.version" class="ml-5">
                  <span class="absolute -left-[9px] mt-1.5 h-4 w-4 rounded-full"
                        :class="v.version === versionsCurrent ? 'bg-indigo-600 ring-4 ring-indigo-100' : 'bg-gray-300'"></span>
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-sm font-bold text-gray-900">v{{ v.version }}</span>
                    <span v-if="v.version === versionsCurrent" class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">当前版本</span>
                    <span class="text-xs text-gray-400">{{ formatTime(v.created_at) }} · {{ v.editor || '系统' }}</span>
                  </div>
                  <p class="text-xs text-gray-500 mt-1">{{ v.change_summary }}</p>
                  <div class="mt-2 text-sm text-gray-700 bg-gray-50 rounded-lg p-3 space-y-1">
                    <p><span class="text-gray-400">题干：</span>{{ v.title }}</p>
                    <p><span class="text-gray-400">答案：</span>{{ v.answer }}</p>
                    <p v-if="v.analysis"><span class="text-gray-400">解析：</span>{{ v.analysis }}</p>
                  </div>
                </li>
              </ol>
            </div>
            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end">
              <button @click="showVersionsModal = false" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">关闭</button>
            </div>
          </div>
        </div>
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
const statusFilter = ref('all')
const statusTabs = [
  { value: 'all', label: '全部' },
  { value: 'active', label: '启用中' },
  { value: 'withdrawn', label: '已撤回' }
]

// 撤回相关
const showWithdrawModal = ref(false)
const withdrawTarget = ref(null)
const impact = ref(null)
const impactLoading = ref(false)

// 版本历史相关
const showVersionsModal = ref(false)
const versionsTarget = ref(null)
const versions = ref([])
const versionsLoading = ref(false)
const versionsCurrent = ref(1)

const defaultForm = {
  category_id: '',
  type: 'single_choice',
  title: '',
  options: { A: '', B: '', C: '', D: '' },
  answer: '',
  analysis: '',
  difficulty: 1,
  score: 1
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
const formatTime = (t) => t ? new Date(t).toLocaleString() : '-'

const getAnswerPlaceholder = () => {
  if (form.value.type === 'single_choice') return '如：A'
  if (form.value.type === 'multiple_choice') return '如：ABD'
  if (form.value.type === 'true_false') return '如：true 或 false'
  return '请输入答案'
}

const fetchQuestions = async () => {
  try {
    const response = await api.get('/questions', { params: { status: statusFilter.value, per_page: 100 } })
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
    score: question.score
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
    let res
    if (isEditing.value) {
      res = await api.put(`/questions/${editingId.value}`, data)
    } else {
      res = await api.post('/questions', data)
    }
    closeModal()
    await fetchQuestions()
    if (res.data?.version_bumped) {
      alert(res.data.warning || `已生成新版本 v${res.data.new_version}。已开考的历史考试继续使用旧版本，不会受影响。`, '已保存为新版本', 'success')
    } else {
      toast.success(res.data?.message || '保存成功')
    }
  } catch (e) {
    console.error('Failed to save question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '保存失败')
    }
  } finally {
    saving.value = false
  }
}

const deleteQuestion = async (question) => {
  const confirmed = await confirm(`确定要删除题目 "${question.title.substring(0, 20)}..." 吗？建议使用"撤回"保留历史考试版本。`, '删除确认')
  if (!confirmed) return
  try {
    await api.delete(`/questions/${question.id}`)
    await fetchQuestions()
    toast.success('删除成功')
  } catch (e) {
    console.error('Failed to delete question:', e)
  }
}

// ---------- 撤回 ----------
const openWithdrawModal = async (question) => {
  withdrawTarget.value = question
  impact.value = null
  showWithdrawModal.value = true
  impactLoading.value = true
  try {
    const res = await api.get(`/questions/${question.id}/impact`)
    impact.value = res.data.impact
  } catch (e) {
    console.error('Failed to fetch impact:', e)
  } finally {
    impactLoading.value = false
  }
}

const confirmWithdraw = async () => {
  try {
    const res = await api.post(`/questions/${withdrawTarget.value.id}/withdraw`)
    showWithdrawModal.value = false
    await fetchQuestions()
    alert(res.data.message, '撤回完成', 'warning')
  } catch (e) {
    console.error('Failed to withdraw question:', e)
  }
}

const restoreQuestion = async (question) => {
  try {
    await api.post(`/questions/${question.id}/restore`)
    await fetchQuestions()
    toast.success('题目已恢复')
  } catch (e) {
    console.error('Failed to restore question:', e)
  }
}

// ---------- 版本历史 ----------
const openVersionsModal = async (question) => {
  versionsTarget.value = question
  versions.value = []
  showVersionsModal.value = true
  versionsLoading.value = true
  try {
    const res = await api.get(`/questions/${question.id}/versions`)
    versions.value = res.data.versions
    versionsCurrent.value = res.data.current_version
  } catch (e) {
    console.error('Failed to fetch versions:', e)
  } finally {
    versionsLoading.value = false
  }
}

onMounted(() => {
  fetchQuestions()
  fetchCategories()
})
</script>
