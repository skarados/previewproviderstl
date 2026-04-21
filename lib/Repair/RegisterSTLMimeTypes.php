<?php

declare(strict_types=1);

namespace OCA\PreviewProviderSTL\Repair;

use OC\Core\Command\Maintenance\Mimetype\UpdateJS;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class RegisterSTLMimeTypes implements IRepairStep
{
	private const MIMETYPE_MAPPING = [
		'stl' => ['model/stl'],
		'obj' => ['model/obj'],
		'3mf' => ['model/3mf'],
	];

	/** @var IMimeTypeLoader */
	private $mimeTypeLoader;

	/** @var UpdateJS */
	private $updateJS;

	public function __construct(IMimeTypeLoader $mimeTypeLoader, UpdateJS $updateJS)
	{
		$this->mimeTypeLoader = $mimeTypeLoader;
		$this->updateJS = $updateJS;
	}

	public function getName(): string
	{
		return 'Register STL, OBJ, and 3MF MIME types (previewproviderstl)';
	}

	public function run(IOutput $output): void
	{
		$output->info('Registering 3D model MIME types...');

		$this->registerMimeTypes($output);
		$this->updateConfigFiles($output);

		$output->info('MIME types registered successfully.');
		$output->info('NOTE: Rescan existing files with: php occ files:scan --all');
	}

	private function registerMimeTypes(IOutput $output): void
	{
		foreach (self::MIMETYPE_MAPPING as $ext => $mimes) {
			foreach ($mimes as $mime) {
				try {
					$mimeTypeId = $this->mimeTypeLoader->getId($mime);
					$this->mimeTypeLoader->updateFilecache($ext, $mimeTypeId);
					$output->info("  Registered: .$ext => $mime");
				} catch (\Throwable $e) {
					$output->warning("  Failed to register: .$ext => $mime ({$e->getMessage()})");
				}
			}
		}
	}

	private function updateConfigFiles(IOutput $output): void
	{
		$configDir = \OC::$configDir;
		$mappingFile = $configDir . 'mimetypemapping.json';

		$obj = [];
		if (file_exists($mappingFile)) {
			$content = file_get_contents($mappingFile);
			$obj = json_decode($content, true) ?: [];
		}

		foreach (self::MIMETYPE_MAPPING as $ext => $mimes) {
			$obj[$ext] = $mimes;
		}

		file_put_contents($mappingFile, json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$output->info("  Updated: $mappingFile");

		try {
			$this->updateJS->run(new StringInput(''), new ConsoleOutput());
		} catch (\Throwable $e) {
			$output->warning("  Failed to regenerate JS mappings: {$e->getMessage()}");
		}
	}
}
