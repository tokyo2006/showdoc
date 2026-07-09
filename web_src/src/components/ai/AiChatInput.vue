<template>
  <div class="ai-chat-input" :class="{ 'input-expanded': expanded }">
    <div class="input-wrapper" ref="inputWrapperRef">
      <a-textarea
        ref="textareaRef"
        v-model:value="inputText"
        :placeholder="$t('ai.ai_input_placeholder') || '输入消息...'"
        :auto-size="expanded ? false : { minRows: 2, maxRows: 5 }"
        :disabled="disabled"
        @keydown="handleKeydown"
      />
    </div>
    <div class="input-actions">
      <!-- 正在生成时显示停止按钮 -->
      <CommonButton
        v-if="isLoading"
        theme="light"
        @click="$emit('stop')"
      >
        <i class="fas fa-stop" style="margin-right: 4px"></i>
        停止
      </CommonButton>
      <!-- 正常发送按钮 -->
      <CommonButton
        v-else
        theme="dark"
        :disabled="!inputText.trim() || disabled"
        @click="handleSend"
      >
        <i class="fas fa-paper-plane" style="margin-right: 4px"></i>
        发送
      </CommonButton>
      <!-- 展开/收起按钮 -->
      <a-tooltip placement="top" :title="expanded ? $t('ai.collapse_input') : $t('ai.expand_input')">
        <button class="expand-toggle-btn" :class="{ active: expanded }" @click="$emit('toggle-expand')">
          <i :class="expanded ? 'fas fa-compress-alt' : 'fas fa-expand-alt'"></i>
        </button>
      </a-tooltip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick, watch } from 'vue'
import CommonButton from '@/components/CommonButton.vue'

const props = defineProps<{
  isLoading: boolean
  disabled?: boolean
  isMobile?: boolean
  expanded?: boolean
}>()

const emit = defineEmits<{
  send: [message: string]
  stop: []
  'toggle-expand': []
}>()

const inputText = ref('')
const textareaRef = ref<any>(null)
const inputWrapperRef = ref<HTMLElement | null>(null)

const handleSend = () => {
  const text = inputText.value.trim()
  if (!text) return
  emit('send', text)
  inputText.value = ''
  nextTick(() => {
    textareaRef.value?.focus()
  })
}

/** 展开模式下清除 Ant Design auto-size 注入的 inline style */
const clearTextareaInlineStyles = () => {
  nextTick(() => {
    const textarea = textareaRef.value?.$el ?? textareaRef.value
    if (textarea instanceof HTMLTextAreaElement) {
      textarea.style.removeProperty('height')
      textarea.style.removeProperty('min-height')
      textarea.style.removeProperty('max-height')
      textarea.style.removeProperty('overflow-y')
    }
  })
}

watch(() => props.expanded, (val) => {
  if (val) clearTextareaInlineStyles()
})

// 持续清除：展开期间每隔一段时间清除 Ant Design 可能重新注入的 inline style
watch(() => props.expanded, (val) => {
  if (val) {
    clearTextareaInlineStyles()
    const interval = setInterval(() => {
      if (!props.expanded) { clearInterval(interval); return }
      const textarea = textareaRef.value?.$el ?? textareaRef.value
      if (textarea instanceof HTMLTextAreaElement && textarea.style.minHeight) {
        textarea.style.removeProperty('height')
        textarea.style.removeProperty('min-height')
        textarea.style.removeProperty('max-height')
        textarea.style.removeProperty('overflow-y')
      }
    }, 100)
    // 3秒后停止轮询（此时 auto-size 不会再重新注入）
    setTimeout(() => clearInterval(interval), 3000)
  }
})

const handleKeydown = (e: KeyboardEvent) => {
  // Enter 发送（Shift+Enter 换行）
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    handleSend()
  }
}

/** 外部可调用聚焦 */
const focus = () => {
  textareaRef.value?.focus()
}

defineExpose({ focus })
</script>

<style scoped lang="scss">
.ai-chat-input {
  padding: 12px;
  background: var(--color-bg-primary);
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;
  transition: all 0.3s ease;

  [data-theme='dark'] & {
    background: var(--color-bg-primary);
    border-top-color: var(--color-border);
  }

  /* 展开模式：铺满可用空间 */
  &.input-expanded {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
}

.input-wrapper {
  transition: flex 0.3s ease;

  & :deep(.ant-input) {
    font-size: 13px;
    line-height: 1.5;
    padding: 8px 12px;
    border-radius: 8px;
    resize: none;
    transition: height 0.3s ease;
  }
}

/* 展开模式下 input-wrapper 占满空间 */
.input-expanded .input-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;

  /* 穿透 Ant Design textarea wrapper */
  & :deep(.ant-input-textarea) {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  & :deep(.ant-input) {
    flex: 1;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow-y: auto !important;
    resize: none;
  }
}

.input-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;


}

/* 展开/收起按钮 */
.expand-toggle-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: transparent;
  color: var(--color-text-secondary);
  cursor: pointer;
  transition: all 0.2s ease;
  flex-shrink: 0;

  i {
    font-size: 14px;
  }

  &:hover {
    color: var(--color-active);
    border-color: var(--color-active);
    background: var(--color-bg-secondary);
  }

  &.active {
    color: var(--color-active);
    border-color: var(--color-active);
  }
}

/* 移动端输入区域适配 */
@media (max-width: 768px) {
  .ai-chat-input {
    padding: 8px 12px;
    /* safe-area-inset-bottom 由父级 .mobile-expanded 处理 */
  }

  .input-actions {
    margin-top: 6px;
  }

  /* 发送/停止按钮触摸区 ≥ 44px */
  .input-actions :deep(.common-button) {
    min-height: 44px;
    min-width: 44px;
  }

  /* 展开按钮触摸区 */
  .expand-toggle-btn {
    min-width: 44px;
    min-height: 44px;
  }
}
</style>
