<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		size="normal"
		class="collective-publish-modal"
		@close="onClose">
		<div class="modal-publish">
			<h2 class="modal-publish__name">
				{{ t('collectives', 'Publish website for collective {name}', { name: collective.name }) }}
			</h2>
			<NcButton :href="exportUrl" download @click="onDownload">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('collectives', 'Download collective as zip') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import DownloadIcon from 'vue-material-design-icons/TrayArrowDown.vue'
import { exportCollectiveUrl } from '../../apis/collectives/collectives.js'

export default {
	name: 'CollectivePublishModal',

	components: {
		DownloadIcon,
		NcButton,
		NcModal,
	},

	props: {
		collective: {
			required: true,
			type: Object,
		},
	},

	emits: [
		'close',
	],

	computed: {
		exportUrl() {
			return exportCollectiveUrl(this.collective.id)
		},
	},

	methods: {
		t,

		onClose() {
			this.$emit('close')
		},

		onDownload() {
			this.onClose()
		},
	},
}
</script>

<style lang="scss" scoped>
.collective-publish-modal {
	:deep(.modal-wrapper .modal-container) {
		display: flex !important;
		padding-block: 4px 0;
		padding-inline: 12px;
	}

	:deep(.modal-wrapper .modal-container__content) {
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
}

.modal-publish {
	height: 550px;
	max-height: 80vh;

	&__name {
		font-size: 21px;
		text-align: center;
	}
}

</style>
