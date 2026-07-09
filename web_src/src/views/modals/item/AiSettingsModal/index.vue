<template>
  <div class="ai-settings-modal">
    <CommonModal
      :class="{ show }"
      :title="$t('item.ai_assistant_setting')"
      :icon="['fas', 'fa-wand-magic-sparkles']"
      width="600px"
      @close="handleClose"
    >
      <div class="modal-content">
        <!-- 加载中 -->
        <div v-if="loading" class="loading-wrapper">
          <a-spin />
        </div>

        <template v-else>
          <!-- AI 未配置提示 -->
          <div v-if="aiNotConfigured" class="ai-not-configured">
            <a-alert
              type="warning"
              :closable="false"
              show-icon
              :message="$t('itemSetting.aiNotConfigured')"
            />
          </div>

          <!-- 说明 -->
          <div v-if="!aiNotConfigured" class="ai-settings-tip">
            {{ $t('itemSetting.aiSettingsTip') }}
          </div>

          <!-- 游客设置区块 -->
          <div v-if="!aiNotConfigured" class="guest-settings-block">
            <div class="block-title">{{ $t('itemSetting.guestSettingsTitle') }}</div>
            <div class="form-desc" style="margin-bottom: 16px;">{{ $t('itemSetting.guestSettingsDesc') }}</div>

            <!-- 游客 AI 开关 -->
            <div class="form-row">
              <label class="form-label">{{ $t('itemSetting.aiEnabled') }}</label>
              <div class="form-control">
                <CommonSwitch v-model="form.guest_enabled" />
                <div class="form-desc">
                  {{ $t('itemSetting.aiEnabledDesc') }}
                </div>

              </div>
            </div>

            <!-- 默认展开对话框 -->
            <div class="form-row">
              <label class="form-label">{{ $t('itemSetting.aiDialogExpanded') }}</label>
              <div class="form-control">
                <CommonSwitch v-model="form.dialog_expanded" />
                <div class="form-desc">
                  {{ $t('itemSetting.aiDialogExpandedDesc') }}
                </div>
              </div>
            </div>


          </div>

          <!-- 对话设置区块 -->
          <div v-if="!aiNotConfigured" class="dialog-settings-block">
            <!-- 欢迎语 -->
            <div class="form-row">
              <label class="form-label">{{ $t('itemSetting.aiWelcomeMessage') }}</label>
              <div class="form-control">
                <CommonInput
                  v-model="form.welcome_message"
                  :placeholder="$t('itemSetting.aiWelcomeMessagePlaceholder')"
                />
                <div class="form-desc">
                  {{ $t('itemSetting.aiWelcomeMessageDesc') }}
                </div>
              </div>
            </div>

            <!-- System Prompt -->
            <div class="form-row">
              <label class="form-label">{{ $t('itemSetting.aiSystemPrompt') }}</label>
              <div class="form-control">
                <CommonTextarea
                  v-model="form.system_prompt"
                  :placeholder="$t('itemSetting.aiSystemPromptPlaceholder')"
                  :rows="5"
                />
                <div class="form-desc">
                  {{ $t('itemSetting.aiSystemPromptDesc') }}
                </div>
              </div>
            </div>
          </div>


        </template>
      </div>
      <template #footer>
        <div class="modal-footer">
          <CommonButton
            theme="light"
            :text="$t('common.cancel')"
            @click="handleClose(false)"
          />
          <CommonButton
            theme="dark"
            :text="saving ? $t('common.submiting') : $t('common.save')"
            :disabled="saving"
            @click="handleSave"
          />
        </div>
      </template>
    </CommonModal>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import CommonModal from '@/components/CommonModal.vue'
import CommonInput from '@/components/CommonInput.vue'
import CommonTextarea from '@/components/CommonTextarea.vue'
import CommonButton from '@/components/CommonButton.vue'
import CommonSwitch from '@/components/CommonSwitch.vue'
import { getItemDetail, getItemAiConfig, saveItemAiConfig } from '@/models/item'
import Message from '@/components/Message'

const { t } = useI18n()

interface Props {
  item_id: string | number
  onClose: (result: boolean) => void
}

const props = defineProps<Props>()

const show = ref(false)
const loading = ref(true)
const saving = ref(false)
const aiNotConfigured = ref(false)

const form = reactive({
  guest_enabled: false,
  dialog_expanded: false,
  welcome_message: '',
  system_prompt: '',
})

// 加载配置
const loadConfig = async () => {
  try {
    // 加载项目详情（包含 ai_config）
    const detailRes: any = await getItemDetail(String(props.item_id))
    if (detailRes?.data?.ai_config) {
      const config = detailRes.data.ai_config
      form.guest_enabled = config.guest_enabled === 1 || config.guest_enabled === true
      form.dialog_expanded = config.dialog_collapsed === 0
      form.welcome_message = config.welcome_message || ''
      form.system_prompt = config.system_prompt || ''
    }

    // 检查 AI 服务是否可用
    const aiRes: any = await getItemAiConfig(props.item_id)
    if (aiRes?.data) {
      if (!aiRes.data.enabled && aiRes.data.message?.includes('未配置')) {
        aiNotConfigured.value = true
      }
    }
  } catch (error) {
    console.error('Load AI config failed:', error)
  } finally {
    loading.value = false
  }
}

// 保存
const handleSave = async () => {
  try {
    saving.value = true
    const res = await saveItemAiConfig({
      item_id: props.item_id,
      guest_enabled: form.guest_enabled ? 1 : 0,
      dialog_collapsed: form.dialog_expanded ? 0 : 1,
      welcome_message: form.welcome_message,
      system_prompt: form.system_prompt,
    })
    if (res && res.error_code === 0) {
      Message.success(t('itemSetting.aiSaveSuccess'))
      handleClose(true)
    } else {
      Message.error(t('itemSetting.aiSaveFailed'))
    }
  } catch (error) {
    console.error('Save AI config failed:', error)
    Message.error(t('itemSetting.aiSaveFailed'))
  } finally {
    saving.value = false
  }
}

const handleClose = (result: boolean = false) => {
  show.value = false
  setTimeout(() => {
    props.onClose(result)
  }, 300)
}

onMounted(async () => {
  setTimeout(() => {
    show.value = true
  })
  await loadConfig()
})
</script>

<style scoped lang="scss">
.modal-content {
  padding: 0 24px;
}

.loading-wrapper {
  display: flex;
  justify-content: center;
  padding: 60px 0;
}

.ai-not-configured {
  margin-top: 16px;
}

.ai-settings-tip {
  font-size: var(--font-size-s);
  color: var(--color-text-secondary);
  line-height: 1.5;
  margin-bottom: 16px;
}

// 游客设置区块
.guest-settings-block {
  background: var(--color-bg-secondary);
  border-radius: 6px;
  padding: 16px 20px;
  border: 1px solid var(--color-border);
  margin-bottom: 16px;

  .block-title {
    font-size: var(--font-size-m);
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--color-border);
  }
}

// 对话设置区块
.dialog-settings-block {
  margin-bottom: 8px;
}

// 统一表单行布局：左标签 + 右控件
.form-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 16px;

  &:last-child {
    margin-bottom: 0;
  }

  .form-label {
    flex-shrink: 0;
    width: 140px;
    font-size: var(--font-size-m);
    font-weight: 500;
    color: var(--color-text-primary);
    line-height: 32px;
    padding-top: 4px;
  }

  .form-control {
    flex: 1;
    min-width: 0;
  }
}

// 描述文字
.form-desc {
  font-size: var(--font-size-s);
  color: var(--color-text-secondary);
  margin-top: 4px;
  line-height: 1.5;

  &.form-desc-warning {
    color: var(--color-warning-text, var(--color-orange));
  }
}

// 取消对话扣费提醒
.cancel-billing-reminder {
  font-size: var(--font-size-s);
  color: var(--color-text-secondary);
  padding: 8px 12px;
  background: var(--color-bg-secondary);
  border-radius: 4px;
  line-height: 1.5;
  margin-top: 8px;

  i {
    margin-right: 6px;
    opacity: 0.6;
  }
}

// 底部按钮（使用 CommonModal 的 footer 插槽）
.modal-footer {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  width: 100%;
  padding: 16px 24px 0;
}
</style>
