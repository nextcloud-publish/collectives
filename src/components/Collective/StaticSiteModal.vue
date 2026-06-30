<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog
		:name="t('collectives', 'Generate static site')"
		size="normal"
		class="ssg-modal"
		@closing="$emit('close')">
		<div v-if="loading" class="ssg-modal__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<p class="ssg-modal__hint">
				{{ t('collectives', 'Select the pages to include in the static site.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="allSelected"
				:indeterminate="someSelected"
				class="ssg-modal__all"
				@update:modelValue="toggleAll">
				{{ t('collectives', 'Select all') }}
			</NcCheckboxRadioSwitch>

			<ul class="ssg-modal__pages">
				<li v-for="page in pages" :key="page.id">
					<NcCheckboxRadioSwitch
						:modelValue="selected.includes(page.id)"
						@update:modelValue="toggle(page.id, $event)">
						{{ pageLabel(page) }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
		</template>

		<template #actions>
			<NcButton
				variant="primary"
				:disabled="generating || selected.length === 0"
				@click="onGenerate">
				<template #icon>
					<NcLoadingIcon v-if="generating" :size="20" />
					<WebIcon v-else :size="20" />
				</template>
				{{ generating
					? t('collectives', 'Generating …')
					: n('collectives', 'Generate site ({count} page)', 'Generate site ({count} pages)', selected.length, { count: selected.length }) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import WebIcon from 'vue-material-design-icons/Web.vue'
import { generateStaticSite, getPages } from '../../apis/collectives/index.js'

export default {
	name: 'StaticSiteModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		WebIcon,
	},

	props: {
		collective: {
			type: Object,
			required: true,
		},
	},

	emits: [
		'close',
	],

	data() {
		return {
			loading: true,
			generating: false,
			pages: [],
			selected: [],
		}
	},

	computed: {
		allSelected() {
			return this.pages.length > 0 && this.selected.length === this.pages.length
		},

		someSelected() {
			return this.selected.length > 0 && this.selected.length < this.pages.length
		},
	},

	async mounted() {
		try {
			const response = await getPages({ collectiveId: this.collective.id })
			this.pages = (response.data.ocs.data.pages || [])
				.slice()
				.sort((a, b) => (a.filePath || '').localeCompare(b.filePath || ''))
			// Preselect everything - the common case is "export the whole collective".
			this.selected = this.pages.map((page) => page.id)
		} catch (e) {
			console.error('Failed to load pages for static site', e)
			showError(t('collectives', 'Could not load pages'))
			this.$emit('close')
		} finally {
			this.loading = false
		}
	},

	methods: {
		n,
		t,

		pageLabel(page) {
			const title = page.title || t('collectives', 'Start page')
			return page.emoji ? `${page.emoji} ${title}` : title
		},

		toggle(pageId, checked) {
			if (checked) {
				if (!this.selected.includes(pageId)) {
					this.selected.push(pageId)
				}
			} else {
				this.selected = this.selected.filter((id) => id !== pageId)
			}
		},

		toggleAll(checked) {
			this.selected = checked ? this.pages.map((page) => page.id) : []
		},

		async onGenerate() {
			this.generating = true
			try {
				const response = await generateStaticSite(this.collective.id, this.selected, this.collective.name)
				const { path, pages } = response.data.ocs.data
				showSuccess(n('collectives',
					'Static site with %n page generated and saved to {path}',
					'Static site with %n pages generated and saved to {path}',
					pages,
					{ path }))
				this.$emit('close')
			} catch (e) {
				console.error('Failed to generate static site', e)
				let errorMessage = ''
				if (e.response?.data?.ocs?.meta?.message) {
					errorMessage = e.response.data.ocs.meta.message
				}
				showError(t('collectives', 'Could not generate static site. {errorMessage}', { errorMessage }))
			} finally {
				this.generating = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.ssg-modal__loading {
	display: flex;
	justify-content: center;
	padding: 2rem 0;
}

.ssg-modal__hint {
	margin-block-end: 8px;
	color: var(--color-text-maxcontrast);
}

.ssg-modal__all {
	padding-block-end: 4px;
	border-block-end: 1px solid var(--color-border);
	margin-block-end: 4px;
}

.ssg-modal__pages {
	display: flex;
	flex-direction: column;
	max-height: 50vh;
	overflow-y: auto;
}
</style>
