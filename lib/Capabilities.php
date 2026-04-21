<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\PreviewProviderSTL;

use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IURLGenerator;

class Capabilities implements ICapability {
	public const MIMETYPES = [
		'model/stl',
		'model/obj',
		'model/3mf',
		// 'application/vnd.oasis.opendocument.spreadsheet',
		// 'application/vnd.oasis.opendocument.graphics',
		// 'application/vnd.oasis.opendocument.presentation',
		// 'application/vnd.oasis.opendocument.text-flat-xml',
		// 'application/vnd.oasis.opendocument.spreadsheet-flat-xml',
		// 'application/vnd.oasis.opendocument.graphics-flat-xml',
		// 'application/vnd.oasis.opendocument.presentation-flat-xml',
		// 'application/vnd.lotus-wordpro',
		// 'application/vnd.visio',
		// 'application/vnd.ms-visio.drawing',
		// 'application/vnd.wordperfect',
		// 'application/rtf',
		// 'text/rtf',
	];

	// public const MIMETYPES_MSOFFICE = [
	// 	'application/msonenote',
	// 	'application/msword',
	// 	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	// 	'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
	// 	'application/vnd.ms-word.document.macroEnabled.12',
	// 	'application/vnd.ms-word.template.macroEnabled.12',
	// 	'application/vnd.ms-excel',
	// 	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	// 	'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
	// 	'application/vnd.ms-excel.sheet.macroEnabled.12',
	// 	'application/vnd.ms-excel.template.macroEnabled.12',
	// 	'application/vnd.ms-excel.addin.macroEnabled.12',
	// 	'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
	// 	'application/vnd.ms-powerpoint',
	// 	'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	// 	'application/vnd.openxmlformats-officedocument.presentationml.template',
	// 	'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
	// 	'application/vnd.ms-powerpoint.addin.macroEnabled.12',
	// 	'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
	// 	'application/vnd.ms-powerpoint.template.macroEnabled.12',
	// 	'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
	// 	'text/csv',
	// ];

	// public const MIMETYPES_OPTIONAL = [
	// 	'image/svg+xml',
	// 	'application/pdf',
	// 	'text/plain',
	// 	'text/spreadsheet',
	// ];

	// public const SECURE_VIEW_ADDITIONAL_MIMES = [
	// 	'image/jpeg',
	// 	'image/svg+xml',
	// 	'image/cgm',
	// 	'image/vnd.dxf',
	// 	'image/x-emf',
	// 	'image/x-wmf',
	// 	'image/x-wpg',
	// 	'image/x-freehand',
	// 	'image/bmp',
	// 	'image/png',
	// 	'image/gif',
	// 	'image/tiff',
	// 	'image/jpg',
	// 	'image/jpeg',
	// 	'text/plain',
	// 	'application/pdf',
	// ];

	private ?array $capabilities = null;

	/** @var IAppManager */
	private $appManager;

	/** @var string|null */
	private $userId;

	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(
		IAppManager $appManager,
		?IURLGenerator $urlGenerator
	) {
		$this->appManager = $appManager;
		$this->urlGenerator = $urlGenerator;
	}

	public function getCapabilities() {
		// Only expose capabilities for users with enabled office or guests (where it depends on the share owner if they have access)
		// if (!$this->permissionManager->isEnabledForUser() && $this->userId !== null) {
		// 	return [];
		// }

		if (!$this->capabilities) {
			$defaultMimetypes = self::MIMETYPES;
			// $optionalMimetypes = self::MIMETYPES_OPTIONAL;

			// if (!$this->appManager->isEnabledForUser('files_pdfviewer')) {
			// 	$defaultMimetypes[] = 'application/pdf';
			// 	// $optionalMimetypes = array_diff($optionalMimetypes, ['application/pdf']);
			// }

			$this->capabilities = [
				'previewproviderstl' => [
					'version' => $this->appManager->getAppVersion('previewproviderstl'),
					'mimetypes' => array_values($defaultMimetypes)
					// 'mimetypesNoDefaultOpen' => array_values($optionalMimetypes),
					// 'mimetypesSecureView' => $this->config->useSecureViewAdditionalMimes() ? self::SECURE_VIEW_ADDITIONAL_MIMES : []
				],
			];
		}
		return $this->capabilities;
	}
}
