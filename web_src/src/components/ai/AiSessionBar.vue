<template>
  <div class="ai-session-bar" :class="{ 'mobile-session-bar': isMobile }">
    <!-- 移动端精简工具栏 -->
    <template v-if="isMobile">
      <div class="session-bar-left">
        <i class="fas fa-arrow-left mobile-back-btn" @click="$emit('toggle-collapse')"></i>
        <span class="scope-label">{{ scopeLabel }}</span>
      </div>
      <div class="session-bar-actions">
        <i class="fas fa-plus mobile-action-btn" @click="$emit('new-session')"></i>
        <i class="fas fa-list mobile-action-btn" @click="$emit('toggle-mobile-drawer')"></i>
      </div>
    </template>

    <!-- 桌面端完整工具栏 -->
    <template v-else>
      <div class="session-bar-left">
        <span class="scope-label">
          <i class="fas fa-robot" style="margin-right: 4px"></i>
          {{ scopeLabel }}
        </span>
      </div>
      <div class="session-bar-actions">
        <a-tooltip :title="$t('ai.clear_session')">
          <i class="fas fa-eraser" @click="$emit('clear-session')"></i>
        </a-tooltip>
        <a-tooltip :title="$t('ai.new_session')">
          <i class="fas fa-plus" @click="$emit('new-session')"></i>
        </a-tooltip>
        <a-tooltip :title="$t('ai.session_list')">
          <i class="fas fa-list" @click="showList = !showList"></i>
        </a-tooltip>
        <a-tooltip :title="$t('common.minimize')">
          <i class="fas fa-minus" @click="$emit('toggle-collapse')"></i>
        </a-tooltip>
        <a-tooltip :title="props.isFullscreen ? $t('common.exit_fullscreen') : $t('ai.fullscreen')">
          <i :class="props.isFullscreen ? 'fas fa-compress' : 'fas fa-expand'" @click="$emit('toggle-fullscreen')"></i>
        </a-tooltip>
        <a-tooltip :title="$t('common.close')">
          <i class="fas fa-times" @click="$emit('toggle-collapse')"></i>
        </a-tooltip>
      </div>

      <!-- 会话列表下拉 -->
      <div v-if="showList && sessions.length > 0" class="session-list-dropdown">
        <div class="session-list-header">
          <span>{{ $t('ai.session_list') }}</span>
          <i class="fas fa-times" @click="showList = false"></i>
        </div>
        <div
          v-for="s in sessions"
          :key="s.session_id"
          class="session-list-item"
          :class="{ active: s.session_id === currentSessionId }"
          @click="$emit('select', s); showList = false"
        >
          <div class="session-item-info">
            <span class="session-item-title">{{ s.title || $t('ai.session_item_default_title', { id: s.session_id }) }}</span>
            <span class="session-item-meta">
              {{ $t('ai.message_count', { count: s.message_count }) }}
              <span v-if="s.last_message_at" class="session-item-time">· {{ formatRelativeTime(s.last_message_at) }}</span>
            </span>
          </div>
          <a-popconfirm
            :title="$t('ai.confirm_delete_session')"
            @confirm.stop="$emit('delete', s)"
            :ok-text="$t('ai.confirm')"
            :cancel-text="$t('ai.cancel')"
          >
            <i class="fas fa-trash-alt session-delete" @click.stop></i>
          </a-popconfirm>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import 'dayjs/locale/zh-cn'
import type { AiChatSession } from '@/api/aiAgent'

dayjs.extend(relativeTime)
dayjs.locale('zh-cn')

const { t: $t } = useI18n()

const props = defineProps<{
  sessions: AiChatSession[]
  currentSessionId: number | null
  globalMode: boolean
  itemName?: string
  isFullscreen?: boolean
  isMobile?: boolean
}>()

defineEmits<{
  select: [session: AiChatSession]
  'new-session': []
  delete: [session: AiChatSession]
  'toggle-collapse': []
  'toggle-fullscreen': []
  'clear-session': []
  'toggle-mobile-drawer': []
}>()

const showList = ref(false)

/** 格式化相对时间 */
const formatRelativeTime = (timestamp: number): string => {
  if (!timestamp) return ''
  // 后端返回的是 Unix timestamp（秒）
  const date = dayjs.unix(timestamp)
  return date.fromNow()
}

const scopeLabel = computed(() => {
  if (props.globalMode) {
    return $t('ai.global_assistant')
  }
  return props.itemName ? $t('ai.current_scope', { name: props.itemName }) : $t('ai.project_assistant')
})
</script>

<style scoped lang="scss">
.ai-session-bar {
  height: 42px;
  background: var(--color-bg-secondary);
  color: var(--color-primary);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  flex-shrink: 0;
  position: relative;

  [data-theme='dark'] & {
    background: var(--color-bg-secondary);
    color: var(--color-primary);
  }
}

.session-bar-left {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.scope-label {
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.session-bar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.session-bar-actions i {
  font-size: 14px;
  cursor: pointer;
  transition: opacity 0.15s ease;
  &:hover {
    opacity: 0.7;
  }
}

.session-list-dropdown {
  position: absolute;
  top: 42px;
  left: 0;
  right: 0;
  background: var(--color-bg-primary);
  border: 1px solid var(--color-border);
  border-top: none;
  max-height: 260px;
  overflow-y: auto;
  z-index: 10;
  box-shadow: var(--shadow-sm);
}

.session-list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  font-size: 12px;
  color: var(--color-text-secondary);
  border-bottom: 1px solid var(--color-border);

  i {
    cursor: pointer;
    font-size: 12px;
  }
}

.session-list-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  cursor: pointer;
  transition: background 0.15s;

  &:hover {
    background: var(--color-bg-secondary);
  }

  &.active {
    background: var(--color-bg-secondary);
    font-weight: 500;
  }
}

.session-item-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.session-item-title {
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.session-item-meta {
  font-size: 11px;
  color: var(--color-text-secondary);
  margin-top: 2px;
}

.session-item-time {
  margin-left: 2px;
  color: var(--color-text-secondary);
}

.session-delete {
  font-size: 12px;
  color: var(--color-text-secondary);
  padding: 4px;
  flex-shrink: 0;
  margin-left: 8px;

  &:hover {
    color: #ff4d4f;
  }
}

/* ─── 移动端会话栏 ─── */
.mobile-session-bar {
  height: 48px;
  padding: 0 12px;
  /* safe-area-inset-top 由父级 .mobile-expanded 处理，不再重复 */
}

.mobile-back-btn {
  font-size: 18px;
  cursor: pointer;
  padding: 12px;
  margin-left: -8px;
  color: var(--color-primary);
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mobile-action-btn {
  font-size: 18px;
  cursor: pointer;
  padding: 12px;
  color: var(--color-primary);
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;

  &:active {
    opacity: 0.6;
  }
}

.mobile-session-bar .session-bar-actions {
  gap: 4px;
}

.mobile-session-bar .scope-label {
  font-size: 15px;
}
</style>
