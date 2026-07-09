/**
 * useAiEdit - 监听 AI Agent 的 edit 事件并更新编辑器内容
 *
 * 当 AI 在回复中输出修改后的完整内容时，后端通过 SSE edit 事件发送，
 * AiAgentDialog 通过 mitt emitter 派发 ai:edit 事件（payload: { content, pageId, sessionId }）。
 * 此 composable 在编辑器组件中注册回调，收到事件时调用回调更新编辑器内容。
 *
 * 注意：本 composable 不会自动清理监听，调用方必须在 onBeforeUnmount 中调用返回的 cleanup()。
 *
 * 使用方式（在编辑器容器组件中）：
 * const { cleanup } = useAiEdit((content, ctx) => {
 *   editormdEditorRef.value?.setValue(content)
 * })
 */
import type { Emitter } from 'mitt'

type EditPayload = {
  content: string
  pageId?: number
  sessionId?: number
}

type EditContext = {
  pageId?: number
  sessionId?: number
}

type EditCallback = (content: string, ctx: EditContext) => void

/**
 * 注册 AI 编辑回调
 * @param onEdit 收到 edit 事件时的回调
 * @returns { cleanup } 必须调用 cleanup 清理监听（建议在 onBeforeUnmount 中调用）
 */
export function useAiEdit(onEdit: EditCallback): { cleanup: () => void } {
  let emitter: Emitter<any> | null = null
  let handler: ((payload: EditPayload) => void) | null = null

  const cleanup = () => {
    if (emitter && handler) {
      emitter.off('ai:edit', handler as any)
    }
    emitter = null
    handler = null
  }

  emitter = (window as any).__MAIN_EMITTER__
  if (emitter) {
    handler = (payload: EditPayload) => {
      try {
        onEdit(payload.content, { pageId: payload.pageId, sessionId: payload.sessionId })
      } catch (e) {
        console.error('[useAiEdit] Error in onEdit callback:', e)
      }
    }
    emitter.on('ai:edit', handler as any)
  } else {
    console.warn('[useAiEdit] __MAIN_EMITTER__ not found, edit events will not work')
  }

  return { cleanup }
}
