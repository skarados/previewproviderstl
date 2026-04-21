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

	/** @var string */
	private $stlBinary;

	/** @var string */
	private $binary;

	/** @var IClientService */
	private $clientService;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(IClientService $clientService, Capabilities $capabilities, LoggerInterface $logger) {
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->capabilitites = $capabilities->getCapabilities()['previewproviderstl'] ?? [];
		// Use the bundled stl-thumb binary from the vendor directory
		// This ensures the plugin works in Docker containers where /usr/bin/stl-thumb is not available
		$this->stlBinary = dirname(__DIR__, 2) . '/vendor/stl-thumb-bin/stl-thumb';
	}

	// /**
	//  * {@inheritDoc}
	//  */
	// public function getMimeType(): string {
	// 	return '/model\/stl/';
	// }

	public function isAvailable(\OCP\Files\FileInfo $file): bool {
		// Lazily resolve the binary path (allows config override via options)
		if (is_null($this->binary)) {
			if (isset($this->options['stlBinary'])) {
				// Allow config override for custom binary path - validate it's safe
				$customPath = $this->options['stlBinary'];
				// Only allow absolute paths to prevent path traversal
				if (is_string($customPath) && strpos($customPath, '/') === 0) {
					$this->binary = $customPath;
				}
			} elseif (is_string($this->stlBinary)) {
				$this->binary = $this->stlBinary;
			}
		}
		// Also verify the binary actually exists on disk
		return is_string($this->binary) && file_exists($this->binary);
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

		if ($binaryType !== 'stl-thumb') {
			// Unsupported binary type
			unlink($tmpPath);
			return null;
		}

		// Calculate best rotation for the model (only for local files to avoid extra I/O)
		$inputPath = $absPath;
		$rotationAngle = 0;

		if ($this->isLocalFile($absPath)) {
			$orientation = new STLOrientation($this->logger);
			$rotationAngle = $orientation->calculateBestRotation($absPath);

			// Only apply rotation if angle is significant (> 5 degrees)
			if (abs($rotationAngle) > 5) {
				$rotatedPath = $orientation->rotateSTL($absPath, $rotationAngle);
				if ($rotatedPath !== null) {
					$inputPath = $rotatedPath;
					$this->tmpFiles[] = $rotatedPath;
				}
			}
		}

		// Build command: stl-thumb -s [size] [input] [output]
		$cmd = [$this->binary, '-s', $maxX, $inputPath, $tmpPath];

		$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $desc, $pipes);

		if (!is_resource($proc)) {
			$this->logger->warning('PreviewProviderSTL: Failed to start stl-thumb process');
			unlink($tmpPath);
			return null;
		}

		// Set a timeout of 30 seconds to avoid hanging requests
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$start = time();
		$timeout = 30;
		$stdout = '';
		$stderr = '';

		while (!feof($pipes[1]) || !feof($pipes[2])) {
			if ((time() - $start) > $timeout) {
				proc_terminate($proc, SIGKILL);
				$this->logger->warning('PreviewProviderSTL: stl-thumb process timed out');
				proc_close($proc);
				unlink($tmpPath);
				return null;
			}
			$stdout .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			usleep(10000);
		}

		proc_close($proc);

		if (!file_exists($tmpPath)) {
			$this->logger->warning('PreviewProviderSTL: stl-thumb did not produce output', [
				'stdout' => trim($stdout),
				'stderr' => trim($stderr),
			]);
			return null;
		}

		$image = new \OCP\Image();
		$image->loadFromFile($tmpPath);
		unlink($tmpPath);

		if (!$image->valid()) {
			$this->logger->warning('PreviewProviderSTL: Failed to load generated image', [
				'stderr' => trim($stderr),
			]);
			return null;
		}

		$image->scaleDownToFit($maxX, $maxY);
		return $image;
	}

	/**
	 * Check if file is a local file (not encrypted/remote)
	 */
	private function isLocalFile(string $path): bool
	{
		return is_readable($path) && is_file($path);
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
			// Only unlink if file exists to avoid warnings
			if (file_exists($tmpFile)) {
				unlink($tmpFile);
			}
		}

		$this->tmpFiles = [];
	}
}
