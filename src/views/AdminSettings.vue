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
				placeholder="http://host.docker.internal:8080"
				:error="ssgRendererUrlError"
				:helperText="ssgRendererUrlHint"
				@keydown.enter="saveRendererUrl"
				@blur="saveRendererUrl" />
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
}>('collectives', 'adminSettings')

let originalDefaultUserFolder = adminSettings.default_user_folder
const defaultUserFolder = ref(adminSettings.default_user_folder)

let originalRendererUrl = adminSettings.ssg_renderer_url
const ssgRendererUrl = ref(adminSettings.ssg_renderer_url)

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
</script>
