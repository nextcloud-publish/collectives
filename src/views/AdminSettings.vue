<!--
  * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  * SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div>
		<NcSettingsSection
			:name="t('collectives', 'Collectives default user folder')"
			:description="t('collectives', 'The default path where collectives are mounted in user home directory')">
			<NcTextField
				id="defaultUserFolder"
				v-model="defaultUserFolder"
				name="defaultUserFolder"
				:label="t('collectives', 'Default user folder')"
				:error="defaultUserFolderError"
				:helperText="defaultUserFolderHint"
				@keydown.enter="saveDefaultUserFolder"
				@blur="saveDefaultUserFolder" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('collectives', 'Static site renderer')"
			:description="t('collectives', 'External service that renders static sites from collectives. Run the container from the app\'s ssg/ directory and enter its URL below. When unset, the &quot;Generate static site&quot; feature is disabled.')">
			<NcTextField
				id="ssgRendererUrl"
				v-model="ssgRendererUrl"
				name="ssgRendererUrl"
				type="url"
				:label="t('collectives', 'Renderer service URL')"
				placeholder="http://collectives-ssg-renderer:8080"
				:error="ssgRendererUrlError"
				:helperText="ssgRendererUrlHint"
				@keydown.enter="saveRendererUrl"
				@blur="saveRendererUrl" />

			<NcTextField
				id="ssgRendererSecret"
				v-model="ssgRendererSecret"
				name="ssgRendererSecret"
				type="password"
				class="ssg-secret"
				:label="t('collectives', 'Shared secret')"
				:placeholder="secretPlaceholder"
				:helperText="t('collectives', 'Must match COLLECTIVES_SSG_SECRET of the renderer container. Leave empty to keep the current value.')"
				@keydown.enter="saveRendererSecret"
				@blur="saveRendererSecret" />
		</NcSettingsSection>
	</div>
</template>

<script setup lang="ts">
import { showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const adminSettings = loadState<{
	default_user_folder: string
	ssg_renderer_url: string
	ssg_renderer_secret_set: boolean
}>('collectives', 'adminSettings')

let originalDefaultUserFolder = adminSettings.default_user_folder
const defaultUserFolder = ref(adminSettings.default_user_folder)

let originalRendererUrl = adminSettings.ssg_renderer_url
const ssgRendererUrl = ref(adminSettings.ssg_renderer_url)
const ssgRendererSecret = ref('')
const secretSet = ref(adminSettings.ssg_renderer_secret_set)

const defaultUserFolderError = computed(() => {
	return defaultUserFolder.value !== ''
		&& !/^\/[a-zA-Z0-9-_./]+$/.test(defaultUserFolder.value)
})

const defaultUserFolderHint = computed(() => {
	return defaultUserFolderError.value
		? t('collectives', 'Empty string or path starting with "/" is expected')
		: ''
})

const ssgRendererUrlError = computed(() => {
	return ssgRendererUrl.value !== '' && !/^https?:\/\/.+/.test(ssgRendererUrl.value)
})

const ssgRendererUrlHint = computed(() => {
	return ssgRendererUrlError.value
		? t('collectives', 'A URL starting with "http://" or "https://" is expected')
		: ''
})

const secretPlaceholder = computed(() => {
	return secretSet.value
		? t('collectives', 'A secret is set (hidden)')
		: t('collectives', 'No secret set')
})

/**
 * Saves the default_user_folder setting to the server
 */
async function saveDefaultUserFolder() {
	if (defaultUserFolderError.value || originalDefaultUserFolder === defaultUserFolder.value) {
		return
	}
	globalThis.OCP.AppConfig.setValue('collectives', 'default_user_folder', defaultUserFolder.value, {
		success() {
			originalDefaultUserFolder = defaultUserFolder.value
			showSuccess(t('collectives', 'Saved default user folder'))
		},
	})
}

/**
 * Saves the static site renderer service URL to the server
 */
async function saveRendererUrl() {
	if (ssgRendererUrlError.value || originalRendererUrl === ssgRendererUrl.value) {
		return
	}
	globalThis.OCP.AppConfig.setValue('collectives', 'ssg_renderer_url', ssgRendererUrl.value, {
		success() {
			originalRendererUrl = ssgRendererUrl.value
			showSuccess(t('collectives', 'Saved static site renderer URL'))
		},
	})
}

/**
 * Saves the static site renderer shared secret to the server
 */
async function saveRendererSecret() {
	if (ssgRendererSecret.value === '') {
		return
	}
	globalThis.OCP.AppConfig.setValue('collectives', 'ssg_renderer_secret', ssgRendererSecret.value, {
		success() {
			secretSet.value = true
			ssgRendererSecret.value = ''
			showSuccess(t('collectives', 'Saved static site renderer secret'))
		},
	})
}
</script>

<style lang="scss" scoped>
.ssg-secret {
	margin-block-start: 12px;
}
</style>
