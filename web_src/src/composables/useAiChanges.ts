/**
 * useAiChanges - 监听 AI Agent 的 changes 事件并执行页面刷新
 *
 * 在 App.vue 中调用 useAiChanges() 即可全局监听。
 * 根据 operations 数组中的 action 类型，智能判断需要刷新哪个区域。
 * 使用 300ms 防抖合并多次 changes 事件，避免频繁刷新。
 */
import { onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { Emitter } from 'mitt'
import { useItemStore } from '@/store'

type Operation = {
  action: string
  item_id?: number
  page_id?: number
  page_title?: string
  cat_id?: number
  item_name?: string
}

type ChangesPayload = {
  operations: Operation[]
  sessionId?: string
}

// 看板相关的 action 集合
const KANBAN_ACTIONS = new Set([
  'create_task',
  'update_task',
  'delete_task',
  'create_list',
  'update_list',
  'delete_list',
  'archive_list',
  'restore_list',
])

// 项目级 action 集合
const ITEM_ACTIONS = new Set(['create_item', 'update_item', 'delete_item'])

// 页面/目录级 action 集合
const PAGE_ACTIONS = new Set(['create', 'update', 'delete'])

// 防抖间隔（ms）
const DEBOUNCE_MS = 300

export function useAiChanges() {
  const route = useRoute()
  const router = useRouter()
  const itemStore = useItemStore()
  let emitter: Emitter<any> | null = null
  let handler: ((payload: ChangesPayload) => void) | null = null

  // 防抖相关状态
  let pendingOperations: Operation[] = []
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  /**
   * 判断当前是否在项目首页（项目列表页）
   */
  const isOnItemListPage = () => {
    return route.path === '/item/index' || route.name === 'ItemIndex'
  }

  /**
   * 判断当前是否在某个项目详情页内
   * 返回 item_id 或 null。优先读 Pinia itemStore（兼容 item_domain 项目）。
   */
  const getCurrentItemId = (): number | null => {
    const storeItemId = Number(itemStore.itemId)
    if (!isNaN(storeItemId) && storeItemId > 0) return storeItemId
    // 兜底：路由格式 /:item_id 或 /:item_id/:page_id
    const itemIdParam = route.params.item_id as string
    if (itemIdParam) {
      const id = parseInt(itemIdParam)
      if (!isNaN(id) && id > 0) return id
    }
    return null
  }

  /**
   * 刷新项目列表（如果在项目列表页）
   */
  const refreshItemList = () => {
    if (!isOnItemListPage()) return
    window.location.reload()
  }

  /**
   * 刷新项目详情页（目录树 + 页面内容）
   * 不再使用 window.location.reload()，改用 CustomEvent 通知 Index.vue 重新获取数据
   */
  const refreshItemShow = (itemId: number) => {
    const currentItemId = getCurrentItemId()
    // currentItemId 为 null 时无法判断，不跳过，让接收方自行决定
    if (currentItemId !== null && currentItemId !== itemId) {
      return
    }

    const showIndexEvent = new CustomEvent('ai:refresh-item', {
      detail: { item_id: itemId },
    })
    window.dispatchEvent(showIndexEvent)
  }

  /**
   * 如果当前在被删除的页面，跳回项目首页
   */
  const navigateAwayFromDeletedPage = (itemId: number, pageId: number) => {
    const currentItemId = getCurrentItemId()
    if (currentItemId !== itemId) return

    const pageIdParam = route.params.page_id as string
    const queryPageId = route.query.page_id as string
    const currentPageId = parseInt(pageIdParam || queryPageId || '0')

    if (currentPageId === pageId) {
      const domain = currentItemId
      router.replace(`/${domain}`)
    }
  }

  /**
   * 如果当前在被删除的项目，跳回首页
   */
  const navigateAwayFromDeletedItem = (itemId: number) => {
    const currentItemId = getCurrentItemId()
    if (currentItemId === itemId) {
      router.replace('/item/index')
    }
  }

  /**
   * 刷新看板视图（如果在看板项目页）
   */
  const refreshKanban = (itemId: number) => {
    const currentItemId = getCurrentItemId()
    if (currentItemId !== itemId) return

    window.dispatchEvent(new CustomEvent('ai:refresh-kanban', {
      detail: { item_id: itemId },
    }))
  }

  /**
   * 处理单个 operation
   */
  const handleOperation = (op: Operation) => {
    const action = op.action

    // 看板操作
    if (KANBAN_ACTIONS.has(action)) {
      const itemId = op.item_id || 0
      if (itemId > 0) {
        refreshKanban(itemId)
      }
      return
    }

    // 项目级操作
    if (ITEM_ACTIONS.has(action)) {
      const itemId = op.item_id || 0

      if (action === 'delete_item') {
        if (itemId > 0) {
          navigateAwayFromDeletedItem(itemId)
        }
      }

      refreshItemList()
      return
    }

    // 页面/目录操作
    if (PAGE_ACTIONS.has(action)) {
      const itemId = op.item_id || 0

      if (action === 'delete') {
        if (itemId > 0) {
          refreshItemShow(itemId)
        }
      } else if (action === 'create' || action === 'update') {
        if (itemId > 0) {
          refreshItemShow(itemId)
        }
      }
    }
  }

  /**
   * 批量处理 operations（去重）
   */
  const processOperations = (operations: Operation[]) => {
    if (!operations || operations.length === 0) return

    // 收集需要执行的操作，避免重复
    const seenItems = new Set<number>()
    let hasItemOp = false
    let deletedItem: number | null = null
    const deletedPages: Array<{ itemId: number; pageId: number }> = []

    for (const op of operations) {
      // 优先处理项目级操作
      if (ITEM_ACTIONS.has(op.action)) {
        hasItemOp = true
        if (op.action === 'delete_item' && op.item_id) {
          deletedItem = op.item_id
        }
        continue
      }

      // 看板操作
      if (KANBAN_ACTIONS.has(op.action)) {
        const itemId = op.item_id || 0
        if (itemId > 0 && !seenItems.has(itemId)) {
          seenItems.add(itemId)
          handleOperation(op)
        }
        continue
      }

      // 页面/目录操作
      if (PAGE_ACTIONS.has(op.action)) {
        const itemId = op.item_id || 0

        if (op.action === 'delete' && op.page_id) {
          deletedPages.push({ itemId, pageId: op.page_id })
        }

        // 同一项目只刷新一次
        if (itemId > 0 && !seenItems.has(itemId)) {
          seenItems.add(itemId)
          handleOperation(op)
        }
      }
    }

    // 处理项目级操作
    if (deletedItem !== null) {
      navigateAwayFromDeletedItem(deletedItem)
    }

    if (hasItemOp) {
      refreshItemList()
    }

    // 处理被删除的页面
    for (const { itemId, pageId } of deletedPages) {
      navigateAwayFromDeletedPage(itemId, pageId)
    }
  }

  /**
   * 防抖处理：收集 operations，延迟合并处理
   */
  const debouncedProcess = () => {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
    }
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      const ops = pendingOperations
      pendingOperations = []
      try {
        processOperations(ops)
      } catch (e) {
        console.error('[useAiChanges] Error processing operations:', e)
      }
    }, DEBOUNCE_MS)
  }

  onMounted(() => {
    emitter = (window as any).__MAIN_EMITTER__
    if (!emitter) {
      console.warn('[useAiChanges] __MAIN_EMITTER__ not found')
      return
    }

    handler = (payload: ChangesPayload) => {
      try {
        const ops = payload.operations || []
        // 合并到待处理队列，防抖延迟处理
        pendingOperations.push(...ops)
        debouncedProcess()
      } catch (e) {
        console.error('[useAiChanges] Error processing changes:', e)
      }
    }

    emitter.on('ai:changes', handler as any)
  })

  onUnmounted(() => {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    if (emitter && handler) {
      emitter.off('ai:changes', handler as any)
    }
  })
}
