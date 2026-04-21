<?php

declare(strict_types=1);

namespace OCA\PreviewProviderSTL\Preview;

use Psr\Log\LoggerInterface;

class STLOrientation
{
	/** @var LoggerInterface */
	private $logger;

	public function __construct(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}

	/**
	 * Analyze STL geometry and determine if rotation is needed
	 *
	 * Returns rotation angle in degrees around X axis (positive = tilt forward)
	 * Common values: 0 = default, -35.264 = isometric, -45 = better view
	 */
	public function calculateBestRotation(string $stlFilePath): float
	{
		try {
			$boundingBox = $this->getBoundingBox($stlFilePath);

			$width = $boundingBox['maxX'] - $boundingBox['minX'];
			$height = $boundingBox['maxY'] - $boundingBox['minY'];
			$depth = $boundingBox['maxZ'] - $boundingBox['minZ'];

			$maxDim = max($width, $height, $depth);
			$minDim = min($width, $height, $depth);

			// Check if model is flat (one dimension much smaller than others)
			$flatnessRatio = $maxDim / $minDim;

			if ($flatnessRatio > 10) {
				$this->logger->debug('STL orientation: very flat model detected', [
					'flatness' => $flatnessRatio,
					'rotation' => -90,
				]);
				return -90;
			}

			if ($flatnessRatio > 5) {
				$this->logger->debug('STL orientation: moderately flat model detected', [
					'flatness' => $flatnessRatio,
					'rotation' => -45,
				]);
				return -45;
			}

			$this->logger->debug('STL orientation: normal 3D model detected', [
				'flatness' => $flatnessRatio,
				'rotation' => -35.264,
			]);
			return -35.264;

		} catch (\Throwable $e) {
			$this->logger->warning('Failed to analyze STL orientation', [
				'exception' => $e->getMessage(),
			]);
			return 0;
		}
	}

	/**
	 * Rotate STL file around X axis by given angle
	 * Returns path to rotated file, or null on failure
	 */
	public function rotateSTL(string $inputPath, float $angleDegrees): ?string
	{
		$isBinary = $this->detectSTLFormat($inputPath);

		try {
			if ($isBinary) {
				return $this->rotateBinarySTL($inputPath, $angleDegrees);
			} else {
				return $this->rotateAsciiSTL($inputPath, $angleDegrees);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to rotate STL', [
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Detect if STL is binary or ASCII
	 */
	private function detectSTLFormat(string $filePath): bool
	{
		$handle = fopen($filePath, 'rb');
		if ($handle === false) {
			return true; // Default to binary on error
		}

		$header = fread($handle, 80);
		fclose($handle);

		// If header starts with "solid", likely ASCII
		if (strpos(trim($header), 'solid') === 0) {
			// Check if it looks like ASCII (has 'vertex' keywords)
			$content = file_get_contents($filePath, false, null, 0, 1000);
			return strpos($content, 'vertex') === false;
		}

		return true;
	}

	/**
	 * Rotate ASCII STL file
	 */
	private function rotateAsciiSTL(string $inputPath, float $angleDegrees): ?string
	{
		$content = file_get_contents($inputPath);
		if ($content === false) {
			return null;
		}

		$angleRad = deg2rad($angleDegrees);
		$cosA = cos($angleRad);
		$sinA = sin($angleRad);

		$output = "solid rotated\n";
		$lines = explode("\n", $content);

		$inFacet = false;
		$vertices = [];

		foreach ($lines as $line) {
			$line = trim($line);
			$lowerLine = strtolower($line);

			if (strpos($lowerLine, 'facet') === 0) {
				$inFacet = true;
				$vertices = [];
				$output .= $line . "\n";
			} elseif (strpos($lowerLine, 'endfacet') === 0) {
				$inFacet = false;
				$output .= $line . "\n";
			} elseif ($inFacet && strpos($lowerLine, 'vertex') === 0) {
				$parts = preg_split('/\s+/', $line);
				if (count($parts) >= 4) {
					$x = (float) $parts[1];
					$y = (float) $parts[2];
					$z = (float) $parts[3];

					// Rotate around X axis: y' = y*cos - z*sin, z' = y*sin + z*cos
					$newY = $y * $cosA - $z * $sinA;
					$newZ = $y * $sinA + $z * $cosA;

					$output .= "  vertex $x $newY $newZ\n";
				}
			} else {
				$output .= $line . "\n";
			}
		}

		$tempFile = $this->createTempFile('stl');
		if (file_put_contents($tempFile, $output) === false) {
			return null;
		}

		return $tempFile;
	}

	/**
	 * Rotate binary STL file
	 */
	private function rotateBinarySTL(string $inputPath, float $angleDegrees): ?string
	{
		$handle = fopen($inputPath, 'rb');
		if ($handle === false) {
			return null;
		}

		// Read header
		$header = fread($handle, 80);

		// Read triangle count
		$triangleData = fread($handle, 4);
		$triangleCount = unpack('V', $triangleData)[1];

		$angleRad = deg2rad($angleDegrees);
		$cosA = cos($angleRad);
		$sinA = sin($angleRad);

		$tempFile = $this->createTempFile('stl');
		$outputHandle = fopen($tempFile, 'wb');
		if ($outputHandle === false) {
			fclose($handle);
			return null;
		}

		// Write header (keep original)
		fwrite($outputHandle, str_pad($header, 80, "\0"));

		// Write triangle count
		fwrite($outputHandle, pack('V', $triangleCount));

		// Process each triangle
		for ($i = 0; $i < $triangleCount; $i++) {
			$data = fread($handle, 50);
			if ($data === false || strlen($data) < 50) {
				break;
			}

			$triangles = unpack('f12', $data);

			// Normal vector (skip - won't be valid after rotation, but required by format)
			$nx = $triangles[1];
			$ny = $triangles[2];
			$nz = $triangles[3];

			// Rotate normal
			$newNy = $ny * $cosA - $nz * $sinA;
			$newNz = $ny * $sinA + $nz * $cosA;

			// Rotate vertices
			$vertexData = '';
			for ($v = 0; $v < 3; $v++) {
				$x = $triangles[$v * 3 + 4];
				$y = $triangles[$v * 3 + 5];
				$z = $triangles[$v * 3 + 6];

				$newY = $y * $cosA - $z * $sinA;
				$newZ = $y * $sinA + $z * $cosA;

				$vertexData .= pack('f', $x);
				$vertexData .= pack('f', $newY);
				$vertexData .= pack('f', $newZ);
			}

			// Write: normal + 3 vertices + attribute byte count
			fwrite($outputHandle, pack('f', $nx));
			fwrite($outputHandle, pack('f', $newNy));
			fwrite($outputHandle, pack('f', $newNz));
			fwrite($outputHandle, $vertexData);
			fwrite($outputHandle, "\0\0"); // Attribute byte count
		}

		fclose($handle);
		fclose($outputHandle);

		return $tempFile;
	}

	/**
	 * Create temporary file with given extension
	 */
	private function createTempFile(string $extension): string
	{
		$tempDir = sys_get_temp_dir();
		$tempFile = $tempDir . '/stl_rotation_' . uniqid() . '.' . $extension;
		return $tempFile;
	}

	/**
	 * Parse STL file and get bounding box
	 */
	private function getBoundingBox(string $filePath): array
	{
		$isBinary = $this->detectSTLFormat($filePath);

		if ($isBinary) {
			return $this->parseBinaryBoundingBox($filePath);
		} else {
			return $this->parseAsciiBoundingBox($filePath);
		}
	}

	/**
	 * Parse ASCII STL bounding box
	 */
	private function parseAsciiBoundingBox(string $filePath): array
	{
		$handle = fopen($filePath, 'r');
		if ($handle === false) {
			return $this->defaultBoundingBox();
		}

		$minX = $minY = $minZ = PHP_FLOAT_MAX;
		$maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

		while (($line = fgets($handle)) !== false) {
			$line = strtolower(trim($line));
			if (strpos($line, 'vertex') === 0) {
				$parts = preg_split('/\s+/', $line);
				if (count($parts) >= 4) {
					$x = (float) $parts[1];
					$y = (float) $parts[2];
					$z = (float) $parts[3];

					$minX = min($minX, $x);
					$maxX = max($maxX, $x);
					$minY = min($minY, $y);
					$maxY = max($maxY, $y);
					$minZ = min($minZ, $z);
					$maxZ = max($maxZ, $z);
				}
			}
		}

		fclose($handle);
		return $this->normalizeBoundingBox($minX, $maxX, $minY, $maxY, $minZ, $maxZ);
	}

	/**
	 * Parse binary STL bounding box
	 */
	private function parseBinaryBoundingBox(string $filePath): array
	{
		$handle = fopen($filePath, 'rb');
		if ($handle === false) {
			return $this->defaultBoundingBox();
		}

		fseek($handle, 80);
		$triangleData = fread($handle, 4);
		$triangleCount = unpack('V', $triangleData)[1];

		$minX = $minY = $minZ = PHP_FLOAT_MAX;
		$maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

		for ($i = 0; $i < $triangleCount; $i++) {
			$data = fread($handle, 50);
			if ($data === false || strlen($data) < 50) {
				break;
			}

			$vertices = unpack('f12', substr($data, 12));
			if ($vertices === false) {
				break;
			}

			for ($v = 0; $v < 3; $v++) {
				$x = $vertices[$v * 3 + 1] ?? 0;
				$y = $vertices[$v * 3 + 2] ?? 0;
				$z = $vertices[$v * 3 + 3] ?? 0;

				$minX = min($minX, $x);
				$maxX = max($maxX, $x);
				$minY = min($minY, $y);
				$maxY = max($maxY, $y);
				$minZ = min($minZ, $z);
				$maxZ = max($maxZ, $z);
			}
		}

		fclose($handle);
		return $this->normalizeBoundingBox($minX, $maxX, $minY, $maxY, $minZ, $maxZ);
	}

	/**
	 * Default bounding box for invalid files
	 */
	private function defaultBoundingBox(): array
	{
		return [
			'minX' => 0, 'maxX' => 1,
			'minY' => 0, 'maxY' => 1,
			'minZ' => 0, 'maxZ' => 1,
		];
	}

	/**
	 * Normalize bounding box
	 */
	private function normalizeBoundingBox(float $minX, float $maxX, float $minY, float $maxY, float $minZ, float $maxZ): array
	{
		if ($minX === PHP_FLOAT_MAX) {
			return $this->defaultBoundingBox();
		}

		$xSize = max($maxX - $minX, 0.001);
		$ySize = max($maxY - $minY, 0.001);
		$zSize = max($maxZ - $minZ, 0.001);

		return [
			'minX' => $minX,
			'maxX' => $minX + $xSize,
			'minY' => $minY,
			'maxY' => $minY + $ySize,
			'minZ' => $minZ,
			'maxZ' => $minZ + $zSize,
		];
	}
}
