<template>
  <div id="app-container" :data-theme="appStore.theme">
    <router-view />
    <AiAgentButton />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useAppStore } from '@/store/app'
import { useAiChanges } from '@/composables/useAiChanges'
import AiAgentButton from '@/components/ai/AiAgentButton.vue'

const appStore = useAppStore()

// 全局监听 AI changes 事件，自动刷新页面内容
useAiChanges()

onMounted(() => {
  // 初始化主题
  appStore.initTheme()
})
</script>

<style lang="scss">
#app-container {
  width: 100%;
  height: 100%;
}
</style>

