<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Alexander A. Klimov <grandmaster@al2klimov.de>
 * @author Daniel Schneider <daniel@schneidoa.de>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Olivier Paroz <github@oparoz.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\PreviewProviderSTL\Preview;

use OCA\PreviewProviderSTL\Capabilities;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use OCP\Preview\IProviderV2;

abstract class PreviewProvider implements IProviderV2 {
	private array $capabilities = [];

	/** @var array */
	protected $tmpFiles = [];

	/**
	 * @deprecated 23.0.0 pass option to \OCP\Preview\ProviderV2
	 * @var string
	 */
	public static $stlBinary = "vendor/usr/bin/stl-thumb";
	// $this->logger->debug("Using binary: " . self::$stlBinary);

	/** @var string */
	private $binary;

	public function __construct(private IClientService $clientService, Capabilities $capabilities, private LoggerInterface $logger) {
		$this->capabilitites = $capabilities->getCapabilities()['previewproviderstl'] ?? [];
	}

	// /**
	//  * {@inheritDoc}
	//  */
	// public function getMimeType(): string {
	// 	return '/model\/stl/';
	// }

	public function isAvailable(\OCP\Files\FileInfo $file): bool {
		// return true;
		// TODO: remove when avconv is dropped
		if (is_null($this->binary)) {
			if (isset($this->options['stlBinary'])) {
				$this->binary = $this->options['stlBinary'];
			} elseif (is_string(self::$stlBinary)) {
				// var_dump(self::$stlBinary);
				$this->binary = self::$stlBinary;
			}
		}
		return is_string($this->binary);
	}

	// /**
	//  * Check if a preview can be generated for $path
	//  *
	//  * @param FileInfo $file
	//  * @return bool
	//  */
	// public function isAvailable(FileInfo $file): bool {
	// 	return true;
	// }

	/**
	 * {@inheritDoc}
	 */
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		// TODO: use proc_open() and stream the source file ?

		if (!$this->isAvailable($file)) {
			return null;
		}

		$result = null;
		if ($this->useTempFile($file)) {
			// try downloading 5 MB first as it's likely that the first frames are present there
			// in some cases this doesn't work for example when the moov atom is at the
			// end of the file, so if it fails we fall back to getting the full file
			$sizeAttempts = [5242880, null];
		} else {
			// size is irrelevant, only attempt once
			$sizeAttempts = [null];
		}

		foreach ($sizeAttempts as $size) {
			$absPath = $this->getLocalFile($file, $size);

			$result = null;
			if (is_string($absPath)) {
				$result = $this->generateThumbNail($maxX, $maxY, $absPath);
			}

			$this->cleanTmpFiles();

			if ($result !== null) {
				break;
			}
		}

		return $result;
	}

	private function generateThumbNail(int $maxX, int $maxY, string $absPath): ?IImage {
		$tmpPath = \OC::$server->getTempManager()->getTemporaryFile();

		$binaryType = substr(strrchr($this->binary, '/'), 1);

		if ($binaryType === 'stl-thumb') {
			$cmd = [$this->binary, '-s', $maxX, $absPath, $tmpPath];
		} else {
			// Not supported
			unlink($tmpPath);
			return null;
		}

		$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$returnCode = -1;
		$output = "";
		if (is_resource($proc)) {
			$stdout = trim(stream_get_contents($pipes[1]));
			$stderr = trim(stream_get_contents($pipes[2]));
			$returnCode = proc_close($proc);
			$output = $stdout . $stderr;
			// var_dump($output);
		}

		if ($returnCode === 0) {
			$image = new \OCP\Image();
			$image->loadFromFile($tmpPath);
			if ($image->valid()) {
				unlink($tmpPath);
				$image->scaleDownToFit($maxX, $maxY);

				return $image;
			}
		}

		unlink($tmpPath);
		return null;
	}

	protected function useTempFile(File $file): bool {
		return $file->isEncrypted() || !$file->getStorage()->isLocal();
	}

	/**
	 * Get a path to either the local file or temporary file
	 *
	 * @param File $file
	 * @param int $maxSize maximum size for temporary files
	 * @return string|false
	 */
	protected function getLocalFile(File $file, ?int $maxSize = null) {
		if ($this->useTempFile($file)) {
			$absPath = \OC::$server->getTempManager()->getTemporaryFile();

			$content = $file->fopen('r');

			if ($maxSize) {
				$content = stream_get_contents($content, $maxSize);
			}

			file_put_contents($absPath, $content);
			$this->tmpFiles[] = $absPath;
			return $absPath;
		} else {
			$path = $file->getStorage()->getLocalFile($file->getInternalPath());
			if (is_string($path)) {
				return $path;
			} else {
				return false;
			}
		}
	}

	/**
	 * Clean any generated temporary files
	 */
	protected function cleanTmpFiles(): void {
		foreach ($this->tmpFiles as $tmpFile) {
			unlink($tmpFile);
		}

		$this->tmpFiles = [];
	}
}
