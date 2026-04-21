<?php

declare(strict_types=1);

namespace OCA\PreviewProviderSTL\Command;

use OCA\PreviewProviderSTL\Preview\STL;
use OCP\AppConfig;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GeneratePreviews extends Command
{
	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserManager */
	private $userManager;

	/** @var IPreview */
	private $previewManager;

	/** @var IConfig */
	private $config;

	/** @var STL */
	private $stlProvider;

	/** @var LoggerInterface */
	private $logger;

	/** @var OutputInterface */
	private $output;

	/** @var bool */
	private $force = false;

	public function __construct(
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IPreview $previewManager,
		IConfig $config,
		STL $stlProvider,
		LoggerInterface $logger
	) {
		parent::__construct();
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->previewManager = $previewManager;
		$this->config = $config;
		$this->stlProvider = $stlProvider;
		$this->logger = $logger;
	}

	protected function configure(): void
	{
		$this
			->setName('preview:generate-stl')
			->setDescription('Generate STL/OBJ/3MF preview thumbnails')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL,
				'Generate previews for a specific user (omit for all users)'
			)
			->addOption(
				'file-id',
				'f',
				InputOption::VALUE_REQUIRED,
				'Generate preview for a specific file by ID'
			)
			->addOption(
				'path',
				'p',
				InputOption::VALUE_OPTIONAL,
				'Limit to a specific path (e.g., "/Documents")'
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Delete existing previews before generating new ones'
			)
			->addOption(
				'verbose',
				'v',
				InputOption::VALUE_NONE,
				'Show detailed output'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->output = $output;
		$this->force = $input->getOption('force') ?? false;

		// Check if binary is available
		if (!$this->stlProvider->isAvailable()) {
			$output->writeln('<error>STL binary not found. Preview generation not available.</error>');
			return 1;
		}

		$fileId = $input->getOption('file-id');

		if ($fileId !== null) {
			return $this->generateForFileId((int) $fileId);
		}

		$userId = $input->getArgument('user_id');
		$path = $input->getOption('path');

		if ($userId !== null) {
			return $this->generateForUser($userId, $path);
		}

		return $this->generateForAllUsers($path);
	}

	/**
	 * Generate preview for a specific file by ID
	 */
	private function generateForFileId(int $fileId): int
	{
		try {
			$file = $this->getFileById($fileId);
		} catch (NotFoundException $e) {
			$this->output->writeln('<error>File not found with ID: ' . $fileId . '</error>');
			return 1;
		}

		$mimeType = $file->getMimetype();
		if (!$this->isSTLMimeType($mimeType)) {
			$this->output->writeln('<error>File is not an STL/OBJ/3MF file. MIME type: ' . $mimeType . '</error>');
			return 1;
		}

		$this->output->writeln('Generating preview for: ' . $file->getPath());
		$result = $this->generatePreview($file);

		if ($result) {
			$this->output->writeln('<info>Preview generated successfully.</info>');
			return 0;
		}

		$this->output->writeln('<error>Failed to generate preview.</error>');
		return 1;
	}

	/**
	 * Generate previews for all files of a specific user
	 */
	private function generateForUser(string $userId, ?string $path = null): int
	{
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->output->writeln('<error>User not found: ' . $userId . '</error>');
			return 1;
		}

		$this->output->writeln('Generating STL previews for user: ' . $userId);

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Exception $e) {
			$this->output->writeln('<error>Could not access user folder: ' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($path !== null) {
			try {
				$userFolder = $userFolder->get($path);
				if (!$userFolder instanceof \OCP\Files\Folder) {
					$this->output->writeln('<error>Path is not a folder: ' . $path . '</error>');
					return 1;
				}
			} catch (NotFoundException $e) {
				$this->output->writeln('<error>Path not found: ' . $path . '</error>');
				return 1;
			}
		}

		return $this->processFolder($userFolder);
	}

	/**
	 * Generate previews for all users
	 */
	private function generateForAllUsers(?string $path = null): int
	{
		$this->output->writeln('Generating STL previews for all users...');

		$count = 0;
		$this->userManager->callForAllUsers(function ($user) use ($path, &$count) {
			$userId = $user->getUID();
			$this->output->writeln('Processing user: ' . $userId);
			$result = $this->generateForUser($userId, $path);
			if ($result === 0) {
				$count++;
			}
		});

		$this->output->writeln('<info>Processed ' . $count . ' users.</info>');
		return 0;
	}

	/**
	 * Process all files in a folder recursively
	 */
	private function processFolder(\OCP\Files\Folder $folder): int
	{
		$files = $this->findSTLFiles($folder);
		$total = count($files);
		$processed = 0;
		$failed = 0;

		$this->output->writeln('Found ' . $total . ' STL/OBJ/3MF files.');

		foreach ($files as $file) {
			$result = $this->generatePreview($file);
			if ($result) {
				$processed++;
			} else {
				$failed++;
			}

			if ($this->output->isVerbose() && $file instanceof File) {
				$this->output->writeln('  ' . ($result ? '✓' : '✗') . ' ' . $file->getPath());
			}
		}

		$this->output->writeln('Processed: ' . $processed . ' / ' . $total . ' (Failed: ' . $failed . ')');
		return $failed > 0 ? 1 : 0;
	}

	/**
	 * Find all STL/OBJ/3MF files in a folder recursively
	 *
	 * @return \OCP\Files\File[]
	 */
	private function findSTLFiles(\OCP\Files\Folder $folder): array
	{
		$files = [];
		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			if ($node instanceof \OCP\Files\File) {
				$mimeType = $node->getMimetype();
				if ($this->isSTLMimeType($mimeType)) {
					$files[] = $node;
				}
			} elseif ($node instanceof \OCP\Files\Folder) {
				$files = array_merge($files, $this->findSTLFiles($node));
			}
		}

		return $files;
	}

	/**
	 * Check if MIME type is STL/OBJ/3MF
	 */
	private function isSTLMimeType(string $mimeType): bool
	{
		$allowedMimes = ['model/stl', 'model/obj', 'model/3mf'];
		return in_array($mimeType, $allowedMimes, true);
	}

	/**
	 * Generate preview for a single file
	 */
	private function generatePreview(File $file): bool
	{
		try {
			// Delete existing previews if force is enabled
			if ($this->force) {
				$this->deleteExistingPreviews($file);
			}

			// Use Nextcloud's preview system to generate the preview
			// This will use our STL provider registered in Application.php
			$wasCreated = $this->previewManager->generatePreview($file);

			if ($wasCreated) {
				$this->logger->info('Preview generated for file: ' . $file->getFileId());
				return true;
			}

			$this->logger->warning('Preview generation returned false for file: ' . $file->getFileId());
			return false;
		} catch (\Exception $e) {
			$this->logger->error('Failed to generate preview for file: ' . $file->getFileId(), [
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Delete existing preview files for a given file
	 */
	private function deleteExistingPreviews(File $file): void
	{
		try {
			$previewFolder = \OC::$server->getAppDataDir('previewproviderstl');
			$fileId = $file->getFileId();

			// Try to find and delete preview files in various possible locations
			$dataDir = $this->config->getSystemValue('datadirectory', '');
			if (empty($dataDir)) {
				return;
			}

			// Common preview storage patterns
			$patterns = [
				$dataDir . '/appdata_*/preview/' . $fileId . '*',
				$dataDir . '/*/appdata_*/preview/' . $fileId . '*',
			];

			foreach ($patterns as $pattern) {
				$files = glob($pattern);
				foreach ($files as $file) {
					if (is_file($file)) {
						unlink($file);
					}
				}
			}
		} catch (\Exception $e) {
			// Ignore errors - preview will be regenerated anyway
		}
	}

	/**
	 * Get file by ID
	 *
	 * @throws NotFoundException
	 */
	private function getFileById(int $fileId): File
	{
		$allUsers = $this->rootFolder->getUserFolderIds();

		foreach ($allUsers as $userId => $rootFolderId) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$file = $this->findFileById($userFolder, $fileId);
				if ($file !== null) {
					return $file;
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		throw new NotFoundException('File not found: ' . $fileId);
	}

	/**
	 * Recursively find file by ID in folder
	 */
	private function findFileById(\OCP\Files\Folder $folder, int $fileId): ?File
	{
		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			if ($node instanceof File && $node->getId() === $fileId) {
				return $node;
			}
			if ($node instanceof \OCP\Files\Folder) {
				$file = $this->findFileById($node, $fileId);
				if ($file !== null) {
					return $file;
				}
			}
		}

		return null;
	}
}
