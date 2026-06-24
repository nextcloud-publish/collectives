/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { apiUrl } from './urls.js'

/**
 * Render the selected collective pages as a static site with Hugo.
 *
 * @param {number} collectiveId - ID of the collective
 * @param {number[]} pageIds - IDs of the pages to include
 * @param {string} title - Title shown on the generated site
 */
export function generateStaticSite(collectiveId, pageIds, title) {
	return axios.post(apiUrl('v1.0', 'staticsite'), { collectiveId, pageIds, title })
}
