<template>
  <AiAgentDialog
    :item-info="itemInfoData"
    :global-mode="!hasItemId"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useItemStore } from '@/store'
import AiAgentDialog from './AiAgentDialog.vue'

/**
 * 全局 AI 聊天按钮入口。
 * - 在项目页面：从 Pinia itemStore 读取 itemInfo，传入 AiAgentDialog
 * - 在非项目页面（首页/用户中心等）：globalMode 运行
 */
const itemStore = useItemStore()

const hasItemId = computed(() => !!itemStore.itemId)

const itemInfoData = computed(() => {
  if (itemStore.itemId) {
    return {
      item_id: Number(itemStore.itemId),
      item_name: itemStore.itemName,
    }
  }
  return null
})
</script>

<style scoped>
/* 样式已移至 AiAgentDialog 及子组件 */
</style>
